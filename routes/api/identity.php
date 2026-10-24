<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\SessionController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth/otp')->group(function (): void {
    Route::post('request', [AuthController::class, 'requestOtp'])->middleware('throttle:otp-request');
    Route::post('verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:otp-verify');
});

Route::middleware(['auth:sanctum', 'abilities:customer', 'active'])->group(function (): void {
    Route::delete('auth/session', [AuthController::class, 'logout']);

    Route::get('me', [MeController::class, 'show']);
    Route::patch('me', [MeController::class, 'update']);
    Route::put('me/consents/marketing', [MeController::class, 'updateMarketingConsent']);

    Route::get('me/sessions', [SessionController::class, 'index']);
    Route::delete('me/sessions/{session}', [SessionController::class, 'destroy'])->whereNumber('session');
});
