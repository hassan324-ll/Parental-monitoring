<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use App\Services\DeviceCredentialService;
use App\Services\DeviceSharingActivationService;
use App\Services\DeviceSharingService;
use App\Services\UsageIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DeviceSharingActivationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $owner = User::factory()->create();
        $family = DB::table('families')->insertGetId(['owner_id' => $owner->id, 'name' => 'Synthetic activation family']);
        $child = DB::table('children')->insertGetId(['family_id' => $family, 'display_name' => 'Synthetic child']);
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $d = Device::forceCreate(['family_id' => $family, 'child_id' => $child, 'public_id' => (string) Str::uuid(), 'name' => 'Synthetic phone', 'credential_hash' => $hash]);
        app(DeviceSharingService::class)->decide($d->id, $hash, 'usage', true, 'sharing-v1');
        app(DeviceSharingService::class)->decide($d->id, $hash, 'location', true, 'sharing-v1');

        return [$d, $hash, $token];
    }

    private function decision(array $changes = []): array
    {
        return array_replace(['acknowledged' => true, 'expected_version' => 1, 'disclosure_version' => 'usage-collection-v1'], $changes);
    }

    private function confirm(Device $d, string $hash, array $changes = []): array
    {
        return app(DeviceSharingActivationService::class)->confirm($d->id, $hash, 'usage', $this->decision($changes));
    }

    private function denied(callable $work, int $status): void
    {
        try {
            $work();
            $this->fail('Expected denial.');
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    private function seedRecords(Device $d): void
    {
        DB::table('app_usage_daily')->insert(['device_id' => $d->id, 'day' => now('UTC')->toDateString(), 'package_name' => 'example.synthetic', 'duration_seconds' => 60]);
        DB::table('usage_ingestion_snapshots')->insert(['device_id' => $d->id, 'day' => now('UTC')->toDateString(), 'consent_version' => 1, 'recorded_until' => now('UTC')]);
        DB::table('location_updates')->insert(['device_id' => $d->id, 'latitude' => 0, 'longitude' => 0, 'accuracy_meters' => 1000, 'recorded_at' => now('UTC')]);
    }

    public function test_default_disabled_and_string_flag_cannot_activate_or_delete(): void
    {
        [$d, $h] = $this->fixture();
        $this->seedRecords($d);
        $this->assertFalse(config('familyguard.device_activation_enabled'));
        foreach ([false, 'true', 1] as $enabled) {
            config(['familyguard.device_activation_enabled' => $enabled]);
            $this->denied(fn () => $this->confirm($d, $h), 409);
        }
        $this->assertDatabaseCount('app_usage_daily', 1);
        $this->assertDatabaseCount('usage_ingestion_snapshots', 1);
        $this->assertDatabaseHas('device_sharing_consents', ['device_id' => $d->id, 'feature' => 'usage', 'version' => 1, 'disclosure_version' => 'sharing-v1']);
    }

    public function test_fresh_grant_advances_version_purges_own_feature_and_preserves_other_family(): void
    {
        config(['familyguard.device_activation_enabled' => true]);
        [$a, $ha] = $this->fixture();
        [$b] = $this->fixture();
        $this->seedRecords($a);
        $this->seedRecords($b);
        $this->travelTo(now('UTC')->startOfSecond()->addMinute());
        $result = $this->confirm($a, $ha);
        $this->assertSame(['consent_recorded' => true, 'feature' => 'usage', 'version' => 2, 'disclosure_version' => 'usage-collection-v1', 'collection_available' => false], $result);
        $this->assertDatabaseMissing('app_usage_daily', ['device_id' => $a->id]);
        $this->assertDatabaseMissing('usage_ingestion_snapshots', ['device_id' => $a->id]);
        $this->assertDatabaseHas('location_updates', ['device_id' => $a->id]);
        $this->assertDatabaseHas('app_usage_daily', ['device_id' => $b->id]);
        $this->assertDatabaseHas('device_sharing_consents', ['device_id' => $a->id, 'feature' => 'usage', 'version' => 2, 'granted_at' => now('UTC')->toDateTimeString(), 'revoked_at' => null]);
        $this->denied(fn () => $this->confirm($a, $ha), 409);
        $this->assertDatabaseHas('device_sharing_consents', ['device_id' => $a->id, 'feature' => 'usage', 'version' => 2]);
    }

    public function test_feature_specific_acknowledgment_types_scope_and_version_are_strict(): void
    {
        config(['familyguard.device_activation_enabled' => true]);
        [$d, $h] = $this->fixture();
        $this->seedRecords($d);
        foreach ([['acknowledged' => false], ['acknowledged' => 'true'], ['acknowledged' => 1], ['expected_version' => '1'], ['expected_version' => 0],
            ['disclosure_version' => 'sharing-v1'], ['disclosure_version' => 'location-collection-v1'], ['device_id' => $d->id], ['family_id' => $d->family_id], ['child_id' => $d->child_id], ['version' => 1]] as $change) {
            try {
                $this->confirm($d, $h, $change);
                $this->fail('Invalid consent accepted.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        foreach (array_keys($this->decision()) as $field) {
            $decision = $this->decision();
            unset($decision[$field]);
            try {
                app(DeviceSharingActivationService::class)->confirm($d->id, $h, 'usage', $decision);
                $this->fail('Missing consent accepted.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->denied(fn () => $this->confirm($d, $h, ['expected_version' => 2]), 409);
        $this->denied(fn () => app(DeviceSharingActivationService::class)->confirm($d->id, $h, 'screen', $this->decision()), 404);
        $this->assertDatabaseCount('app_usage_daily', 1);
    }

    public function test_disabled_missing_and_changed_preferences_require_new_review(): void
    {
        config(['familyguard.device_activation_enabled' => true]);
        [$d, $h] = $this->fixture();
        app(DeviceSharingService::class)->decide($d->id, $h, 'usage', false);
        $this->denied(fn () => $this->confirm($d, $h, ['expected_version' => 2]), 409);
        app(DeviceSharingService::class)->decide($d->id, $h, 'usage', true, 'sharing-v1');
        $this->denied(fn () => $this->confirm($d, $h), 409);
        $this->assertSame(4, $this->confirm($d, $h, ['expected_version' => 3])['version']);
        DB::table('device_sharing_consents')->where('device_id', $d->id)->where('feature', 'usage')->delete();
        $this->denied(fn () => $this->confirm($d, $h), 409);
    }

    public function test_wrong_family_rotated_and_revoked_credentials_cannot_confirm(): void
    {
        config(['familyguard.device_activation_enabled' => true]);
        [$a, $ha] = $this->fixture();
        [, $hb] = $this->fixture();
        $this->denied(fn () => $this->confirm($a, $hb), 401);
        $next = hash('sha256', bin2hex(random_bytes(32)));
        app(DeviceCredentialService::class)->rotate($a->id, $ha, $next);
        $this->denied(fn () => $this->confirm($a, $ha), 401);
        $this->confirm($a, $next);
        app(DeviceCredentialService::class)->revoke($a->id);
        $this->denied(fn () => $this->confirm($a, $next), 401);
    }

    public function test_new_grant_rejects_old_epoch_and_pregrant_data_but_allows_new_minimized_totals(): void
    {
        config(['familyguard.device_activation_enabled' => true]);
        $this->travelTo(now('UTC')->startOfDay()->addHours(12));
        [$d, $h] = $this->fixture();
        $this->confirm($d, $h);
        $this->travel(60)->seconds();
        $payload = ['consent_version' => 2, 'day' => now('UTC')->toDateString(), 'recorded_until' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'apps' => [['package_name' => 'example.synthetic', 'duration_seconds' => 60]]];
        $this->denied(fn () => app(UsageIngestionService::class)->store($d->id, $h, array_replace($payload, ['consent_version' => 1])), 409);
        try {
            app(UsageIngestionService::class)->store($d->id, $h, array_replace($payload, ['apps' => [['package_name' => 'example.synthetic', 'duration_seconds' => 61]]]));
            $this->fail('Pregrant time accepted.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
        app(UsageIngestionService::class)->store($d->id, $h, $payload);
        $this->assertDatabaseHas('app_usage_daily', ['device_id' => $d->id, 'duration_seconds' => 60]);
        app(DeviceSharingService::class)->decide($d->id, $h, 'usage', false);
        $this->assertDatabaseCount('app_usage_daily', 0);
        $this->assertDatabaseCount('usage_ingestion_snapshots', 0);
    }

    public function test_location_confirmation_is_independent_and_no_public_route_exists(): void
    {
        config(['familyguard.device_activation_enabled' => true]);
        [$d, $h, $token] = $this->fixture();
        $this->seedRecords($d);
        $result = app(DeviceSharingActivationService::class)->confirm($d->id, $h, 'location', $this->decision(['disclosure_version' => 'location-collection-v1']));
        $this->assertSame(2, $result['version']);
        $this->assertDatabaseCount('location_updates', 0);
        $this->assertDatabaseCount('app_usage_daily', 1);
        $this->withToken($token)->postJson('/api/v1/companion/sharing/location/activate', $this->decision())->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/companion/sharing')->assertOk()->assertJsonPath('collection_available', false);
    }
}
