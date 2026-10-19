<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use App\Services\DeviceCredentialService;
use App\Services\DeviceSharingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DeviceSharingTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $owner = User::factory()->create();
        $family = DB::table('families')->insertGetId(['owner_id' => $owner->id, 'name' => 'Synthetic sharing family']);
        $child = DB::table('children')->insertGetId(['family_id' => $family, 'display_name' => 'Synthetic child']);
        $token = bin2hex(random_bytes(32));
        $device = Device::forceCreate(['family_id' => $family, 'child_id' => $child, 'public_id' => (string) Str::uuid(), 'name' => 'Synthetic phone', 'credential_hash' => hash('sha256', $token)]);

        return [$device, $token, $owner];
    }

    private function enable(string $token, string $feature): void
    {
        $this->withToken($token)->putJson("/api/v1/companion/sharing/{$feature}", ['enabled' => true, 'disclosure_version' => 'sharing-v1'])->assertOk();
    }

    private function records(int $deviceId, ?string $day = null, ?string $time = null): void
    {
        DB::table('app_usage_daily')->insert(['device_id' => $deviceId, 'day' => $day ?? now('UTC')->toDateString(), 'package_name' => 'example.synthetic', 'duration_seconds' => 60]);
        DB::table('location_updates')->insert(['device_id' => $deviceId, 'latitude' => 0, 'longitude' => 0, 'accuracy_meters' => 1000, 'recorded_at' => $time ?? now('UTC')]);
    }

    public function test_new_device_is_off_by_default_and_parent_identity_cannot_enable_sharing(): void
    {
        [$d,$t,$owner] = $this->fixture();
        $this->getJson('/api/v1/companion/sharing')->assertUnauthorized();
        $this->actingAs($owner, 'firebase-parent')->putJson('/api/v1/companion/sharing/usage', ['enabled' => true, 'disclosure_version' => 'sharing-v1'])->assertUnauthorized();
        $this->withToken($t)->getJson('/api/v1/companion/sharing')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('sharing.usage.enabled', false)->assertJsonPath('sharing.location.enabled', false)->assertJsonPath('sharing.usage.version', 0)->assertJsonPath('collection_available', false)->assertDontSee($t)->assertDontSee($d->credential_hash);
        $this->assertDatabaseCount('device_sharing_consents', 0);
    }

    public function test_enable_requires_boolean_consent_correct_disclosure_and_no_target_identifiers(): void
    {
        [, $t] = $this->fixture();
        foreach ([['enabled' => true], ['enabled' => true, 'disclosure_version' => 'old'], ['enabled' => 'true', 'disclosure_version' => 'sharing-v1'], ['enabled' => 1, 'disclosure_version' => 'sharing-v1']] as $body) {
            $this->withToken($t)->putJson('/api/v1/companion/sharing/usage', $body)->assertUnprocessable();
        }
        foreach (['device_id', 'family_id', 'child_id', 'version'] as $field) {
            $this->withToken($t)->putJson('/api/v1/companion/sharing/usage', ['enabled' => true, 'disclosure_version' => 'sharing-v1', $field => 999])->assertUnprocessable();
        }
        $this->withToken($t)->putJson('/api/v1/companion/sharing/screen', ['enabled' => true, 'disclosure_version' => 'sharing-v1'])->assertNotFound();
        $this->assertDatabaseCount('device_sharing_consents', 0);
    }

    public function test_decision_versions_are_monotonic_and_features_are_independent(): void
    {
        [$d,$t] = $this->fixture();
        $this->enable($t, 'usage');
        $this->withToken($t)->getJson('/api/v1/companion/sharing')->assertJsonPath('sharing.usage.version', 1)->assertJsonPath('sharing.location.enabled', false);
        $this->withToken($t)->putJson('/api/v1/companion/sharing/usage', ['enabled' => false])->assertOk()->assertJsonPath('sharing.usage.version', 2);
        $this->enable($t, 'usage');
        $this->withToken($t)->getJson('/api/v1/companion/sharing')->assertJsonPath('sharing.usage.version', 3);
        $row = DB::table('device_sharing_consents')->where('device_id', $d->id)->first();
        $this->assertNotNull($row->granted_at);
        $this->assertNull($row->revoked_at);
    }

    public function test_disabling_deletes_only_own_feature_data_even_with_another_family_present(): void
    {
        [$a,$ta] = $this->fixture();
        [$b,$tb] = $this->fixture();
        $this->enable($ta, 'usage');
        $this->enable($ta, 'location');
        $this->enable($tb, 'usage');
        $this->records($a->id);
        $this->records($b->id);
        $this->withToken($ta)->putJson('/api/v1/companion/sharing/usage', ['enabled' => false])->assertOk()->assertJsonPath('sharing.usage.enabled', false)->assertJsonPath('sharing.location.enabled', true);
        $this->assertDatabaseMissing('app_usage_daily', ['device_id' => $a->id]);
        $this->assertDatabaseHas('location_updates', ['device_id' => $a->id]);
        $this->assertDatabaseHas('app_usage_daily', ['device_id' => $b->id]);
        $this->withToken($tb)->getJson('/api/v1/companion/sharing')->assertJsonPath('sharing.usage.enabled', true);
    }

    public function test_delete_is_stop_and_erase_not_a_cross_device_target(): void
    {
        [$a,$ta] = $this->fixture();
        [$b] = $this->fixture();
        $this->enable($ta, 'location');
        $this->records($a->id);
        $this->records($b->id);
        $this->withToken($ta)->deleteJson('/api/v1/companion/sharing/location', ['device_id' => $b->id])->assertOk()->assertJsonPath('sharing.location.enabled', false);
        $this->assertDatabaseMissing('location_updates', ['device_id' => $a->id]);
        $this->assertDatabaseHas('location_updates', ['device_id' => $b->id]);
        $this->assertDatabaseHas('app_usage_daily', ['device_id' => $a->id]);
    }

    public function test_locked_service_reauthenticates_stale_rotated_credentials(): void
    {
        [$d,$old] = $this->fixture();
        $next = bin2hex(random_bytes(32));
        app(DeviceCredentialService::class)->rotate($d->id, hash('sha256', $old), hash('sha256', $next));
        try {
            app(DeviceSharingService::class)->decide($d->id, hash('sha256', $old), 'usage', true, 'sharing-v1');
            $this->fail('Stale credential accepted');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
        $this->assertDatabaseCount('device_sharing_consents', 0);
        $this->enable($next, 'usage');
    }

    public function test_parent_revocation_disables_both_and_deletes_data_without_touching_other_family(): void
    {
        [$a,$ta,$owner] = $this->fixture();
        [$b,$tb] = $this->fixture();
        $this->enable($ta, 'usage');
        $this->enable($ta, 'location');
        $this->enable($tb, 'location');
        $this->records($a->id);
        $this->records($b->id);
        $this->actingAs($owner, 'firebase-parent')->deleteJson("/api/v1/families/{$a->family_id}/devices/{$a->public_id}")->assertSuccessful();
        $this->withToken($ta)->putJson('/api/v1/companion/sharing/usage', ['enabled' => true, 'disclosure_version' => 'sharing-v1'])->assertUnauthorized();
        $this->assertSame(0, DB::table('device_sharing_consents')->where('device_id', $a->id)->where('enabled', true)->count());
        $this->assertDatabaseMissing('location_updates', ['device_id' => $a->id]);
        $this->assertDatabaseMissing('app_usage_daily', ['device_id' => $a->id]);
        $this->assertDatabaseHas('location_updates', ['device_id' => $b->id]);
    }

    public function test_device_disconnect_also_revokes_consents_and_purges(): void
    {
        [$d,$t] = $this->fixture();
        $this->enable($t, 'usage');
        $this->records($d->id);
        $this->withToken($t)->postJson('/api/v1/companion/disconnect')->assertOk();
        $this->assertDatabaseMissing('app_usage_daily', ['device_id' => $d->id]);
        $this->assertDatabaseMissing('location_updates', ['device_id' => $d->id]);
        $this->assertDatabaseHas('device_sharing_consents', ['device_id' => $d->id, 'feature' => 'usage', 'enabled' => false, 'version' => 2]);
    }

    public function test_retention_prunes_cutoffs_and_all_data_without_active_consent(): void
    {
        $this->travelTo(now('UTC')->startOfDay()->addHours(12));
        [$a,$ta] = $this->fixture();
        [$b] = $this->fixture();
        $this->enable($ta, 'usage');
        $this->enable($ta, 'location');
        $this->records($a->id, now('UTC')->subDays(6)->toDateString(), now('UTC')->subHours(23)->toDateTimeString());
        $this->records($a->id, now('UTC')->subDays(7)->toDateString(), now('UTC')->subHours(24)->toDateTimeString());
        $this->records($b->id);
        $this->artisan('familyguard:prune-sharing')->assertExitCode(0);
        $this->assertSame(1, DB::table('app_usage_daily')->where('device_id', $a->id)->count());
        $this->assertSame(1, DB::table('location_updates')->where('device_id', $a->id)->count());
        $this->assertDatabaseMissing('app_usage_daily', ['device_id' => $b->id]);
        $this->assertDatabaseMissing('location_updates', ['device_id' => $b->id]);
    }

    public function test_consent_migration_rolls_back_and_reapplies_on_isolated_database(): void
    {
        $migration = require database_path('migrations/2026_10_19_000002_create_device_sharing_consents.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('device_sharing_consents'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('device_sharing_consents'));
    }
}
