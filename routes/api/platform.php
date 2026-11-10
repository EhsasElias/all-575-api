<?php

use App\Http\Controllers\Api\V1\SuperAdminDashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'abilities:customer', 'active', 'throttle:30,1'])
    ->get('platform/super-admin/dashboard', [SuperAdminDashboardController::class, 'show']);
