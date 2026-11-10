<?php

use App\Http\Controllers\Api\V1\SuperAdminDashboardController;
use App\Http\Controllers\Api\V1\PlatformAdminAccessController;
use App\Http\Controllers\Api\V1\PlatformAdminDashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'abilities:customer', 'active', 'throttle:30,1'])
    ->get('platform/super-admin/dashboard', [SuperAdminDashboardController::class, 'show']);

Route::middleware(['auth:sanctum', 'abilities:customer', 'active', 'throttle:30,1'])
    ->group(function (): void {
        Route::get('platform/super-admin/admins', [PlatformAdminAccessController::class, 'index']);
        Route::post('platform/super-admin/admins', [PlatformAdminAccessController::class, 'store'])
            ->middleware('throttle:10,1');
        Route::patch('platform/super-admin/admins/{access}', [PlatformAdminAccessController::class, 'update'])
            ->whereNumber('access')->middleware('throttle:10,1');
        Route::get('platform/admin/dashboard', [PlatformAdminDashboardController::class, 'show']);
        Route::get('platform/admin/orders', [PlatformAdminDashboardController::class, 'orders']);
    });
