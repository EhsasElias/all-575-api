<?php

use App\Http\Controllers\Api\V1\SellerWorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'abilities:seller', 'active'])
    ->prefix('seller')->group(function (): void {
        Route::get('orders', [SellerWorkspaceController::class, 'orders']);
        Route::patch('orders/{suborder}/status', [SellerWorkspaceController::class, 'advance']);
    });
