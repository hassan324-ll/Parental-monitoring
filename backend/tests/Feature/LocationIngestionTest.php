<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use App\Services\DeviceCredentialService;
use App\Services\DeviceSharingService;
use App\Services\LocationIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LocationIngestionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(bool $activated = true): array
    {
        $owner = User::factory()->create();
        $family = DB::table('families')->insertGetId(['owner_id' => $owner->id, 'name' => 'Synthetic ingestion family']);
        $child = DB::table('children')->insertGetId(['family_id' => $family, 'display_name' => 'Synthetic child']);
        $hash = hash('sha256', bin2hex(random_bytes(32)));
        $d = Device::forceCreate(['family_id' => $family, 'child_id' => $child, 'public_id' => (string) Str::uuid(), 'name' => 'Synthetic phone', 'credential_hash' => $hash]);
        if ($activated) {
            // Synthetic future activation only: no runtime route grants this disclosure.
            DB::table('device_sharing_consents')->insert(['device_id' => $d->id, 'feature' => 'location', 'enabled' => true, 'version' => 3,
                'disclosure_version' => LocationIngestionService::ACTIVATION_DISCLOSURE, 'granted_at' => now('UTC')->subHours(25)]);
        }

        return [$d, $hash];
    }

    private function sample(array $changes = []): array
    {
        return array_replace(['consent_version' => 3, 'recorded_at' => now('UTC')->subMinute()->format('Y-m-d\TH:i:s\Z'),
            'latitude' => 0.004321, 'longitude' => 0.006789, 'accuracy_meters' => 20], $changes);
    }

    private function store(Device $d, string $hash, array $body): array
    {
        return app(LocationIngestionService::class)->store($d->id, $hash, $body);
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

    private function invalid(Device $d, string $hash, array $body): void
    {
        try {
            $this->store($d, $hash, $body);
            $this->fail('Expected invalid sample rejection.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    public function test_minimizes_coordinates_accuracy_and_response(): void
    {
        [$d, $h] = $this->fixture();
        $this->assertSame(['accepted' => true, 'duplicate' => false], $this->store($d, $h, $this->sample()));
        $row = DB::table('location_updates')->sole();
        $this->assertSame(0.0, (float) $row->latitude);
        $this->assertSame(0.01, (float) $row->longitude);
        $this->assertSame(1000, (int) $row->accuracy_meters);
    }

    public function test_defaults_and_saved_preferences_never_authorize_collection(): void
    {
        [$d, $h] = $this->fixture(false);
        $this->denied(fn () => $this->store($d, $h, $this->sample()), 403);
        app(DeviceSharingService::class)->decide($d->id, $h, 'location', true, 'sharing-v1');
        $this->denied(fn () => $this->store($d, $h, $this->sample(['consent_version' => 1])), 403);
        $this->assertDatabaseCount('location_updates', 0);
    }

    public function test_deduplicates_after_minimization_and_rejects_conflicting_replay(): void
    {
        [$d, $h] = $this->fixture();
        $sample = $this->sample();
        $this->store($d, $h, $sample);
        $this->assertSame(['accepted' => true, 'duplicate' => true], $this->store($d, $h, $sample));
        $sample['latitude'] = 0.001;
        $this->assertTrue($this->store($d, $h, $sample)['duplicate']);
        $sample['latitude'] = 1;
        $this->denied(fn () => $this->store($d, $h, $sample), 409);
        $this->assertDatabaseCount('location_updates', 1);
    }

    public function test_strict_fields_numbers_bounds_dates_and_ownership_are_rejected_atomically(): void
    {
        [$d, $h] = $this->fixture();
        foreach ([['consent_version' => '3'], ['consent_version' => 3.0], ['consent_version' => true], ['latitude' => '0'],
            ['latitude' => 91], ['longitude' => -181], ['latitude' => INF], ['longitude' => NAN], ['accuracy_meters' => 1.5],
            ['accuracy_meters' => -1], ['accuracy_meters' => 100001], ['accuracy_meters' => '20'], ['recorded_at' => '2026-02-30T10:00:00Z'],
            ['recorded_at' => '2026-10-19T10:00:00+00:00'], ['device_id' => $d->id], ['family_id' => $d->family_id],
            ['child_id' => $d->child_id], ['message' => 'synthetic forbidden content'], ['samples' => []]] as $change) {
            $this->invalid($d, $h, $this->sample($change));
        }
        foreach (array_keys($this->sample()) as $key) {
            $body = $this->sample();
            unset($body[$key]);
            $this->invalid($d, $h, $body);
        }
        $this->assertDatabaseCount('location_updates', 0);
    }

    public function test_version_and_grant_and_rolling_retention_fences(): void
    {
        $this->travelTo(now('UTC')->startOfSecond());
        [$d, $h] = $this->fixture();
        $this->denied(fn () => $this->store($d, $h, $this->sample(['consent_version' => 2])), 409);
        foreach ([now('UTC')->subHours(24), now('UTC')->subHours(25), now('UTC')->addSecond()] as $time) {
            $this->invalid($d, $h, $this->sample(['recorded_at' => $time->format('Y-m-d\TH:i:s\Z')]));
        }
        DB::table('device_sharing_consents')->where('device_id', $d->id)->update(['granted_at' => now('UTC')->subSeconds(30)]);
        $this->invalid($d, $h, $this->sample());
        $this->assertDatabaseCount('location_updates', 0);
        $this->store($d, $h, $this->sample(['recorded_at' => now('UTC')->subSeconds(30)->format('Y-m-d\TH:i:s\Z')]));
        $this->assertDatabaseCount('location_updates', 1);
    }

    public function test_fifteen_minute_in_order_limit_and_expired_record_cleanup(): void
    {
        $this->travelTo(now('UTC')->startOfSecond());
        [$d, $h] = $this->fixture();
        DB::table('location_updates')->insert(['device_id' => $d->id, 'latitude' => 0, 'longitude' => 0, 'accuracy_meters' => 1000, 'recorded_at' => now('UTC')->subHours(24)]);
        $start = now('UTC')->subHour();
        $this->store($d, $h, $this->sample(['recorded_at' => $start->format('Y-m-d\TH:i:s\Z')]));
        $this->assertDatabaseCount('location_updates', 1);
        foreach ([$start->copy()->subSecond(), $start->copy()->addSeconds(899)] as $time) {
            $this->invalid($d, $h, $this->sample(['recorded_at' => $time->format('Y-m-d\TH:i:s\Z')]));
        }
        $this->store($d, $h, $this->sample(['recorded_at' => $start->copy()->addMinutes(15)->format('Y-m-d\TH:i:s\Z')]));
        $this->assertDatabaseCount('location_updates', 2);
    }

    public function test_rotation_reauthenticates_and_cross_family_hash_cannot_write(): void
    {
        [$a, $ha] = $this->fixture();
        [$b, $hb] = $this->fixture();
        $this->denied(fn () => $this->store($a, $hb, $this->sample()), 401);
        $next = hash('sha256', bin2hex(random_bytes(32)));
        app(DeviceCredentialService::class)->rotate($a->id, $ha, $next);
        $this->denied(fn () => $this->store($a, $ha, $this->sample()), 401);
        $this->store($a, $next, $this->sample());
        $this->assertDatabaseMissing('location_updates', ['device_id' => $b->id]);
    }

    public function test_stop_delete_and_revoke_prevent_resurrection_and_preserve_other_family(): void
    {
        [$a, $ha] = $this->fixture();
        [$b, $hb] = $this->fixture();
        $this->store($a, $ha, $this->sample());
        $this->store($b, $hb, $this->sample());
        app(DeviceSharingService::class)->decide($a->id, $ha, 'location', false);
        $this->denied(fn () => $this->store($a, $ha, $this->sample()), 403);
        $this->assertDatabaseMissing('location_updates', ['device_id' => $a->id]);
        $this->assertDatabaseHas('location_updates', ['device_id' => $b->id]);
        app(DeviceCredentialService::class)->revoke($b->id);
        $this->denied(fn () => $this->store($b, $hb, $this->sample()), 401);
        $this->assertDatabaseCount('location_updates', 0);
    }

    public function test_ingestion_has_no_public_route_or_activation_route(): void
    {
        [$d, $h] = $this->fixture(false);
        $token = bin2hex(random_bytes(32));
        $d->credential_hash = hash('sha256', $token);
        $d->save();
        $this->withToken($token)->postJson('/api/v1/companion/locations', $this->sample())->assertNotFound();
        $this->withToken($token)->putJson('/api/v1/companion/sharing/location', ['enabled' => true, 'disclosure_version' => LocationIngestionService::ACTIVATION_DISCLOSURE])->assertUnprocessable();
        $this->withToken($token)->getJson('/api/v1/companion/sharing')->assertOk()->assertJsonPath('collection_available', false);
    }
}
