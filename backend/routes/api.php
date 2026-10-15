<?php

use App\Http\Controllers\Api\ChildController;
use App\Http\Controllers\Api\FamilyController;
use App\Http\Controllers\Api\ParentProfileController;
use App\Http\Middleware\ThrottleParentAuthentication;
use Illuminate\Support\Facades\Route;

Route::get('/v1/health', fn () => response()->json(['status' => 'ok']));

// Only RSA-verified, email-verified Firebase parents can access these routes.
// Future Android companion endpoints require separate consent-based device auth.
Route::prefix('v1')->middleware([ThrottleParentAuthentication::class.':60,1', 'auth:firebase-parent'])->group(function (): void {
    Route::get('/me', ParentProfileController::class);
    Route::get('/families', [FamilyController::class, 'index']);
    Route::post('/families', [FamilyController::class, 'store']);
    Route::get('/families/{family}/children', [ChildController::class, 'index']);
    Route::post('/families/{family}/children', [ChildController::class, 'store']);
});
