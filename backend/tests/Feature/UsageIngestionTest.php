<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use App\Services\DeviceCredentialService;
use App\Services\DeviceSharingService;
use App\Services\UsageIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class UsageIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->startOfDay()->addHours(12));
    }

    private function fixture(bool $activated = true): array
    {
        $owner = User::factory()->create();
        $family = DB::table('families')->insertGetId(['owner_id' => $owner->id, 'name' => 'Synthetic usage family']);
        $child = DB::table('children')->insertGetId(['family_id' => $family, 'display_name' => 'Synthetic child']);
        $hash = hash('sha256', bin2hex(random_bytes(32)));
        $d = Device::forceCreate(['family_id' => $family, 'child_id' => $child, 'public_id' => (string) Str::uuid(), 'name' => 'Synthetic phone', 'credential_hash' => $hash]);
        if ($activated) {
            DB::table('device_sharing_consents')->insert(['device_id' => $d->id, 'feature' => 'usage', 'enabled' => true, 'version' => 3,
                'disclosure_version' => UsageIngestionService::ACTIVATION_DISCLOSURE, 'granted_at' => now('UTC')->subDays(8)]);
        }

        return [$d, $hash];
    }

    private function sample(array $changes = []): array
    {
        return array_replace(['consent_version' => 3, 'day' => now('UTC')->toDateString(), 'recorded_until' => now('UTC')->subHour()->format('Y-m-d\TH:i:s\Z'),
            'apps' => [['package_name' => 'example.synthetic', 'duration_seconds' => 60]]], $changes);
    }

    private function store(Device $d, string $hash, array $body): array
    {
        return app(UsageIngestionService::class)->store($d->id, $hash, $body);
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
            $this->fail('Expected validation rejection.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    public function test_cumulative_retry_replaces_totals_without_double_counting(): void
    {
        [$d, $h] = $this->fixture();
        $body = $this->sample();
        $this->assertSame(['accepted' => true, 'duplicate' => false], $this->store($d, $h, $body));
        $this->assertSame(['accepted' => true, 'duplicate' => true], $this->store($d, $h, $body));
        $body['recorded_until'] = now('UTC')->format('Y-m-d\TH:i:s\Z');
        $body['apps'][0]['duration_seconds'] = 120;
        $body['apps'][] = ['package_name' => 'example.other', 'duration_seconds' => 30];
        $this->store($d, $h, $body);
        $this->assertSame(150, (int) DB::table('app_usage_daily')->sum('duration_seconds'));
        $this->assertDatabaseCount('app_usage_daily', 2);
        $this->assertDatabaseCount('usage_ingestion_snapshots', 1);
        $body['apps'] = array_reverse($body['apps']);
        $this->assertTrue($this->store($d, $h, $body)['duplicate']);
    }

    public function test_empty_snapshot_remembers_order_without_collecting_app_names(): void
    {
        [$d, $h] = $this->fixture();
        $body = $this->sample(['apps' => []]);
        $this->store($d, $h, $body);
        $this->assertTrue($this->store($d, $h, $body)['duplicate']);
        $this->assertDatabaseCount('app_usage_daily', 0);
        $this->assertDatabaseCount('usage_ingestion_snapshots', 1);
    }

    public function test_default_and_preference_grants_cannot_authorize_ingestion(): void
    {
        [$d, $h] = $this->fixture(false);
        $this->denied(fn () => $this->store($d, $h, $this->sample()), 403);
        app(DeviceSharingService::class)->decide($d->id, $h, 'usage', true, 'sharing-v1');
        $this->denied(fn () => $this->store($d, $h, $this->sample(['consent_version' => 1])), 403);
        $this->assertDatabaseCount('usage_ingestion_snapshots', 0);
    }

    public function test_rejects_stale_conflicting_decreased_omitted_and_impossible_updates(): void
    {
        [$d, $h] = $this->fixture();
        $body = $this->sample();
        $this->store($d, $h, $body);
        $this->denied(fn () => $this->store($d, $h, $this->sample(['recorded_until' => now('UTC')->subHours(2)->format('Y-m-d\TH:i:s\Z')])), 409);
        $conflict = $body;
        $conflict['apps'][0]['duration_seconds'] = 61;
        $this->denied(fn () => $this->store($d, $h, $conflict), 409);
        $later = $this->sample(['recorded_until' => now('UTC')->format('Y-m-d\TH:i:s\Z'), 'apps' => []]);
        $this->denied(fn () => $this->store($d, $h, $later), 409);
        $later['apps'] = [['package_name' => 'example.synthetic', 'duration_seconds' => 59]];
        $this->denied(fn () => $this->store($d, $h, $later), 409);
        $later['apps'][0]['duration_seconds'] = 62;
        $later['recorded_until'] = now('UTC')->subHour()->addSecond()->format('Y-m-d\TH:i:s\Z');
        $this->denied(fn () => $this->store($d, $h, $later), 409);
        $this->assertSame(60, (int) DB::table('app_usage_daily')->sum('duration_seconds'));
    }

    public function test_strict_minimized_fields_types_and_package_bounds(): void
    {
        [$d, $h] = $this->fixture();
        $app = ['package_name' => 'example.synthetic', 'duration_seconds' => 60];
        foreach ([['consent_version' => '3'], ['consent_version' => true], ['day' => '2026-02-30'], ['day' => 'today'],
            ['recorded_until' => '2026-10-19T10:00:00+00:00'], ['device_id' => $d->id], ['family_id' => $d->family_id], ['child_id' => $d->child_id],
            ['message' => 'synthetic forbidden content'], ['apps' => array_fill(0, 101, $app)], ['apps' => ['x' => $app]], ['apps' => ['bad']],
            ['apps' => [$app + ['title' => 'forbidden']]], ['apps' => [array_replace($app, ['package_name' => 'invalid package'])]],
            ['apps' => [array_replace($app, ['package_name' => str_repeat('a', 151).'.test'])]], ['apps' => [$app, $app]]] as $change) {
            $this->invalid($d, $h, $this->sample($change));
        }
        foreach (['60', 60.0, true, 0, -1, 86401, INF, NAN, null] as $seconds) {
            $this->invalid($d, $h, $this->sample(['apps' => [array_replace($app, ['duration_seconds' => $seconds])]]));
        }
        foreach (array_keys($this->sample()) as $key) {
            $body = $this->sample();
            unset($body[$key]);
            $this->invalid($d, $h, $body);
        }
        $this->assertDatabaseCount('app_usage_daily', 0);
        $this->assertDatabaseCount('usage_ingestion_snapshots', 0);
    }

    public function test_grant_day_retention_version_and_combined_time_fences(): void
    {
        [$d, $h] = $this->fixture();
        $this->denied(fn () => $this->store($d, $h, $this->sample(['consent_version' => 2])), 409);
        foreach ([['day' => now('UTC')->subDays(7)->toDateString()], ['day' => now('UTC')->addDay()->toDateString()],
            ['recorded_until' => now('UTC')->addSecond()->format('Y-m-d\TH:i:s\Z')],
            ['recorded_until' => now('UTC')->startOfDay()->format('Y-m-d\TH:i:s\Z')],
            ['apps' => [['package_name' => 'example.one', 'duration_seconds' => 25000], ['package_name' => 'example.two', 'duration_seconds' => 25000]]]] as $change) {
            $this->invalid($d, $h, $this->sample($change));
        }
        DB::table('device_sharing_consents')->where('device_id', $d->id)->update(['granted_at' => now('UTC')->subSeconds(30)]);
        $this->invalid($d, $h, $this->sample());
        $this->invalid($d, $h, $this->sample(['recorded_until' => now('UTC')->format('Y-m-d\TH:i:s\Z')]));
        $this->store($d, $h, $this->sample(['recorded_until' => now('UTC')->format('Y-m-d\TH:i:s\Z'), 'apps' => [['package_name' => 'example.synthetic', 'duration_seconds' => 30]]]));
        $this->assertDatabaseCount('app_usage_daily', 1);
    }

    public function test_retained_day_boundary_and_snapshot_pruning_match_totals(): void
    {
        [$d, $h] = $this->fixture();
        $oldDay = now('UTC')->subDays(7)->toDateString();
        DB::table('usage_ingestion_snapshots')->insert(['device_id' => $d->id, 'day' => $oldDay, 'consent_version' => 3, 'recorded_until' => now('UTC')->subDays(7)]);
        DB::table('app_usage_daily')->insert(['device_id' => $d->id, 'day' => $oldDay, 'package_name' => 'example.synthetic', 'duration_seconds' => 60]);
        $day = now('UTC')->subDays(6)->startOfDay();
        $body = $this->sample(['day' => $day->toDateString(), 'recorded_until' => $day->copy()->addDay()->format('Y-m-d\TH:i:s\Z')]);
        $this->store($d, $h, $body);
        $this->assertDatabaseMissing('usage_ingestion_snapshots', ['day' => $oldDay]);
        $this->assertDatabaseMissing('app_usage_daily', ['day' => $oldDay]);
        $this->travel(1)->days();
        $this->artisan('familyguard:prune-sharing')->assertExitCode(0);
        $this->assertDatabaseCount('app_usage_daily', 0);
        $this->assertDatabaseCount('usage_ingestion_snapshots', 0);
    }

    public function test_rotated_cross_family_and_old_grant_cannot_mutate_snapshot(): void
    {
        [$a, $ha] = $this->fixture();
        [$b, $hb] = $this->fixture();
        $this->denied(fn () => $this->store($a, $hb, $this->sample()), 401);
        $next = hash('sha256', bin2hex(random_bytes(32)));
        app(DeviceCredentialService::class)->rotate($a->id, $ha, $next);
        $this->denied(fn () => $this->store($a, $ha, $this->sample()), 401);
        $this->store($a, $next, $this->sample());
        DB::table('device_sharing_consents')->where('device_id', $a->id)->update(['version' => 4]);
        $this->denied(fn () => $this->store($a, $next, $this->sample(['consent_version' => 4])), 409);
        $this->assertDatabaseMissing('app_usage_daily', ['device_id' => $b->id]);
    }

    public function test_stop_revoke_and_device_cascade_delete_totals_and_retry_metadata(): void
    {
        [$a, $ha] = $this->fixture();
        [$b, $hb] = $this->fixture();
        $this->store($a, $ha, $this->sample());
        $this->store($b, $hb, $this->sample());
        app(DeviceSharingService::class)->decide($a->id, $ha, 'usage', false);
        $this->denied(fn () => $this->store($a, $ha, $this->sample()), 403);
        $this->assertDatabaseMissing('usage_ingestion_snapshots', ['device_id' => $a->id]);
        $this->assertDatabaseHas('usage_ingestion_snapshots', ['device_id' => $b->id]);
        app(DeviceCredentialService::class)->revoke($b->id);
        $this->denied(fn () => $this->store($b, $hb, $this->sample()), 401);
        $this->assertDatabaseCount('app_usage_daily', 0);
        $this->assertDatabaseCount('usage_ingestion_snapshots', 0);
        [$c, $hc] = $this->fixture();
        $this->store($c, $hc, $this->sample());
        $c->delete();
        $this->assertDatabaseCount('app_usage_daily', 0);
        $this->assertDatabaseCount('usage_ingestion_snapshots', 0);
    }

    public function test_legacy_totals_without_snapshot_require_recovery_and_routes_stay_unavailable(): void
    {
        [$d, $h] = $this->fixture();
        DB::table('app_usage_daily')->insert(['device_id' => $d->id, 'day' => now('UTC')->toDateString(), 'package_name' => 'example.synthetic', 'duration_seconds' => 60]);
        $this->denied(fn () => $this->store($d, $h, $this->sample()), 409);
        $token = bin2hex(random_bytes(32));
        $d->credential_hash = hash('sha256', $token);
        $d->save();
        $this->withToken($token)->postJson('/api/v1/companion/usage', $this->sample())->assertNotFound();
        $this->withToken($token)->putJson('/api/v1/companion/sharing/usage', ['enabled' => true, 'disclosure_version' => UsageIngestionService::ACTIVATION_DISCLOSURE])->assertUnprocessable();
        $this->withToken($token)->getJson('/api/v1/companion/sharing')->assertOk()->assertJsonPath('collection_available', false);
    }

    public function test_maximum_package_count_accepts_only_positive_foreground_totals(): void
    {
        [$d, $h] = $this->fixture();
        $apps = [];
        for ($i = 0; $i < 100; $i++) {
            $apps[] = ['package_name' => 'example.synthetic'.$i, 'duration_seconds' => 1];
        }
        $this->store($d, $h, $this->sample(['apps' => $apps]));
        $this->assertDatabaseCount('app_usage_daily', 100);
        $this->assertSame(100, (int) DB::table('app_usage_daily')->sum('duration_seconds'));
    }

    public function test_snapshot_migration_rolls_back_and_reapplies(): void
    {
        $migration = require database_path('migrations/2026_10_19_000003_create_usage_ingestion_snapshots.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('usage_ingestion_snapshots'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('usage_ingestion_snapshots'));
    }
}
