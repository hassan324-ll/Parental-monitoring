<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Support\FamilyAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ChildController extends Controller
{
    public function index(Request $request, Family $family): JsonResponse
    {
        $family = FamilyAccess::findVisible($request->user(), $family);
        $children = $family->children()->orderBy('id')->paginate(20);

        return response()->json([
            'data' => $children->items(),
            'meta' => [
                'current_page' => $children->currentPage(),
                'last_page' => $children->lastPage(),
                'total' => $children->total(),
            ],
        ]);
    }

    public function store(Request $request, Family $family): JsonResponse
    {
        $family = FamilyAccess::findVisible($request->user(), $family);
        abort_unless(FamilyAccess::canManage($request->user(), $family), 403);

        $validated = $request->validate([
            'display_name' => ['required', 'string', 'min:1', 'max:100'],
        ]);

        $child = $family->children()->create($validated);

        return response()->json(['data' => $child], 201);
    }
}
