<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Services\DeviceCredentialService;
use App\Support\FamilyAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PairedDeviceController extends Controller
{
    public function index(Request $request, Family $family): JsonResponse
    {
        $family = FamilyAccess::findVisible($request->user(), $family);
        $devices = $family->devices()->with('child:id,display_name')
            ->orderByDesc('id')->paginate(20);

        return response()->json([
            'data' => $devices->items(),
            'meta' => ['current_page' => $devices->currentPage(),
                'last_page' => $devices->lastPage(), 'total' => $devices->total()],
        ])->header('Cache-Control', 'no-store');
    }

    public function revoke(Request $request, Family $family, string $device, DeviceCredentialService $credentials): JsonResponse
    {
        $family = FamilyAccess::findVisible($request->user(), $family);
        abort_unless(FamilyAccess::canManage($request->user(), $family), 403);
        $paired = $family->devices()->where('public_id', $device)->firstOrFail();
        $credentials->revoke($paired->id);

        return response()->json(['message' => 'Device access revoked.']);
    }
}
