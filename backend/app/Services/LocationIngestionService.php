<?php

namespace App\Services;

use App\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Unrouted foundation only. Existing sharing-v1 preferences never authorize ingestion. */
final class LocationIngestionService
{
    public const ACTIVATION_DISCLOSURE = 'location-collection-v1';

    public function __construct(private DeviceCredentialService $credentials) {}

    public function store(int $id, string $hash, array $payload): array
    {
        return $this->credentials->forCurrentDevice($id, $hash, function (Device $device) use ($payload): array {
            $consent = DB::table('device_sharing_consents')->where('device_id', $device->id)->where('feature', 'location')->first();
            abort_unless($consent && $consent->enabled && $consent->revoked_at === null
                && $consent->granted_at !== null && $consent->disclosure_version === self::ACTIVATION_DISCLOSURE, 403);
            $this->validatePayload($payload);
            abort_unless((int) $consent->version === $payload['consent_version'], 409, 'Consent changed.');
            $time = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $payload['recorded_at'], 'UTC');
            $now = CarbonImmutable::now('UTC');
            $grant = CarbonImmutable::parse($consent->granted_at, 'UTC');
            if ($time->lessThan($grant) || $time->lessThanOrEqualTo($now->subHours(24)) || $time->greaterThan($now)) {
                throw ValidationException::withMessages(['recorded_at' => 'Sample must be after the current grant, within retention and not in the future.']);
            }
            // Keep only coarse coordinates. Never persist the original payload or return coordinates.
            $latitude = round((float) $payload['latitude'], 2);
            $longitude = round((float) $payload['longitude'], 2);
            $accuracy = max(1000, $payload['accuracy_meters']);
            $existing = DB::table('location_updates')->where('device_id', $device->id)->where('recorded_at', $time)->first();
            if ($existing) {
                abort_unless((float) $existing->latitude === $latitude && (float) $existing->longitude === $longitude
                    && (int) $existing->accuracy_meters === $accuracy, 409, 'Sample conflicts with an existing timestamp.');

                return ['accepted' => true, 'duplicate' => true];
            }
            $latest = DB::table('location_updates')->where('device_id', $device->id)->max('recorded_at');
            if ($latest !== null && $time->lessThan(CarbonImmutable::parse($latest, 'UTC')->addMinutes(15))) {
                throw ValidationException::withMessages(['recorded_at' => 'Samples must arrive in order, at least fifteen minutes apart.']);
            }
            DB::table('location_updates')->where('device_id', $device->id)->where('recorded_at', '<=', $now->subHours(24))->delete();
            DB::table('location_updates')->insert([
                'device_id' => $device->id, 'latitude' => $latitude, 'longitude' => $longitude,
                'accuracy_meters' => $accuracy, 'recorded_at' => $time, 'created_at' => $now, 'updated_at' => $now,
            ]);

            return ['accepted' => true, 'duplicate' => false];
        });
    }

    private function validatePayload(array $payload): void
    {
        $allowed = ['consent_version', 'recorded_at', 'latitude', 'longitude', 'accuracy_meters'];
        if (array_diff(array_keys($payload), $allowed)) {
            throw ValidationException::withMessages(['payload' => 'Only minimized sample fields are accepted.']);
        }
        $integer = static function ($attribute, $value, $fail): void {
            if (! is_int($value)) {
                $fail('A JSON integer is required.');
            }
        };
        $number = static function ($attribute, $value, $fail): void {
            if (! (is_int($value) || is_float($value)) || ! is_finite((float) $value)) {
                $fail('A finite JSON number is required.');
            }
        };
        Validator::make($payload, [
            'consent_version' => ['bail', 'required', $integer, 'integer', 'min:1'],
            'recorded_at' => ['bail', 'required', 'string', 'date_format:Y-m-d\TH:i:s\Z'],
            'latitude' => ['bail', 'required', $number, 'numeric', 'between:-90,90'],
            'longitude' => ['bail', 'required', $number, 'numeric', 'between:-180,180'],
            'accuracy_meters' => ['bail', 'required', $integer, 'integer', 'between:0,100000'],
        ])->validate();
    }
}
