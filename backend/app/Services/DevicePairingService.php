<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class DevicePairingService
{
    public function approve(array $data): ?array
    {
        return DB::transaction(function () use ($data): ?array {
            $pairing = DB::table('device_pairings')
                ->where('token_hash', hash('sha256', strtoupper($data['code'])))
                ->lockForUpdate()->first();

            if (! $pairing || $pairing->consumed_at !== null ||
                now()->greaterThanOrEqualTo($pairing->expires_at) || ! $pairing->child_id) {
                return null;
            }
            $staged = array_key_exists('credential_hash', $data);
            $credential = $staged ? null : bin2hex(random_bytes(32));
            $hash = $staged ? $data['credential_hash'] : hash('sha256', $credential);
            if (Device::where('credential_hash', $hash)->exists()) {
                $this->rejectReuse();
            }
            // Ownership is derived only from the invitation; provisional row stays inside this transaction.
            $device = new Device;
            $device->family_id = $pairing->family_id;
            $device->child_id = $pairing->child_id;
            $device->public_id = (string) Str::uuid();
            $device->name = trim($data['device_name']);
            $device->android_version = $data['android_version'] ?? null;
            $device->last_seen_at = now();
            $device->save();
            // The global unique history constraint arbitrates concurrent approval/rotation reuse too.
            if (DB::table('device_credential_history')->insertOrIgnore([
                'device_id' => $device->id, 'credential_hash' => $hash, 'created_at' => now(),
            ]) !== 1) {
                $this->rejectReuse();
            }
            $device->credential_hash = $hash;
            $device->save();
            DB::table('device_pairings')->where('id', $pairing->id)->update([
                'consumed_at' => now(), 'updated_at' => now(),
            ]);
            $result = ['device' => [
                'id' => $device->public_id, 'name' => $device->name, 'paired' => true,
            ]];
            if (! $staged) {
                $result['device_token'] = $credential;
            }

            return $result;
        });
    }

    private function rejectReuse(): never
    {
        throw ValidationException::withMessages(['credential_hash' => 'The credential cannot be reused.']);
    }
}
