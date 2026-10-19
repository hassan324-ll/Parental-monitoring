<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use App\Services\DeviceCredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DeviceCredentialRotationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $parent = User::factory()->create();
        $family = DB::table('families')->insertGetId(['owner_id' => $parent->id, 'name' => 'Synthetic rotation family']);
        $child = DB::table('children')->insertGetId(['family_id' => $family, 'display_name' => 'Synthetic child']);
        $token = bin2hex(random_bytes(32));
        $device = Device::forceCreate([
            'family_id' => $family, 'child_id' => $child, 'public_id' => (string) Str::uuid(),
            'name' => 'Synthetic phone', 'credential_hash' => hash('sha256', $token),
        ]);

        return [$parent, $family, $device, $token];
    }

    public function test_rotation_requires_a_device_credential(): void
    {
        $this->postJson('/api/v1/companion/rotate', ['next_credential_hash' => str_repeat('a', 64)])->assertUnauthorized();
    }

    public function test_rotation_invalidates_old_credential_without_returning_any_secret(): void
    {
        [, , $device, $old] = $this->fixture();
        $next = bin2hex(random_bytes(32));
        $this->withToken($old)->postJson('/api/v1/companion/rotate', ['next_credential_hash' => hash('sha256', $next)])
            ->assertOk()->assertJsonPath('rotated', true)->assertJsonPath('device.id', $device->public_id)
            ->assertHeader('Cache-Control', 'no-store, private')->assertDontSee($old)->assertDontSee($next)
            ->assertDontSee(hash('sha256', $next));
        $this->withToken($old)->getJson('/api/v1/companion/me')->assertUnauthorized();
        $this->withToken($old)->postJson('/api/v1/companion/rotate', ['next_credential_hash' => hash('sha256', $next)])->assertUnauthorized();
        $this->withToken($next)->getJson('/api/v1/companion/me')->assertOk();
        $this->assertDatabaseCount('device_credential_history', 2);
    }

    public function test_invalid_hashes_and_target_identifiers_are_rejected(): void
    {
        [, , $device, $old] = $this->fixture();
        foreach (['', 'short', str_repeat('A', 64), str_repeat('a', 63), str_repeat('a', 64)."\n"] as $invalid) {
            $this->withToken($old)->postJson('/api/v1/companion/rotate', ['next_credential_hash' => $invalid])->assertUnprocessable();
        }
        foreach (['device_id', 'family_id', 'child_id'] as $field) {
            $this->withToken($old)->postJson('/api/v1/companion/rotate', [
                'next_credential_hash' => str_repeat('a', 64), $field => 999,
            ])->assertUnprocessable();
        }
        $this->assertSame(hash('sha256', $old), $device->fresh()->credential_hash);
    }

    public function test_current_and_historical_credentials_cannot_be_reused(): void
    {
        [, , , $old] = $this->fixture();
        $next = bin2hex(random_bytes(32));
        $this->withToken($old)->postJson('/api/v1/companion/rotate', ['next_credential_hash' => hash('sha256', $old)])->assertUnprocessable();
        $this->withToken($old)->postJson('/api/v1/companion/rotate', ['next_credential_hash' => hash('sha256', $next)])->assertOk();
        $this->withToken($next)->postJson('/api/v1/companion/rotate', ['next_credential_hash' => hash('sha256', $old)])->assertUnprocessable();
        $this->withToken($next)->getJson('/api/v1/companion/me')->assertOk();
    }

    public function test_rotation_cannot_take_another_devices_current_or_historical_hash(): void
    {
        [, , , $a] = $this->fixture();
        [, , $deviceB, $b] = $this->fixture();
        $nextA = bin2hex(random_bytes(32));
        $this->withToken($a)->postJson('/api/v1/companion/rotate', ['next_credential_hash' => hash('sha256', $b)])->assertUnprocessable();
        $this->withToken($a)->postJson('/api/v1/companion/rotate', ['next_credential_hash' => hash('sha256', $nextA)])->assertOk();
        $this->withToken($b)->postJson('/api/v1/companion/rotate', ['next_credential_hash' => hash('sha256', $a)])->assertUnprocessable();
        $this->assertSame(hash('sha256', $b), $deviceB->fresh()->credential_hash);
    }

    public function test_parent_revocation_prevents_rotation(): void
    {
        [$parent, $family, $device, $old] = $this->fixture();
        $this->actingAs($parent, 'firebase-parent')->deleteJson("/api/v1/families/{$family}/devices/{$device->public_id}")->assertOk();
        $this->withToken($old)->postJson('/api/v1/companion/rotate', ['next_credential_hash' => str_repeat('a', 64)])->assertUnauthorized();
        $this->assertNull($device->fresh()->credential_hash);
    }

    public function test_old_credential_cannot_disconnect_the_rotated_session(): void
    {
        [, , , $old] = $this->fixture();
        $next = bin2hex(random_bytes(32));
        $this->withToken($old)->postJson('/api/v1/companion/rotate', ['next_credential_hash' => hash('sha256', $next)])->assertOk();
        $this->withToken($old)->postJson('/api/v1/companion/disconnect')->assertUnauthorized();
        $this->withToken($next)->getJson('/api/v1/companion/me')->assertOk();
    }

    public function test_service_rechecks_credential_after_a_stale_middleware_snapshot(): void
    {
        [, , $snapshot, $old] = $this->fixture();
        $next = bin2hex(random_bytes(32));
        $service = app(DeviceCredentialService::class);
        $service->rotate($snapshot->id, hash('sha256', $old), hash('sha256', $next));
        foreach (['status', 'disconnect', 'rotate'] as $operation) {
            try {
                $arguments = [$snapshot->id, hash('sha256', $old)];
                if ($operation === 'rotate') {
                    $arguments[] = str_repeat('f', 64);
                }
                $service->{$operation}(...$arguments);
                $this->fail('Stale device authorization must be rejected under the row lock.');
            } catch (HttpException $exception) {
                $this->assertSame(401, $exception->getStatusCode());
            }
        }
        $this->assertSame(hash('sha256', $next), $snapshot->fresh()->credential_hash);
        $this->assertNull($snapshot->fresh()->revoked_at);
    }

    public function test_self_disconnect_revokes_the_rotated_credential(): void
    {
        [, , $device, $old] = $this->fixture();
        $next = bin2hex(random_bytes(32));
        $this->withToken($old)->postJson('/api/v1/companion/rotate', ['next_credential_hash' => hash('sha256', $next)])->assertOk();
        $this->withToken($next)->postJson('/api/v1/companion/disconnect')->assertOk();
        $this->withToken($next)->getJson('/api/v1/companion/me')->assertUnauthorized();
        $this->assertNull($device->fresh()->credential_hash);
    }

    public function test_family_isolation_and_viewer_denial_survive_rotation(): void
    {
        [$parentA] = $this->fixture();
        [, $familyB, $deviceB, $oldB] = $this->fixture();
        $next = bin2hex(random_bytes(32));
        $this->withToken($oldB)->postJson('/api/v1/companion/rotate', ['next_credential_hash' => hash('sha256', $next)])->assertOk();
        $this->actingAs($parentA, 'firebase-parent')->deleteJson("/api/v1/families/{$familyB}/devices/{$deviceB->public_id}")->assertNotFound();
        DB::table('family_members')->insert(['family_id' => $familyB, 'user_id' => $parentA->id, 'role' => 'viewer']);
        $this->deleteJson("/api/v1/families/{$familyB}/devices/{$deviceB->public_id}")->assertForbidden();
        $this->withToken($next)->getJson('/api/v1/companion/me')->assertOk();
    }

    public function test_history_is_not_serialized_and_cascades_with_device_deletion(): void
    {
        [$parent, $family, $device, $old] = $this->fixture();
        $next = bin2hex(random_bytes(32));
        $this->withToken($old)->postJson('/api/v1/companion/rotate', ['next_credential_hash' => hash('sha256', $next)])->assertOk();
        $this->actingAs($parent, 'firebase-parent')->getJson("/api/v1/families/{$family}/devices")
            ->assertOk()->assertDontSee('credential_hash')->assertDontSee($next)->assertDontSee(hash('sha256', $old));
        $device->delete();
        $this->assertDatabaseCount('device_credential_history', 0);
    }

    public function test_migration_backfills_existing_credentials_without_modifying_them(): void
    {
        [, , $device, $old] = $this->fixture();
        $migration = require database_path('migrations/2026_10_19_000001_create_device_credential_history.php');
        $migration->down();
        $this->assertSame(hash('sha256', $old), $device->fresh()->credential_hash);
        $migration->up();
        $this->assertDatabaseHas('device_credential_history', ['device_id' => $device->id, 'credential_hash' => hash('sha256', $old)]);
    }
}
