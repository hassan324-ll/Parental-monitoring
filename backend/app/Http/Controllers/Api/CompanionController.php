<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RotateDeviceCredentialRequest;
use App\Models\Device;
use App\Services\DeviceCredentialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CompanionController extends Controller
{
    public function status(Request $request, DeviceCredentialService $credentials): JsonResponse
    {
        $device = $credentials->status($this->deviceId($request), $this->presentedHash($request));

        return response()->json([
            'paired' => true, 'device' => [
                'id' => $device->public_id,
                'name' => $device->name,
                'revoked' => false,
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function rotate(RotateDeviceCredentialRequest $request, DeviceCredentialService $credentials): JsonResponse
    {
        $device = $credentials->rotate($this->deviceId($request), $this->presentedHash($request),
            $request->validated('next_credential_hash'));

        return response()->json([
            'rotated' => true, 'device' => ['id' => $device->public_id, 'name' => $device->name],
        ])->header('Cache-Control', 'no-store');
    }

    public function disconnect(Request $request, DeviceCredentialService $credentials): JsonResponse
    {
        $credentials->disconnect($this->deviceId($request), $this->presentedHash($request));

        return response()->json(['message' => 'Device disconnected.'])->header('Cache-Control', 'no-store');
    }

    private function deviceId(Request $request): int
    {
        /** @var Device $device */
        $device = $request->attributes->get('paired_device');

        return $device->id;
    }

    private function presentedHash(Request $request): string
    {
        return hash('sha256', (string) $request->bearerToken());
    }
}
