<?php

use App\Http\Controllers\Api\V1\SellerWorkspaceController;
use App\Http\Controllers\Api\V1\SellerAccessController;
use App\Http\Controllers\Api\V1\SellerApplicationController;
use App\Http\Controllers\Api\V1\SellerCatalogWorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'abilities:customer', 'active', 'throttle:30,1'])->group(function (): void {
    Route::get('seller/application', [SellerApplicationController::class, 'show']);
    Route::put('seller/application', [SellerApplicationController::class, 'save']);
    Route::post('seller/application/submit', [SellerApplicationController::class, 'submit']);
});

Route::get('seller/memberships', [SellerAccessController::class, 'memberships'])
    ->middleware(['auth:sanctum', 'abilities:customer', 'active']);

Route::post('seller/session', [SellerAccessController::class, 'issue'])
    ->middleware(['auth:sanctum', 'abilities:customer', 'active', 'throttle:10,1']);

Route::middleware(['auth:sanctum', 'abilities:seller', 'active'])
    ->prefix('seller')->group(function (): void {
        Route::get('products', [SellerCatalogWorkspaceController::class, 'products']);
        Route::get('stock', [SellerCatalogWorkspaceController::class, 'stock']);
        Route::get('dashboard', [SellerWorkspaceController::class, 'dashboard']);
        Route::get('orders', [SellerWorkspaceController::class, 'orders']);
        Route::patch('orders/{suborder}/status', [SellerWorkspaceController::class, 'advance']);
    });
