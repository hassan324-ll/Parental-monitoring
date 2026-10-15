<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ParentProfileController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $parent = $request->user();

        return response()->json(['data' => [
            'id' => $parent->getKey(),
            'name' => $parent->name,
            'email' => $parent->email,
        ]]);
    }
}
