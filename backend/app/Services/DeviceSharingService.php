<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Support\Facades\DB;

final class DeviceSharingService
{
    public function __construct(private DeviceCredentialService $credentials) {}

    public function status(int $id, string $hash): array
    {
        return $this->credentials->forCurrentDevice($id, $hash, fn (Device $d) => $this->snapshot($d->id));
    }

    public function decide(int $id, string $hash, string $feature, bool $enabled, ?string $disclosure = null): array
    {
        abort_unless(in_array($feature, ['usage', 'location'], true), 404);
        abort_unless(! $enabled || $disclosure === 'sharing-v1', 422);

        return $this->credentials->forCurrentDevice($id, $hash, function (Device $d) use ($feature, $enabled, $disclosure): array {
            $old = DB::table('device_sharing_consents')->where('device_id', $d->id)->where('feature', $feature)->first();
            $now = now();
            DB::table('device_sharing_consents')->upsert([[
                'device_id' => $d->id, 'feature' => $feature, 'enabled' => $enabled, 'version' => ($old->version ?? 0) + 1,
                'disclosure_version' => $enabled ? $disclosure : ($old->disclosure_version ?? null),
                'granted_at' => $enabled ? $now : ($old->granted_at ?? null), 'revoked_at' => $enabled ? null : $now,
                'created_at' => $old->created_at ?? $now, 'updated_at' => $now,
            ]], ['device_id', 'feature'], ['enabled', 'version', 'disclosure_version', 'granted_at', 'revoked_at', 'updated_at']);
            if (! $enabled) {
                self::purgeFeature($d->id, $feature);
            }

            return $this->snapshot($d->id);
        });
    }

    /** Caller holds the device row lock in the same transaction as credential revocation. */
    public static function revokeForDevice(int $id): void
    {
        DB::table('device_sharing_consents')->where('device_id', $id)->update([
            'enabled' => false, 'version' => DB::raw('version + 1'), 'revoked_at' => now(), 'updated_at' => now()]);
        self::purgeFeature($id, 'usage');
        self::purgeFeature($id, 'location');
    }

    public function prune(): void
    {
        Device::orderBy('id')->chunkById(100, function ($devices): void {
            foreach ($devices as $candidate) {
                DB::transaction(function () use ($candidate): void {
                    $d = Device::whereKey($candidate->id)->lockForUpdate()->first();
                    if (! $d) {
                        return;
                    }
                    $state = $this->snapshot($d->id)['sharing'];
                    foreach (['usage', 'location'] as $feature) {
                        if ($d->revoked_at !== null || ! $state[$feature]['enabled']) {
                            self::purgeFeature($d->id, $feature);
                        }
                    }
                    DB::table('app_usage_daily')->where('device_id', $d->id)->where('day', '<', now('UTC')->startOfDay()->subDays(6)->toDateString())->delete();
                    DB::table('location_updates')->where('device_id', $d->id)->where('recorded_at', '<=', now('UTC')->subHours(24))->delete();
                    DB::table('usage_ingestion_snapshots')->where('device_id', $d->id)->where('day', '<', now('UTC')->startOfDay()->subDays(6)->toDateString())->delete();
                });
            }
        });
    }

    private function snapshot(int $id): array
    {
        $rows = DB::table('device_sharing_consents')->where('device_id', $id)->get()->keyBy('feature');
        $sharing = [];
        foreach (['usage', 'location'] as $feature) {
            $r = $rows->get($feature);
            $sharing[$feature] = [
                'enabled' => (bool) ($r->enabled ?? false), 'version' => (int) ($r->version ?? 0),
                'retention' => $feature === 'usage' ? '7 UTC calendar days including today' : '24 hours'];
        }

        return ['sharing' => $sharing, 'disclosure_version' => 'sharing-v1', 'collection_available' => false];
    }

    private static function purgeFeature(int $id, string $feature): void
    {
        DB::table($feature === 'usage' ? 'app_usage_daily' : 'location_updates')->where('device_id', $id)->delete();
        if ($feature === 'usage') {
            DB::table('usage_ingestion_snapshots')->where('device_id', $id)->delete();
        }
    }
}
