<?php

use App\Http\Controllers\Api\ChildController;
use App\Http\Controllers\Api\FamilyController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/health', fn () => response()->json(['status' => 'ok']));

// Laravel auth is used by backend tests. Production Firebase bearer-token
// verification is NOT configured yet: unauthenticated HTTP requests fail closed.
Route::prefix('v1')->middleware(['auth', 'throttle:60,1'])->group(function (): void {
    Route::get('/families', [FamilyController::class, 'index']);
    Route::post('/families', [FamilyController::class, 'store']);
    Route::get('/families/{family}/children', [ChildController::class, 'index']);
    Route::post('/families/{family}/children', [ChildController::class, 'store']);
});
