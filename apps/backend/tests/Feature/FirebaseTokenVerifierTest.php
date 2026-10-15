<?php

namespace Tests\Feature;

use App\Auth\FirebaseTokenVerifier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FirebaseTokenVerifierTest extends TestCase
{
    use RefreshDatabase;

    private static \OpenSSLAsymmetricKey $key;

    private static string $certificate = '';

    private const URL = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $options = ['config' => __DIR__.'/../Fixtures/openssl.cnf', 'private_key_bits' => 2048, 'digest_alg' => 'sha256'];
        self::$key = openssl_pkey_new($options);
        $csr = openssl_csr_new(['commonName' => 'synthetic.example.test'], self::$key, $options);
        $cert = openssl_csr_sign($csr, null, self::$key, 1, $options);
        openssl_x509_export($cert, self::$certificate);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.firebase.project_id' => 'synthetic-project', 'cache.default' => 'array']);
        Cache::flush();
        Http::preventStrayRequests();
    }

    private function claims(array $changes = []): array
    {
        return array_replace(['aud' => 'synthetic-project', 'iss' => 'https://securetoken.google.com/synthetic-project', 'iat' => time() - 10, 'exp' => time() + 3600, 'auth_time' => time() - 30, 'sub' => 'synthetic-parent', 'email' => 'parent@example.test', 'email_verified' => true, 'name' => 'Synthetic Parent'], $changes);
    }

    private function jwt(array $claims = [], array $header = []): string
    {
        $encode = fn ($v) => rtrim(strtr(base64_encode(json_encode($v)), '+/', '-_'), '=');
        $body = $encode(array_replace(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'key-1'], $header)).'.'.$encode($this->claims($claims));
        openssl_sign($body, $signature, self::$key, OPENSSL_ALGO_SHA256);

        return $body.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }

    private function certificates(): void
    {
        Http::fake([self::URL => Http::response(['key-1' => self::$certificate], 200, ['Cache-Control' => 'public, max-age=3600'])]);
    }

    public function test_valid_rsa_signed_verified_parent_is_accepted(): void
    {
        $this->certificates();
        $identity = app(FirebaseTokenVerifier::class)->verify($this->jwt());
        $this->assertSame('synthetic-parent', $identity?->uid);
        $this->assertSame('parent@example.test', $identity?->email);
    }

    #[DataProvider('invalidClaims')]
    public function test_invalid_signed_claims_are_rejected(array $changes): void
    {
        $this->certificates();
        $this->assertNull(app(FirebaseTokenVerifier::class)->verify($this->jwt($changes)));
    }

    public static function invalidClaims(): array
    {
        return [
            'expired' => [['exp' => 1]], 'wrong audience' => [['aud' => 'other-project']], 'wrong issuer' => [['iss' => 'https://securetoken.google.com/other-project']],
            'unverified email' => [['email_verified' => false]], 'string verified flag' => [['email_verified' => 'true']], 'empty uid' => [['sub' => '']], 'oversized uid' => [['sub' => str_repeat('x', 129)]],
            'invalid email' => [['email' => 'not-an-email']], 'missing email' => [['email' => null]], 'string expiry' => [['exp' => '9999999999']], 'future issued' => [['iat' => PHP_INT_MAX]],
            'future auth time' => [['auth_time' => PHP_INT_MAX]], 'future not before' => [['nbf' => PHP_INT_MAX]], 'auth after issue' => [['iat' => 1, 'auth_time' => 2]],
        ];
    }

    public function test_tampering_with_a_signed_payload_is_rejected(): void
    {
        $this->certificates();
        $parts = explode('.', $this->jwt());
        $parts[1] = rtrim(strtr(base64_encode(json_encode($this->claims(['sub' => 'impersonated-parent']))), '+/', '-_'), '=');
        $this->assertNull(app(FirebaseTokenVerifier::class)->verify(implode('.', $parts)));
    }

    public function test_unsupported_algorithm_is_rejected_without_network(): void
    {
        $this->assertNull(app(FirebaseTokenVerifier::class)->verify($this->jwt([], ['alg' => 'none'])));
        Http::assertNothingSent();
    }

    public function test_missing_configuration_and_malformed_tokens_fail_closed(): void
    {
        config(['services.firebase.project_id' => '']);
        $this->assertNull(app(FirebaseTokenVerifier::class)->verify($this->jwt()));
        config(['services.firebase.project_id' => 'synthetic-project']);
        foreach ([null, '', 'not-a-token', 'x.y.z', str_repeat('x', 8193)] as $token) {
            $this->assertNull(app(FirebaseTokenVerifier::class)->verify($token));
        }Http::assertNothingSent();
    }

    public function test_signing_service_failure_is_rejected(): void
    {
        Http::fake([self::URL => Http::response([], 503)]);
        $this->assertNull(app(FirebaseTokenVerifier::class)->verify($this->jwt()));
    }

    public function test_google_cache_lifetime_is_respected(): void
    {
        Http::fake([self::URL => Http::response(['key-1' => self::$certificate], 200, ['Cache-Control' => 'max-age=1'])]);
        $verifier = app(FirebaseTokenVerifier::class);
        $this->assertNotNull($verifier->verify($this->jwt()));
        $this->travel(2)->seconds();
        $this->assertNotNull($verifier->verify($this->jwt()));
        Http::assertSentCount(2);
    }

    public function test_rotated_key_is_refetched_after_refresh_cooldown(): void
    {
        Http::fake([self::URL => Http::sequence()->push(['key-1' => self::$certificate], 200, ['Cache-Control' => 'max-age=3600'])->push(['key-2' => self::$certificate], 200, ['Cache-Control' => 'max-age=3600'])]);
        $verifier = app(FirebaseTokenVerifier::class);
        $this->assertNotNull($verifier->verify($this->jwt()));
        $this->travel(61)->seconds();
        $this->assertNotNull($verifier->verify($this->jwt([], ['kid' => 'key-2'])));
        Http::assertSentCount(2);
    }

    public function test_unknown_key_burst_cannot_force_unbounded_certificate_downloads(): void
    {
        $this->certificates();
        $verifier = app(FirebaseTokenVerifier::class);
        $verifier->verify($this->jwt());
        for ($i = 0; $i < 5; $i++) {
            $this->assertNull($verifier->verify($this->jwt([], ['kid' => 'missing-'.$i])));
        }Http::assertSentCount(1);
    }

    public function test_real_signed_identity_cannot_supply_another_owner_uid(): void
    {
        $this->certificates();
        $other = User::factory()->create(['firebase_uid' => 'other-parent']);
        $response = $this->withToken($this->jwt())->postJson('/api/v1/families', ['name' => 'Signed synthetic family', 'owner_id' => $other->id, 'firebase_uid' => 'other-parent'])->assertCreated();
        $this->assertNotSame($other->id, $response->json('data.owner_id'));
        $this->assertDatabaseHas('users', ['id' => $response->json('data.owner_id'), 'firebase_uid' => 'synthetic-parent']);
    }

    public function test_real_signed_identity_cannot_read_another_family(): void
    {
        $this->certificates();
        $other = User::factory()->create();
        $family = DB::table('families')->insertGetId(['owner_id' => $other->id, 'name' => 'Private synthetic family']);
        $this->withToken($this->jwt())->getJson('/api/v1/families/'.$family.'/children')->assertNotFound();
    }

    public function test_cache_control_age_and_no_store_are_respected(): void
    {
        Http::fake([self::URL => Http::response(['key-1' => self::$certificate], 200, ['Cache-Control' => 'max-age=5', 'Age' => '4'])]);
        $v = app(FirebaseTokenVerifier::class);
        $this->assertNotNull($v->verify($this->jwt()));
        $this->travel(2)->seconds();
        $this->assertNotNull($v->verify($this->jwt()));
        Http::assertSentCount(2);
    }

    public function test_no_store_certificates_are_not_cached(): void
    {
        Http::fake([self::URL => Http::response(['key-1' => self::$certificate], 200, ['Cache-Control' => 'no-store'])]);
        $v = app(FirebaseTokenVerifier::class);
        $this->assertNotNull($v->verify($this->jwt()));
        $this->assertNotNull($v->verify($this->jwt()));
        Http::assertSentCount(2);
    }
}
