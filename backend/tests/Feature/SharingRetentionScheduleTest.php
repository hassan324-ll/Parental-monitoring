<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SharingRetentionScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->startOfDay()->addHours(12));
    }

    private function event(): CallbackEvent
    {
        $events = collect($this->app->make(Schedule::class)->events())->where('description', 'familyguard-sharing-retention');
        $this->assertCount(1, $events);

        return $events->sole();
    }

    private function fixture(bool $enabled): Device
    {
        $owner = User::factory()->create();
        $family = DB::table('families')->insertGetId(['owner_id' => $owner->id, 'name' => 'Synthetic retention family']);
        $child = DB::table('children')->insertGetId(['family_id' => $family, 'display_name' => 'Synthetic child']);
        $d = Device::forceCreate(['family_id' => $family, 'child_id' => $child, 'public_id' => (string) Str::uuid(), 'name' => 'Synthetic phone', 'credential_hash' => hash('sha256', bin2hex(random_bytes(32)))]);
        foreach (['usage', 'location'] as $feature) {
            DB::table('device_sharing_consents')->insert(['device_id' => $d->id, 'feature' => $feature, 'enabled' => $enabled, 'version' => 3]);
        }
        foreach ([6, 7] as $age) {
            $day = now('UTC')->subDays($age)->toDateString();
            DB::table('app_usage_daily')->insert(['device_id' => $d->id, 'day' => $day, 'package_name' => 'example.synthetic', 'duration_seconds' => 60]);
            DB::table('usage_ingestion_snapshots')->insert(['device_id' => $d->id, 'day' => $day, 'consent_version' => 3, 'recorded_until' => now('UTC')->subDays($age)]);
        }
        foreach ([23, 24] as $age) {
            DB::table('location_updates')->insert(['device_id' => $d->id, 'latitude' => 0, 'longitude' => 0, 'accuracy_meters' => 1000, 'recorded_at' => now('UTC')->subHours($age)]);
        }

        return $d;
    }

    public function test_schedule_is_opt_in_utc_five_minutes_and_non_overlapping(): void
    {
        $event = $this->event();
        $this->assertFalse(config('familyguard.sharing_retention_enabled'));
        $this->assertFalse($event->filtersPass($this->app));
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertSame('UTC', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(60, $event->expiresAt);
        config(['familyguard.sharing_retention_enabled' => 'true']);
        $this->assertFalse($event->filtersPass($this->app));
        config(['familyguard.sharing_retention_enabled' => true]);
        $this->assertTrue($event->filtersPass($this->app));
    }

    public function test_disabled_scheduler_tick_does_not_delete_records(): void
    {
        $this->fixture(false);
        config(['familyguard.sharing_retention_enabled' => false]);
        $this->artisan('schedule:run')->assertExitCode(0);
        $this->assertDatabaseCount('app_usage_daily', 2);
        $this->assertDatabaseCount('usage_ingestion_snapshots', 2);
        $this->assertDatabaseCount('location_updates', 2);
    }

    public function test_enabled_tick_prunes_expired_and_disabled_data_including_watermarks(): void
    {
        $active = $this->fixture(true);
        $disabled = $this->fixture(false);
        config(['familyguard.sharing_retention_enabled' => true]);
        $this->artisan('schedule:run')->assertExitCode(0);
        $this->assertSame(1, DB::table('app_usage_daily')->where('device_id', $active->id)->count());
        $this->assertSame(1, DB::table('usage_ingestion_snapshots')->where('device_id', $active->id)->count());
        $this->assertSame(1, DB::table('location_updates')->where('device_id', $active->id)->count());
        foreach (['app_usage_daily', 'usage_ingestion_snapshots', 'location_updates'] as $table) {
            $this->assertDatabaseMissing($table, ['device_id' => $disabled->id]);
        }
        $this->assertFalse($this->event()->mutex->exists($this->event()));
    }

    public function test_existing_event_lock_skips_tick_then_cleanup_can_run_after_release(): void
    {
        $this->fixture(false);
        config(['familyguard.sharing_retention_enabled' => true]);
        $event = $this->event();
        $this->assertTrue($event->mutex->create($event));
        try {
            $this->artisan('schedule:run')->assertExitCode(0);
            $this->assertDatabaseCount('location_updates', 2);
        } finally {
            $event->mutex->forget($event);
        }
        $this->artisan('schedule:run')->assertExitCode(0);
        $this->assertDatabaseCount('location_updates', 0);
        $this->assertDatabaseCount('usage_ingestion_snapshots', 0);
    }

    public function test_scheduled_pruning_crosses_device_chunk_boundary_without_skipping_rows(): void
    {
        $base = $this->fixture(false);
        for ($i = 0; $i < 100; $i++) {
            $d = Device::forceCreate(['family_id' => $base->family_id, 'child_id' => $base->child_id, 'public_id' => (string) Str::uuid(),
                'name' => 'Synthetic batch phone', 'credential_hash' => hash('sha256', bin2hex(random_bytes(32)))]);
            DB::table('location_updates')->insert(['device_id' => $d->id, 'latitude' => 0, 'longitude' => 0, 'accuracy_meters' => 1000, 'recorded_at' => now('UTC')]);
        }
        $this->assertDatabaseCount('devices', 101);
        config(['familyguard.sharing_retention_enabled' => true]);
        $this->artisan('schedule:run')->assertExitCode(0);
        $this->assertDatabaseCount('location_updates', 0);
        $this->assertDatabaseCount('app_usage_daily', 0);
        $this->assertDatabaseCount('usage_ingestion_snapshots', 0);
        $this->assertDatabaseCount('devices', 101);
    }

    public function test_failure_propagates_and_releases_event_lock_for_retry(): void
    {
        $event = $this->event();
        Artisan::shouldReceive('call')->once()->with('familyguard:prune-sharing')->andReturn(1);
        try {
            $event->run($this->app);
            $this->fail('Failed retention must not report success.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Sharing retention command failed.', $e->getMessage());
        }
        $this->assertFalse($event->mutex->exists($event));
    }
}
