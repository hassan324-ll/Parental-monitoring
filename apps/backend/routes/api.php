<?php

use App\Http\Controllers\Api\ChildController;
use App\Http\Controllers\Api\FamilyController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/health', fn () => response()->json(['status' => 'ok']));

// All family endpoints require an RSA-verified, email-verified Firebase ID token.
// Firebase project configuration is required; missing configuration fails closed.
// Android companion devices will use a separate revocable device guard later.
Route::prefix('v1')->middleware(['auth:firebase-parent', 'throttle:60,1'])->group(function (): void {
    Route::get('/families', [FamilyController::class, 'index']);
    Route::post('/families', [FamilyController::class, 'store']);
    Route::get('/families/{family}/children', [ChildController::class, 'index']);
    Route::post('/families/{family}/children', [ChildController::class, 'store']);
});
