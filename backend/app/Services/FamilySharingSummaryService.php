<?php

namespace App\Services;

use App\Models\Family;
use App\Models\User;
use App\Support\FamilyAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class FamilySharingSummaryService
{
    public function read(User $parent, Family $family, string $publicId): array
    {
        return DB::transaction(function () use ($parent, $family, $publicId): array {
            $family = FamilyAccess::findVisible($parent, $family);
            abort_unless(FamilyAccess::canManage($parent, $family), 403);
            $device = $family->devices()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            $now = CarbonImmutable::now('UTC');
            $rows = DB::table('device_sharing_consents')->where('device_id', $device->id)->get()->keyBy('feature');
            $valid = static function (string $feature, string $disclosure) use ($device, $rows, $now): ?object {
                $row = $rows->get($feature);

                return $device->revoked_at === null && is_string($device->credential_hash) && preg_match('/^[a-f0-9]{64}$/D', $device->credential_hash) === 1 && $row && $row->enabled && $row->revoked_at === null
                    && $row->disclosure_version === $disclosure && $row->granted_at !== null
                    && CarbonImmutable::parse($row->granted_at, 'UTC')->lessThanOrEqualTo($now) ? $row : null;
            };
            $usage = $valid('usage', UsageIngestionService::ACTIVATION_DISCLOSURE);
            $location = $valid('location', LocationIngestionService::ACTIVATION_DISCLOSURE);

            return ['device_id' => $device->public_id, 'revoked' => $device->revoked_at !== null, 'collection_available' => false,
                'usage' => ['consent_current' => $usage !== null, 'retention' => '7 UTC calendar days including today',
                    'days' => $usage ? $this->usage($device->id, $usage, $now) : []],
                'location' => ['consent_current' => $location !== null, 'retention' => '24 hours',
                    'latest' => $location ? $this->location($device->id, $location, $now) : null]];
        });
    }

    private function usage(int $id, object $consent, CarbonImmutable $now): array
    {
        $snapshots = DB::table('usage_ingestion_snapshots')->where('device_id', $id)->where('consent_version', $consent->version)
            ->where('day', '>=', $now->startOfDay()->subDays(6)->toDateString())->where('day', '<=', $now->toDateString())
            ->orderByDesc('day')->limit(7)->get();
        $grant = CarbonImmutable::parse($consent->granted_at, 'UTC');
        $days = [];
        foreach ($snapshots as $snapshot) {
            $day = CarbonImmutable::parse($snapshot->day, 'UTC')->startOfDay();
            $start = $grant->greaterThan($day) ? $grant : $day;
            $until = CarbonImmutable::parse($snapshot->recorded_until, 'UTC');
            if ($until->lessThanOrEqualTo($start) || $until->greaterThan($day->addDay()) || $until->greaterThan($now)) {
                continue;
            }
            $apps = DB::table('app_usage_daily')->where('device_id', $id)->where('day', $snapshot->day)
                ->orderBy('package_name')->limit(101)->get(['package_name', 'duration_seconds']);
            $total = 0;
            $output = [];
            foreach ($apps as $app) {
                if (! preg_match('/^[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*)+$/D', $app->package_name)
                    || strlen($app->package_name) > 150 || $app->duration_seconds < 1 || $app->duration_seconds > 86400) {
                    continue 2;
                }
                $total += (int) $app->duration_seconds;
                $output[] = ['package_name' => $app->package_name, 'duration_seconds' => (int) $app->duration_seconds];
            }
            if (count($apps) > 100 || $total > (int) $start->diffInSeconds($until)) {
                continue;
            }
            $days[] = ['day' => $day->toDateString(), 'recorded_until' => $until->format('Y-m-d\TH:i:s\Z'), 'total_seconds' => $total, 'apps' => $output];
        }

        return $days;
    }

    private function location(int $id, object $consent, CarbonImmutable $now): ?array
    {
        $row = DB::table('location_updates')->where('device_id', $id)->where('recorded_at', '>', $now->subHours(24))
            ->where('recorded_at', '>=', $consent->granted_at)->where('recorded_at', '<=', $now)
            ->orderByDesc('recorded_at')->orderByDesc('id')->first(['latitude', 'longitude', 'accuracy_meters', 'recorded_at']);
        if (! $row) {
            return null;
        }
        $lat = (float) $row->latitude;
        $lng = (float) $row->longitude;
        if (! is_finite($lat) || ! is_finite($lng) || abs($lat) > 90 || abs($lng) > 180 || $row->accuracy_meters < 0 || $row->accuracy_meters > 100000) {
            return null;
        }

        return ['latitude' => round($lat, 2), 'longitude' => round($lng, 2), 'accuracy_meters' => max(1000, (int) $row->accuracy_meters),
            'recorded_at' => CarbonImmutable::parse($row->recorded_at, 'UTC')->format('Y-m-d\TH:i:s\Z')];
    }
}
