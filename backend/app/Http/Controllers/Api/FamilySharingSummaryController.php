<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Services\FamilySharingSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FamilySharingSummaryController extends Controller
{
    public function show(Request $request, Family $family, string $device, FamilySharingSummaryService $summaries): JsonResponse
    {
        return response()->json($summaries->read($request->user(), $family, $device))->header('Cache-Control', 'no-store');
    }
}
