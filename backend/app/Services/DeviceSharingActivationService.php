<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Unrouted, default-disabled fresh-consent transition. Never an OS permission grant. */
final class DeviceSharingActivationService
{
    public function __construct(private DeviceCredentialService $credentials) {}

    public function confirm(int $id, string $hash, string $feature, array $decision): array
    {
        return $this->credentials->forCurrentDevice($id, $hash, function (Device $device) use ($feature, $decision): array {
            abort_unless(config('familyguard.device_activation_enabled') === true, 409, 'Device activation is unavailable.');
            abort_unless(in_array($feature, ['usage', 'location'], true), 404);
            $disclosure = $feature === 'usage' ? UsageIngestionService::ACTIVATION_DISCLOSURE : LocationIngestionService::ACTIVATION_DISCLOSURE;
            if (array_diff(array_keys($decision), ['acknowledged', 'expected_version', 'disclosure_version'])
                || ($decision['acknowledged'] ?? null) !== true || ! is_int($decision['expected_version'] ?? null)
                || $decision['expected_version'] < 1 || ($decision['disclosure_version'] ?? null) !== $disclosure) {
                throw ValidationException::withMessages(['decision' => 'Fresh explicit acknowledgment, current preference version and feature disclosure are required.']);
            }
            $previous = DB::table('device_sharing_consents')->where('device_id', $device->id)->where('feature', $feature)->first();
            abort_unless($previous && $previous->enabled && $previous->revoked_at === null
                && $previous->disclosure_version === 'sharing-v1' && (int) $previous->version === $decision['expected_version'], 409, 'Refresh the device preference before confirming a new grant.');
            // New grant must not relabel old data or carry retry watermarks across epochs.
            DB::table($feature === 'usage' ? 'app_usage_daily' : 'location_updates')->where('device_id', $device->id)->delete();
            if ($feature === 'usage') {
                DB::table('usage_ingestion_snapshots')->where('device_id', $device->id)->delete();
            }
            $version = (int) $previous->version + 1;
            DB::table('device_sharing_consents')->where('id', $previous->id)->update([
                'enabled' => true, 'version' => $version, 'disclosure_version' => $disclosure,
                'granted_at' => now('UTC'), 'revoked_at' => null, 'updated_at' => now('UTC'),
            ]);

            return ['consent_recorded' => true, 'feature' => $feature, 'version' => $version,
                'disclosure_version' => $disclosure, 'collection_available' => false];
        });
    }
}
