<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StagedPairingTest extends TestCase
{
    use RefreshDatabase;

    private function invitation(): array
    {
        $this->actingAs(User::factory()->create(), 'firebase-parent');
        $family = $this->postJson('/api/v1/families', ['name' => 'Synthetic family'])->assertCreated()->json('data.id');
        $child = $this->postJson("/api/v1/families/{$family}/children", ['display_name' => 'Synthetic child'])->assertCreated()->json('data.id');
        $code = $this->postJson("/api/v1/families/{$family}/children/{$child}/pairings")->assertCreated()->json('code');

        return [$family, $child, $code];
    }

    private function payload(string $code, mixed $hash): array
    {
        return ['code' => $code, 'approved' => true, 'device_name' => 'Synthetic phone', 'credential_hash' => $hash];
    }

    public function test_staged_credential_authorizes_after_approval_without_using_its_response(): void
    {
        [$family, $child, $code] = $this->invitation();
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $this->postJson('/api/v1/device-pairings/approve', $this->payload($code, $hash))
            ->assertCreated()->assertJsonMissingPath('device_token')->assertJsonPath('device.paired', true)
            ->assertHeader('Cache-Control', 'no-store, private')->assertDontSee($token)->assertDontSee($hash);
        // Deliberately use only the pre-existing local credential, not any approval response data.
        $this->withToken($token)->getJson('/api/v1/companion/me')->assertOk();
        $this->withToken($hash)->getJson('/api/v1/companion/me')->assertUnauthorized();
        $this->assertDatabaseHas('devices', ['family_id' => $family, 'child_id' => $child, 'credential_hash' => $hash]);
        $this->assertDatabaseCount('device_credential_history', 1);
        $publicId = DB::table('devices')->value('public_id');
        $this->deleteJson("/api/v1/families/{$family}/devices/{$publicId}")->assertSuccessful();
        $this->withToken($token)->getJson('/api/v1/companion/me')->assertUnauthorized();
    }

    public function test_malformed_hash_and_plaintext_or_target_fields_do_not_consume_invitation(): void
    {
        // Exercise validation independently of the separately tested request rate limit.
        $this->withoutMiddleware(ThrottleRequests::class);
        [, , $code] = $this->invitation();
        foreach ([null, '', false, [], 'short', str_repeat('A', 64), str_repeat('a', 64)."\n", ' '.str_repeat('a', 64)] as $hash) {
            $this->postJson('/api/v1/device-pairings/approve', $this->payload($code, $hash))->assertUnprocessable();
        }
        foreach (['device_token', 'family_id', 'child_id', 'device_id'] as $field) {
            $this->postJson('/api/v1/device-pairings/approve', $this->payload($code, str_repeat('a', 64)) + [$field => 'synthetic'])
                ->assertUnprocessable();
        }
        $this->assertDatabaseCount('devices', 0);
        $this->assertNull(DB::table('device_pairings')->value('consumed_at'));
    }

    public function test_consumed_invitation_rejects_same_and_different_hash_replays(): void
    {
        [, , $code] = $this->invitation();
        $hash = hash('sha256', bin2hex(random_bytes(32)));
        $this->postJson('/api/v1/device-pairings/approve', $this->payload($code, $hash))->assertCreated();
        $this->postJson('/api/v1/device-pairings/approve', $this->payload($code, $hash))->assertNotFound();
        $this->postJson('/api/v1/device-pairings/approve', $this->payload($code, str_repeat('b', 64)))->assertNotFound();
        $this->assertDatabaseCount('devices', 1);
        $this->assertDatabaseCount('device_credential_history', 1);
    }

    public function test_active_rotated_and_revoked_hashes_cannot_be_reused_across_families(): void
    {
        [$family, , $code] = $this->invitation();
        $old = bin2hex(random_bytes(32));
        $next = bin2hex(random_bytes(32));
        $this->postJson('/api/v1/device-pairings/approve', $this->payload($code, hash('sha256', $old)))->assertCreated();
        [, , $activeCode] = $this->invitation();
        $this->postJson('/api/v1/device-pairings/approve', $this->payload($activeCode, hash('sha256', $old)))->assertUnprocessable();
        $this->withToken($old)->postJson('/api/v1/companion/rotate', ['next_credential_hash' => hash('sha256', $next)])->assertOk();
        $this->withToken($next)->postJson('/api/v1/companion/disconnect')->assertOk();
        foreach ([$old, $next] as $used) {
            [, , $freshCode] = $this->invitation();
            $this->postJson('/api/v1/device-pairings/approve', $this->payload($freshCode, hash('sha256', $used)))->assertUnprocessable();
            $this->assertNull(DB::table('device_pairings')->where('token_hash', hash('sha256', $freshCode))->value('consumed_at'));
        }
        $this->assertDatabaseCount('devices', 1);
        $this->assertDatabaseCount('device_credential_history', 2);
    }

    public function test_staged_mode_still_requires_affirmative_consent_and_unexpired_invitation(): void
    {
        [, , $code] = $this->invitation();
        $payload = $this->payload($code, str_repeat('a', 64));
        $payload['approved'] = false;
        $this->postJson('/api/v1/device-pairings/approve', $payload)->assertUnprocessable();
        unset($payload['approved']);
        $this->postJson('/api/v1/device-pairings/approve', $payload)->assertUnprocessable();
        DB::table('device_pairings')->update(['expires_at' => now()]);
        $this->postJson('/api/v1/device-pairings/approve', $this->payload($code, str_repeat('a', 64)))->assertNotFound();
        $this->assertDatabaseCount('devices', 0);
        $this->assertDatabaseCount('device_credential_history', 0);
    }
}
