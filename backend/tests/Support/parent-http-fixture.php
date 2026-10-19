<?php

use App\Models\Device;
use App\Models\User;
use App\Services\LocationIngestionService;
use App\Services\UsageIngestionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\HttpAcceptanceEnvironment;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
HttpAcceptanceEnvironment::guard($app);
$action = $argv[1] ?? '';
$path = $argv[2] ?? '';
$root = realpath(__DIR__.'/../../../.local/http-acceptance');
if (! $root || realpath(dirname($path)) !== $root || ! preg_match('/^[a-f0-9-]{36}\.json$/D', basename($path))) {
    throw new LogicException('Parent fixture requires a unique local acceptance file.');
}
if ($action === 'prepare') {
    if (file_exists($path)) {
        throw new LogicException('Refusing to overwrite parent fixture.');
    }
    if (Artisan::call('migrate', ['--force' => true]) !== 0) {
        throw new RuntimeException('Isolated migration failed.');
    }
    $options = ['config' => __DIR__.'/../Fixtures/openssl.cnf', 'private_key_bits' => 2048, 'digest_alg' => 'sha256'];
    $key = openssl_pkey_new($options);
    $csr = openssl_csr_new(['commonName' => 'synthetic-parent.example.test'], $key, $options);
    $certificate = openssl_csr_sign($csr, null, $key, 1, $options);
    openssl_x509_export($certificate, $pem);
    $fixture = DB::transaction(function () use ($key, $pem, $path): array {
        $nonce = bin2hex(random_bytes(16));
        $users = [];
        $tokens = [];
        foreach (['owner', 'viewer', 'other'] as $role) {
            $email = 'fg-parent-'.$nonce.'-'.$role.'@example.invalid';
            $uid = 'fg-parent-'.$nonce.'-'.$role;
            $user = User::forceCreate(['name' => 'Synthetic parent', 'email' => $email, 'firebase_uid' => $uid,
                'email_verified_at' => now(), 'password' => bin2hex(random_bytes(32))]);
            $users[$role] = ['id' => $user->id, 'email' => $email, 'uid' => $uid];
            $claims = ['aud' => 'synthetic-parent-http', 'iss' => 'https://securetoken.google.com/synthetic-parent-http',
                'iat' => time() - 10, 'auth_time' => time() - 30, 'exp' => time() + 600, 'sub' => $uid, 'email' => $email, 'email_verified' => true];
            $encode = fn ($v) => rtrim(strtr(base64_encode(json_encode($v, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
            $sign = function (array $claims) use ($encode, $key): string {
                $body = $encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'synthetic-parent-key']).'.'.$encode($claims);
                openssl_sign($body, $signature, $key, OPENSSL_ALGO_SHA256);

                return $body.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
            };
            $tokens[$role] = $sign($claims);
            if ($role === 'owner') {
                $tokens['unverified'] = $sign(array_replace($claims, ['email_verified' => false]));
                $tokens['expired'] = $sign(array_replace($claims, ['exp' => time() - 1]));
            }
        }
        $family = DB::table('families')->insertGetId(['owner_id' => $users['owner']['id'], 'name' => 'HTTP synthetic parent family']);
        $other = DB::table('families')->insertGetId(['owner_id' => $users['other']['id'], 'name' => 'HTTP synthetic other family']);
        DB::table('family_members')->insert(['family_id' => $family, 'user_id' => $users['viewer']['id'], 'role' => 'viewer']);
        $child = DB::table('children')->insertGetId(['family_id' => $family, 'display_name' => 'Synthetic child']);
        $hash = hash('sha256', bin2hex(random_bytes(32)));
        $device = Device::forceCreate(['family_id' => $family, 'child_id' => $child, 'public_id' => (string) Str::uuid(), 'name' => 'Synthetic phone', 'credential_hash' => $hash]);
        foreach (['usage' => UsageIngestionService::ACTIVATION_DISCLOSURE, 'location' => LocationIngestionService::ACTIVATION_DISCLOSURE] as $feature => $disclosure) {
            DB::table('device_sharing_consents')->insert(['device_id' => $device->id, 'feature' => $feature, 'enabled' => true, 'version' => 3,
                'disclosure_version' => $disclosure, 'granted_at' => now('UTC')->subDays(2)]);
        }
        $day = now('UTC')->subDay()->startOfDay();
        app(UsageIngestionService::class)->store($device->id, $hash, ['consent_version' => 3, 'day' => $day->toDateString(),
            'recorded_until' => $day->addDay()->format('Y-m-d\TH:i:s\Z'), 'apps' => [['package_name' => 'example.synthetic', 'duration_seconds' => 60]]]);
        app(LocationIngestionService::class)->store($device->id, $hash, ['consent_version' => 3, 'recorded_at' => now('UTC')->subMinute()->format('Y-m-d\TH:i:s\Z'),
            'latitude' => 0.004321, 'longitude' => 0.006789, 'accuracy_meters' => 20]);
        $result = ['users' => $users, 'tokens' => $tokens, 'certificate' => $pem, 'family' => $family, 'other_family' => $other, 'device' => $device->public_id];
        if (file_put_contents($path, json_encode($result, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new RuntimeException('Parent fixture write failed; transaction rolled back.');
        }

        return $result;
    });
    echo "Synthetic signed-parent fixture prepared.\n";
} elseif ($action === 'cleanup') {
    $fixture = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    foreach ($fixture['users'] as $user) {
        if (! preg_match('/^fg-parent-[a-f0-9]{32}-(owner|viewer|other)@example\.invalid$/D', $user['email'])
            || ! DB::table('users')->where('id', $user['id'])->where('email', $user['email'])->where('firebase_uid', $user['uid'])->exists()) {
            throw new LogicException('Synthetic parent ownership could not be verified.');
        }
    }
    DB::transaction(function () use ($fixture): void {
        foreach ($fixture['users'] as $user) {
            DB::table('families')->where('owner_id', $user['id'])->delete();
            DB::table('family_members')->where('user_id', $user['id'])->delete();
            DB::table('users')->where('id', $user['id'])->where('email', $user['email'])->delete();
        }
    });
    unlink($path);
    echo "Synthetic parent fixture removed.\n";
} else {
    throw new LogicException('Unknown parent acceptance fixture action.');
}
