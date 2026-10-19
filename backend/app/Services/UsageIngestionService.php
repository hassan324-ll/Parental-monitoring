<?php

namespace App\Services;

use App\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Unrouted foundation. Usage preferences are not collection authorization. */
final class UsageIngestionService
{
    public const ACTIVATION_DISCLOSURE = 'usage-collection-v1';

    public function __construct(private DeviceCredentialService $credentials) {}

    public function store(int $id, string $hash, array $payload): array
    {
        return $this->credentials->forCurrentDevice($id, $hash, function (Device $device) use ($payload): array {
            $consent = DB::table('device_sharing_consents')->where('device_id', $device->id)->where('feature', 'usage')->first();
            abort_unless($consent && $consent->enabled && $consent->revoked_at === null && $consent->granted_at !== null
                && $consent->disclosure_version === self::ACTIVATION_DISCLOSURE, 403);
            $this->validatePayload($payload);
            abort_unless((int) $consent->version === $payload['consent_version'], 409, 'Consent changed.');
            $now = CarbonImmutable::now('UTC');
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $payload['day'], 'UTC');
            $end = $day->addDay();
            $until = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $payload['recorded_until'], 'UTC');
            $grant = CarbonImmutable::parse($consent->granted_at, 'UTC');
            $start = $grant->greaterThan($day) ? $grant : $day;
            if ($day->lessThan($now->startOfDay()->subDays(6)) || $day->greaterThan($now->startOfDay())
                || $until->lessThanOrEqualTo($start) || $until->greaterThan($end) || $until->greaterThan($now)) {
                throw ValidationException::withMessages(['recorded_until' => 'Snapshot must be within its retained UTC day and current grant, and not in the future.']);
            }
            $apps = [];
            foreach ($payload['apps'] as $app) {
                if (array_key_exists($app['package_name'], $apps)) {
                    throw ValidationException::withMessages(['apps' => 'Package names must be unique.']);
                }
                $apps[$app['package_name']] = $app['duration_seconds'];
            }
            ksort($apps);
            if (array_sum($apps) > (int) $start->diffInSeconds($until)) {
                throw ValidationException::withMessages(['apps' => 'Combined foreground seconds exceed the authorized observation window.']);
            }
            $snapshot = DB::table('usage_ingestion_snapshots')->where('device_id', $device->id)->where('day', $payload['day'])->first();
            $previous = DB::table('app_usage_daily')->where('device_id', $device->id)->where('day', $payload['day'])->pluck('duration_seconds', 'package_name')->map(fn ($value) => (int) $value)->all();
            ksort($previous);
            abort_unless($snapshot || $previous === [], 409, 'Existing totals require explicit recovery before ingestion.');
            if ($snapshot) {
                abort_unless((int) $snapshot->consent_version === $payload['consent_version'], 409, 'Snapshot belongs to a previous grant.');
                $last = CarbonImmutable::parse($snapshot->recorded_until, 'UTC');
                abort_unless($until->greaterThanOrEqualTo($last), 409, 'Snapshot is stale.');
                if ($until->equalTo($last)) {
                    abort_unless($apps === $previous, 409, 'Snapshot conflicts with the same timestamp.');

                    return ['accepted' => true, 'duplicate' => true];
                }
                abort_unless(array_sum($apps) - array_sum($previous) <= (int) $last->diffInSeconds($until), 409, 'Foreground increment exceeds elapsed time.');
                foreach ($previous as $package => $seconds) {
                    abort_unless(array_key_exists($package, $apps) && $apps[$package] >= $seconds, 409, 'Cumulative totals cannot omit or decrease previous packages.');
                }
            }
            foreach ($apps as $package => $seconds) {
                DB::table('app_usage_daily')->upsert([['device_id' => $device->id, 'day' => $payload['day'], 'package_name' => $package,
                    'duration_seconds' => $seconds, 'created_at' => $now, 'updated_at' => $now]], ['device_id', 'day', 'package_name'], ['duration_seconds', 'updated_at']);
            }
            DB::table('usage_ingestion_snapshots')->upsert([['device_id' => $device->id, 'day' => $payload['day'],
                'consent_version' => $payload['consent_version'], 'recorded_until' => $until, 'created_at' => $now, 'updated_at' => $now]],
                ['device_id', 'day'], ['consent_version', 'recorded_until', 'updated_at']);
            foreach (['app_usage_daily', 'usage_ingestion_snapshots'] as $table) {
                DB::table($table)->where('device_id', $device->id)->where('day', '<', $now->startOfDay()->subDays(6)->toDateString())->delete();
            }

            return ['accepted' => true, 'duplicate' => false];
        });
    }

    private function validatePayload(array $payload): void
    {
        if (array_diff(array_keys($payload), ['consent_version', 'day', 'recorded_until', 'apps'])
            || ! isset($payload['apps']) || ! is_array($payload['apps']) || ! array_is_list($payload['apps']) || count($payload['apps']) > 100) {
            throw ValidationException::withMessages(['payload' => 'Only a minimized snapshot with up to 100 app totals is accepted.']);
        }
        $integer = static function ($attribute, $value, $fail): void {
            if (! is_int($value)) {
                $fail('A JSON integer is required.');
            }
        };
        Validator::make($payload, [
            'consent_version' => ['bail', 'required', $integer, 'integer', 'min:1'],
            'day' => ['bail', 'required', 'string', 'date_format:Y-m-d'],
            'recorded_until' => ['bail', 'required', 'string', 'date_format:Y-m-d\TH:i:s\Z'],
            'apps.*' => ['bail', 'required', 'array:package_name,duration_seconds'],
            'apps.*.package_name' => ['bail', 'required', 'string', 'max:150', 'regex:/^[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*)+$/D'],
            'apps.*.duration_seconds' => ['bail', 'required', $integer, 'integer', 'between:1,86400'],
        ])->validate();
    }
}
