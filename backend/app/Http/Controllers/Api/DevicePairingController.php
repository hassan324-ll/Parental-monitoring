<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Family;
use App\Support\FamilyAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DevicePairingController extends Controller
{
    public function create(Request $request, Family $family, int $child): JsonResponse
    {
        $family = FamilyAccess::findVisible($request->user(), $family);
        abort_unless(FamilyAccess::canManage($request->user(), $family), 403);
        $childProfile = $family->children()->findOrFail($child);

        $code = strtoupper(bin2hex(random_bytes(16))); // 128 bits; displayed only once.
        $now = now();
        DB::table('device_pairings')->insert([
            'family_id' => $family->id,
            'child_id' => $childProfile->id,
            'token_hash' => hash('sha256', $code),
            'expires_at' => $now->copy()->addMinutes(10),
            'consumed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return response()->json([
            'code' => $code,
            'expires_at' => $now->copy()->addMinutes(10)->toIso8601String(),
            'child' => ['id' => $childProfile->id, 'display_name' => $childProfile->display_name],
        ], 201)->header('Cache-Control', 'no-store');
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'size:32', 'regex:/^[a-fA-F0-9]{32}$/']]);
        $pairing = $this->validCode($data['code']);
        if (! $pairing) {
            return response()->json(['message' => 'Pairing invitation unavailable or expired.'], 404);
        }

        $family = DB::table('families')->where('id', $pairing->family_id)->first();
        $child = DB::table('children')->where('id', $pairing->child_id)
            ->where('family_id', $pairing->family_id)->first();
        if (! $family || ! $child) {
            return response()->json(['message' => 'Pairing invitation unavailable or expired.'], 404);
        }

        return response()->json([
            'family_name' => $family->name,
            'child_name' => $child->display_name,
            'expires_at' => $pairing->expires_at,
            'requires_approval' => true,
        ])->header('Cache-Control', 'no-store');
    }

    public function approve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'size:32', 'regex:/^[a-fA-F0-9]{32}$/'],
            'approved' => ['required', 'accepted'],
            'device_name' => ['required', 'string', 'min:2', 'max:100'],
            'android_version' => ['nullable', 'string', 'max:40'],
        ]);

        $result = DB::transaction(function () use ($data): ?array {
            $pairing = DB::table('device_pairings')
                ->where('token_hash', hash('sha256', strtoupper($data['code'])))
                ->lockForUpdate()->first();

            if (! $pairing || $pairing->consumed_at !== null ||
                now()->greaterThanOrEqualTo($pairing->expires_at) || ! $pairing->child_id) {
                return null;
            }
            // The database foreign keys enforce family/child membership.
            $credential = bin2hex(random_bytes(32));
            $device = new Device;
            $device->family_id = $pairing->family_id;
            $device->child_id = $pairing->child_id;
            $device->public_id = (string) Str::uuid();
            $device->name = trim($data['device_name']);
            $device->android_version = $data['android_version'] ?? null;
            $device->credential_hash = hash('sha256', $credential);
            $device->last_seen_at = now();
            $device->save();
            DB::table('device_credential_history')->insert([
                'device_id' => $device->id, 'credential_hash' => $device->credential_hash, 'created_at' => now(),
            ]);

            DB::table('device_pairings')->where('id', $pairing->id)->update([
                'consumed_at' => now(),
                'updated_at' => now(),
            ]);

            return ['device' => [
                'id' => $device->public_id, 'name' => $device->name,
                'paired' => true,
            ], 'device_token' => $credential];
        });

        if (! $result) {
            return response()->json(['message' => 'Pairing invitation unavailable or expired.'], 404);
        }

        return response()->json($result, 201)->header('Cache-Control', 'no-store');
    }

    private function validCode(string $code): ?object
    {
        return DB::table('device_pairings')
            ->where('token_hash', hash('sha256', strtoupper($code)))
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->first();
    }
}
