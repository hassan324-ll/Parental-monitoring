<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CompanionController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('paired_device');
        $device->last_seen_at = now();
        $device->save();

        return response()->json([
            'paired' => true, 'device' => [
                'id' => $device->public_id,
                'name' => $device->name,
                'revoked' => false,
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function disconnect(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('paired_device');
        $device->credential_hash = null;
        $device->revoked_at = now();
        $device->save();

        return response()->json(['message' => 'Device disconnected.']);
    }
}
