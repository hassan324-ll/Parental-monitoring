<?php

use App\Http\Controllers\Api\ChildController;
use App\Http\Controllers\Api\FamilyController;
use App\Http\Middleware\ThrottleParentAuthentication;
use Illuminate\Support\Facades\Route;

Route::get('/v1/health', fn () => response()->json(['status' => 'ok']));

// All family endpoints require an RSA-verified, email-verified Firebase ID token.
// Firebase project configuration is required; missing configuration fails closed.
// Android companion devices will use a separate revocable device guard later.
Route::prefix('v1')->middleware([ThrottleParentAuthentication::class.':60,1', 'auth:firebase-parent'])->group(function (): void {
    Route::get('/families', [FamilyController::class, 'index']);
    Route::post('/families', [FamilyController::class, 'store']);
    Route::get('/families/{family}/children', [ChildController::class, 'index']);
    Route::post('/families/{family}/children', [ChildController::class, 'store']);
});
