<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateDeviceSharingRequest;
use App\Services\DeviceSharingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DeviceSharingController extends Controller
{
    public function status(Request $r, DeviceSharingService $s): JsonResponse
    {
        return response()->json($s->status($r->attributes->get('paired_device')->id, hash('sha256', (string) $r->bearerToken())))->header('Cache-Control', 'no-store');
    }

    public function update(UpdateDeviceSharingRequest $r, string $feature, DeviceSharingService $s): JsonResponse
    {
        return response()->json($s->decide($r->attributes->get('paired_device')->id, hash('sha256', (string) $r->bearerToken()), $feature, $r->validated('enabled'), $r->validated('disclosure_version')))->header('Cache-Control', 'no-store');
    }

    public function remove(Request $r, string $feature, DeviceSharingService $s): JsonResponse
    {
        return response()->json($s->decide($r->attributes->get('paired_device')->id, hash('sha256', (string) $r->bearerToken()), $feature, false))->header('Cache-Control', 'no-store');
    }
}
