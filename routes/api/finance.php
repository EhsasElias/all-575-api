<?php

use App\Http\Controllers\Api\V1\FinanceApprovalReviewController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'active', 'throttle:20,1'])
    ->prefix('finance')->group(function (): void {
        Route::get('approvals', [FinanceApprovalReviewController::class, 'index']);
        Route::get('approvals/{approval}/audit', [FinanceApprovalReviewController::class, 'audit'])
            ->whereNumber('approval');
    });
