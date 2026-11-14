<?php

use App\Http\Controllers\Api\V1\SuperAdminDashboardController;
use App\Http\Controllers\Api\V1\SuperAdminMetricController;
use App\Http\Controllers\Api\V1\SuperAdminAccountController;
use App\Http\Controllers\Api\V1\PlatformAdminAccessController;
use App\Http\Controllers\Api\V1\PlatformAdminDashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'abilities:customer', 'active', 'throttle:30,1'])
    ->get('platform/super-admin/dashboard', [SuperAdminDashboardController::class, 'show']);

Route::middleware(['auth:sanctum', 'abilities:customer', 'active', 'throttle:30,1'])
    ->group(function (): void {
        Route::patch('platform/super-admin/accounts/{user}', [SuperAdminAccountController::class, 'update'])->whereNumber('user')->middleware('throttle:10,1');
        Route::patch('platform/super-admin/sellers/{seller}', [SuperAdminAccountController::class, 'updateSeller'])->whereNumber('seller')->middleware('throttle:10,1');
        Route::get('platform/super-admin/metrics/{metric}', [SuperAdminMetricController::class, 'index']);
        Route::get('platform/super-admin/metrics/{metric}/export/{format}', [SuperAdminMetricController::class, 'export']);
        Route::get('platform/super-admin/sellers', [PlatformAdminAccessController::class, 'sellers']);
        Route::get('platform/super-admin/admins', [PlatformAdminAccessController::class, 'index']);
        Route::post('platform/super-admin/admins', [PlatformAdminAccessController::class, 'store'])
            ->middleware('throttle:10,1');
        Route::patch('platform/super-admin/admins/{access}', [PlatformAdminAccessController::class, 'update'])
            ->whereNumber('access')->middleware('throttle:10,1');
        Route::get('platform/admin/dashboard', [PlatformAdminDashboardController::class, 'show']);
        Route::get('platform/admin/orders', [PlatformAdminDashboardController::class, 'orders']);
        Route::get('platform/admin/inventory', [PlatformAdminDashboardController::class, 'inventory']);
    });
