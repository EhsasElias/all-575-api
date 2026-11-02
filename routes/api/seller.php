<?php

use App\Http\Controllers\Api\V1\SellerWorkspaceController;
use App\Http\Controllers\Api\V1\SellerAccessController;
use Illuminate\Support\Facades\Route;

Route::post('seller/session', [SellerAccessController::class, 'issue'])
    ->middleware(['auth:sanctum', 'abilities:customer', 'active', 'throttle:10,1']);

Route::middleware(['auth:sanctum', 'abilities:seller', 'active'])
    ->prefix('seller')->group(function (): void {
        Route::get('dashboard', [SellerWorkspaceController::class, 'dashboard']);
        Route::get('orders', [SellerWorkspaceController::class, 'orders']);
        Route::patch('orders/{suborder}/status', [SellerWorkspaceController::class, 'advance']);
    });
