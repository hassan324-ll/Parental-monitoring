<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Family;
use App\Models\User;
use App\Services\DeviceCredentialService;
use App\Services\DeviceSharingService;
use App\Services\LocationIngestionService;
use App\Services\UsageIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FamilySharingSummaryTest extends TestCase
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
        $f = Family::forceCreate(['owner_id' => $owner->id, 'name' => 'Synthetic summary family']);
        $child = DB::table('children')->insertGetId(['family_id' => $f->id, 'display_name' => 'Synthetic child']);
        $token = bin2hex(random_bytes(32));
        $d = Device::forceCreate(['family_id' => $f->id, 'child_id' => $child, 'public_id' => (string) Str::uuid(), 'name' => 'Synthetic phone', 'credential_hash' => hash('sha256', $token)]);
        if ($activated) {
            foreach (['usage' => UsageIngestionService::ACTIVATION_DISCLOSURE, 'location' => LocationIngestionService::ACTIVATION_DISCLOSURE] as $feature => $disclosure) {
                DB::table('device_sharing_consents')->insert(['device_id' => $d->id, 'feature' => $feature, 'enabled' => true, 'version' => 3,
                    'disclosure_version' => $disclosure, 'granted_at' => now('UTC')->subDays(8)]);
            }
        }

        return [$f, $d, $owner, $token];
    }

    private function url(Family $f, Device $d): string
    {
        return "/api/v1/families/{$f->id}/devices/{$d->public_id}/sharing-summary";
    }

    private function data(Device $d, string $token): void
    {
        $hash = hash('sha256', $token);
        app(UsageIngestionService::class)->store($d->id, $hash, ['consent_version' => 3, 'day' => now('UTC')->toDateString(),
            'recorded_until' => now('UTC')->subHour()->format('Y-m-d\TH:i:s\Z'), 'apps' => [['package_name' => 'example.synthetic', 'duration_seconds' => 60]]]);
        app(LocationIngestionService::class)->store($d->id, $hash, ['consent_version' => 3, 'recorded_at' => now('UTC')->subMinute()->format('Y-m-d\TH:i:s\Z'),
            'latitude' => 0.004321, 'longitude' => 0.006789, 'accuracy_meters' => 20]);
    }

    public function test_owner_reads_minimized_bounded_saved_data_with_no_credentials_or_ownership_internals(): void
    {
        [$f, $d, $owner, $token] = $this->fixture();
        $this->data($d, $token);
        $response = $this->actingAs($owner, 'firebase-parent')->getJson($this->url($f, $d))->assertOk()
            ->assertJsonPath('device_id', $d->public_id)->assertJsonPath('collection_available', false)
            ->assertJsonPath('usage.consent_current', true)->assertJsonPath('usage.days.0.total_seconds', 60)
            ->assertJsonPath('usage.days.0.apps.0.package_name', 'example.synthetic')->assertJsonPath('location.latest.longitude', 0.01)
            ->assertJsonPath('location.latest.accuracy_meters', 1000)->assertDontSee($token)->assertDontSee($d->credential_hash);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(['device_id', 'revoked', 'collection_available', 'usage', 'location'], array_keys($response->json()));
        $this->assertSame(['package_name', 'duration_seconds'], array_keys($response->json('usage.days.0.apps.0')));
        $this->assertSame(['latitude', 'longitude', 'accuracy_meters', 'recorded_at'], array_keys($response->json('location.latest')));
    }

    public function test_membership_roles_are_rechecked_and_viewers_cannot_read_sensitive_data(): void
    {
        [$f, $d, , $token] = $this->fixture();
        $this->data($d, $token);
        $member = User::factory()->create();
        $f->members()->attach($member, ['role' => 'viewer']);
        $this->actingAs($member, 'firebase-parent')->getJson($this->url($f, $d))->assertForbidden()->assertDontSee('example.synthetic');
        foreach (['parent', 'owner'] as $role) {
            $f->members()->updateExistingPivot($member, ['role' => $role]);
            $this->getJson($this->url($f, $d))->assertOk()->assertJsonPath('usage.days.0.total_seconds', 60);
        }
        $f->members()->detach($member);
        $this->getJson($this->url($f, $d))->assertNotFound();
    }

    public function test_foreign_family_and_foreign_device_are_not_enumerated_and_query_targets_are_ignored(): void
    {
        [$a, $da, $owner, $ta] = $this->fixture();
        [$b, $db, , $tb] = $this->fixture();
        $this->data($da, $ta);
        $this->data($db, $tb);
        $this->actingAs($owner, 'firebase-parent')->getJson($this->url($b, $db))->assertNotFound()->assertDontSee($db->public_id);
        $this->getJson($this->url($a, $db))->assertNotFound();
        $this->getJson($this->url($a, $da).'?family_id='.$b->id.'&device_id='.$db->public_id.'&limit=100000')
            ->assertOk()->assertJsonPath('device_id', $da->public_id)->assertDontSee($db->public_id);
    }

    public function test_requires_parent_auth_and_device_bearer_is_not_parent_identity(): void
    {
        [$f, $d, , $token] = $this->fixture();
        $this->getJson($this->url($f, $d))->assertUnauthorized();
        $this->withToken($token)->getJson($this->url($f, $d))->assertUnauthorized();
    }

    public function test_default_and_preference_only_consent_never_disclose_saved_rows(): void
    {
        [$f, $d, $owner, $token] = $this->fixture(false);
        DB::table('app_usage_daily')->insert(['device_id' => $d->id, 'day' => now('UTC')->toDateString(), 'package_name' => 'example.synthetic', 'duration_seconds' => 60]);
        DB::table('location_updates')->insert(['device_id' => $d->id, 'latitude' => 0, 'longitude' => 0, 'accuracy_meters' => 1000, 'recorded_at' => now('UTC')->subMinute()]);
        $this->actingAs($owner, 'firebase-parent')->getJson($this->url($f, $d))->assertOk()->assertJsonPath('usage.days', [])->assertJsonPath('location.latest', null);
        foreach (['usage', 'location'] as $feature) {
            app(DeviceSharingService::class)->decide($d->id, hash('sha256', $token), $feature, true, 'sharing-v1');
        }
        $this->getJson($this->url($f, $d))->assertOk()->assertJsonPath('usage.consent_current', false)->assertJsonPath('location.consent_current', false)
            ->assertJsonPath('usage.days', [])->assertJsonPath('location.latest', null);
    }

    public function test_retention_filters_reads_without_running_or_mutating_pruning(): void
    {
        [$f, $d, $owner] = $this->fixture();
        foreach ([6, 7] as $days) {
            $day = now('UTC')->subDays($days)->startOfDay();
            DB::table('usage_ingestion_snapshots')->insert(['device_id' => $d->id, 'day' => $day->toDateString(), 'consent_version' => 3, 'recorded_until' => $day->copy()->addDay()]);
            DB::table('app_usage_daily')->insert(['device_id' => $d->id, 'day' => $day->toDateString(), 'package_name' => 'example.synthetic', 'duration_seconds' => 60]);
        }
        foreach ([now('UTC')->subHours(24), now('UTC')->addSecond()] as $time) {
            DB::table('location_updates')->insert(['device_id' => $d->id, 'latitude' => 0, 'longitude' => 0, 'accuracy_meters' => 1000, 'recorded_at' => $time]);
        }
        $this->actingAs($owner, 'firebase-parent')->getJson($this->url($f, $d))->assertOk()->assertJsonCount(1, 'usage.days')
            ->assertJsonPath('usage.days.0.day', now('UTC')->subDays(6)->toDateString())->assertJsonPath('location.latest', null);
        $this->assertDatabaseCount('app_usage_daily', 2);
        $this->assertDatabaseCount('usage_ingestion_snapshots', 2);
        $this->assertDatabaseCount('location_updates', 2);
        DB::table('location_updates')->insert(['device_id' => $d->id, 'latitude' => 0.004321, 'longitude' => 0.006789, 'accuracy_meters' => 20, 'recorded_at' => now('UTC')->subHours(24)->addSecond()]);
        $this->getJson($this->url($f, $d))->assertOk()->assertJsonPath('location.latest.longitude', 0.01)->assertJsonPath('location.latest.accuracy_meters', 1000);
    }

    public function test_current_grant_and_snapshot_version_exclude_stale_data(): void
    {
        [$f, $d, $owner, $token] = $this->fixture();
        $this->data($d, $token);
        DB::table('device_sharing_consents')->where('device_id', $d->id)->update(['granted_at' => now('UTC')->subSeconds(30)]);
        $this->actingAs($owner, 'firebase-parent')->getJson($this->url($f, $d))->assertOk()->assertJsonPath('usage.days', [])->assertJsonPath('location.latest', null);
        DB::table('device_sharing_consents')->where('device_id', $d->id)->update(['granted_at' => now('UTC')->subDays(8), 'version' => 4]);
        $this->getJson($this->url($f, $d))->assertOk()->assertJsonPath('usage.days', []);
        DB::table('device_sharing_consents')->where('device_id', $d->id)->update(['granted_at' => now('UTC')->addSecond()]);
        $this->getJson($this->url($f, $d))->assertOk()->assertJsonPath('usage.consent_current', false)->assertJsonPath('location.latest', null);
    }

    public function test_disabled_revoked_and_missing_credentials_fail_closed_even_with_unpurged_rows(): void
    {
        [$f, $d, $owner, $token] = $this->fixture();
        $this->data($d, $token);
        DB::table('device_sharing_consents')->where('device_id', $d->id)->where('feature', 'usage')->update(['enabled' => false]);
        $this->actingAs($owner, 'firebase-parent')->getJson($this->url($f, $d))->assertOk()->assertJsonPath('usage.days', [])->assertJsonPath('location.consent_current', true);
        $d->revoked_at = now('UTC');
        $d->save();
        $this->getJson($this->url($f, $d))->assertOk()->assertJsonPath('revoked', true)->assertJsonPath('location.latest', null);
        $d->revoked_at = null;
        $d->credential_hash = null;
        $d->save();
        $this->getJson($this->url($f, $d))->assertOk()->assertJsonPath('location.consent_current', false)->assertJsonPath('usage.days', []);
        $this->assertDatabaseCount('location_updates', 1);
    }

    public function test_stop_and_revocation_hide_prior_summaries_and_never_touch_another_family(): void
    {
        [$a, $da, $owner, $ta] = $this->fixture();
        [$b, $db, $other, $tb] = $this->fixture();
        $this->data($da, $ta);
        $this->data($db, $tb);
        app(DeviceSharingService::class)->decide($da->id, hash('sha256', $ta), 'usage', false);
        $this->actingAs($owner, 'firebase-parent')->getJson($this->url($a, $da))->assertOk()->assertJsonPath('usage.days', [])->assertJsonPath('location.consent_current', true);
        app(DeviceCredentialService::class)->revoke($da->id);
        $this->getJson($this->url($a, $da))->assertOk()->assertJsonPath('revoked', true)->assertJsonPath('usage.days', [])->assertJsonPath('location.latest', null);
        $this->actingAs($other, 'firebase-parent')->getJson($this->url($b, $db))->assertOk()->assertJsonPath('usage.days.0.total_seconds', 60);
    }

    public function test_response_accepts_seven_days_and_one_hundred_apps_per_day_with_fixed_bounds(): void
    {
        [$f, $d, $owner] = $this->fixture();
        $rows = [];
        for ($days = 0; $days < 7; $days++) {
            $day = now('UTC')->subDays($days)->startOfDay();
            DB::table('usage_ingestion_snapshots')->insert(['device_id' => $d->id, 'day' => $day->toDateString(), 'consent_version' => 3,
                'recorded_until' => $days === 0 ? now('UTC')->subHour() : $day->copy()->addDay()]);
            for ($i = 0; $i < 100; $i++) {
                $rows[] = ['device_id' => $d->id, 'day' => $day->toDateString(), 'package_name' => 'example.synthetic'.$i, 'duration_seconds' => 1];
            }
        }
        DB::table('app_usage_daily')->insert($rows);
        $result = $this->actingAs($owner, 'firebase-parent')->getJson($this->url($f, $d))->assertOk()->assertJsonCount(7, 'usage.days');
        foreach ($result->json('usage.days') as $day) {
            $this->assertCount(100, $day['apps']);
            $this->assertSame(100, $day['total_seconds']);
        }
        $this->getJson('/api/v1/families/'.$f->id.'/devices/not-a-uuid/sharing-summary')->assertNotFound();
    }

    public function test_invalid_legacy_data_and_untracked_usage_are_not_returned_or_partially_truncated(): void
    {
        [$f, $d, $owner, $token] = $this->fixture();
        $this->data($d, $token);
        DB::table('usage_ingestion_snapshots')->delete();
        $this->actingAs($owner, 'firebase-parent')->getJson($this->url($f, $d))->assertOk()->assertJsonPath('usage.days', []);
        DB::table('usage_ingestion_snapshots')->insert(['device_id' => $d->id, 'day' => now('UTC')->toDateString(), 'consent_version' => 3, 'recorded_until' => now('UTC')->subHour()]);
        for ($i = 0; $i < 100; $i++) {
            DB::table('app_usage_daily')->insert(['device_id' => $d->id, 'day' => now('UTC')->toDateString(), 'package_name' => 'example.synthetic'.$i, 'duration_seconds' => 1]);
        }
        $this->getJson($this->url($f, $d))->assertOk()->assertJsonPath('usage.days', []);
        DB::table('app_usage_daily')->where('package_name', '!=', 'example.synthetic')->delete();
        DB::table('app_usage_daily')->update(['duration_seconds' => 86400]);
        DB::table('location_updates')->update(['latitude' => 91]);
        $this->getJson($this->url($f, $d))->assertOk()->assertJsonPath('usage.days', [])->assertJsonPath('location.latest', null);
        DB::table('app_usage_daily')->update(['duration_seconds' => 60, 'package_name' => 'invalid content']);
        $this->getJson($this->url($f, $d))->assertOk()->assertJsonPath('usage.days', []);
    }
}
