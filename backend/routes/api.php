<?php

use App\Http\Controllers\Api\ChildController;
use App\Http\Controllers\Api\CompanionController;
use App\Http\Controllers\Api\DevicePairingController;
use App\Http\Controllers\Api\FamilyController;
use App\Http\Controllers\Api\PairedDeviceController;
use App\Http\Controllers\Api\ParentProfileController;
use App\Http\Middleware\RequirePairedDevice;
use App\Http\Middleware\ThrottleParentAuthentication;
use Illuminate\Support\Facades\Route;

Route::get('/v1/health', fn () => response()->json(['status' => 'ok']));

// A device without a parent-issued invitation cannot pair. Public routes are
// restricted by IP; 128-bit invitation codes expire in ten minutes.
Route::prefix('v1/device-pairings')->middleware('throttle:10,1')->group(function (): void {
    Route::post('/preview', [DevicePairingController::class, 'preview']);
    Route::post('/approve', [DevicePairingController::class, 'approve']);
});

// Device secrets are separate from Firebase parent ID tokens and may be revoked
// by the parent or by the device itself. No monitoring APIs are enabled here.
Route::prefix('v1/companion')->middleware([RequirePairedDevice::class, 'throttle:30,1'])->group(function (): void {
    Route::get('/me', [CompanionController::class, 'status']);
    Route::post('/disconnect', [CompanionController::class, 'disconnect']);
    Route::post('/rotate', [CompanionController::class, 'rotate']);
});

Route::prefix('v1')->middleware([ThrottleParentAuthentication::class.':60,1', 'auth:firebase-parent'])->group(function (): void {
    Route::get('/me', ParentProfileController::class);
    Route::get('/families', [FamilyController::class, 'index']);
    Route::post('/families', [FamilyController::class, 'store']);
    Route::get('/families/{family}/children', [ChildController::class, 'index']);
    Route::post('/families/{family}/children', [ChildController::class, 'store']);
    Route::get('/families/{family}/devices', [PairedDeviceController::class, 'index']);
    Route::post('/families/{family}/children/{child}/pairings', [DevicePairingController::class, 'create']);
    Route::delete('/families/{family}/devices/{device}', [PairedDeviceController::class, 'revoke']);
});
