<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Support\FamilyAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class FamilyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $families = FamilyAccess::visibleTo($request->user())
            ->withCount(['children', 'devices'])
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'data' => $families->items(),
            'meta' => [
                'current_page' => $families->currentPage(),
                'last_page' => $families->lastPage(),
                'total' => $families->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $family = DB::transaction(function () use ($request, $validated): Family {
            $family = new Family(['name' => $validated['name']]);
            $family->owner()->associate($request->user());
            $family->save();
            $family->members()->attach($request->user()->getKey(), ['role' => 'owner']);

            return $family;
        });

        return response()->json(['data' => $family], 201);
    }
}
