<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeviceCredentialService
{
    public function status(int $deviceId, string $presentedHash): Device
    {
        return DB::transaction(function () use ($deviceId, $presentedHash): Device {
            $device = $this->current($deviceId, $presentedHash);
            $device->last_seen_at = now();
            $device->save();

            return $device;
        });
    }

    public function rotate(int $deviceId, string $presentedHash, string $nextHash): Device
    {
        return DB::transaction(function () use ($deviceId, $presentedHash, $nextHash): Device {
            $device = $this->current($deviceId, $presentedHash);
            // Seed an initial credential too, including devices predating the migration.
            DB::table('device_credential_history')->insertOrIgnore([
                'device_id' => $device->id, 'credential_hash' => $presentedHash, 'created_at' => now(),
            ]);
            $used = Device::where('credential_hash', $nextHash)->exists();
            if ($used || DB::table('device_credential_history')->insertOrIgnore([
                'device_id' => $device->id, 'credential_hash' => $nextHash, 'created_at' => now(),
            ]) !== 1) {
                throw ValidationException::withMessages([
                    'next_credential_hash' => 'The replacement credential cannot be reused.',
                ]);
            }
            $device->credential_hash = $nextHash;
            $device->save();

            return $device;
        });
    }

    public function disconnect(int $deviceId, string $presentedHash): void
    {
        DB::transaction(function () use ($deviceId, $presentedHash): void {
            $device = $this->current($deviceId, $presentedHash);
            $this->revokeLocked($device);
        });
    }

    /** Parent authorization must be checked by the family-scoped controller first. */
    public function revoke(int $deviceId): void
    {
        DB::transaction(function () use ($deviceId): void {
            $this->revokeLocked(Device::whereKey($deviceId)->lockForUpdate()->firstOrFail());
        });
    }

    /** Runs device-owned work under the same lock as rotation and revocation. */
    public function forCurrentDevice(int $deviceId, string $presentedHash, \Closure $work): mixed
    {
        return DB::transaction(fn () => $work($this->current($deviceId, $presentedHash)));
    }

    private function current(int $deviceId, string $presentedHash): Device
    {
        $device = Device::whereKey($deviceId)->lockForUpdate()->first();
        abort_unless($device && $device->revoked_at === null && is_string($device->credential_hash)
            && hash_equals($device->credential_hash, $presentedHash), 401);

        return $device;
    }

    private function revokeLocked(Device $device): void
    {
        DeviceSharingService::revokeForDevice($device->id);
        $device->credential_hash = null;
        $device->revoked_at = now();
        $device->save();
    }
}
