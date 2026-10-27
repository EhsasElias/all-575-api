<?php

use App\Http\Controllers\Api\V1\CustomerCaseController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\SpendingReportController;
use Illuminate\Support\Facades\Route;

Route::get('products/{slug}/reviews', [ReviewController::class, 'index']);

Route::middleware(['auth:sanctum', 'abilities:customer', 'active'])->group(function (): void {
    Route::get('me/cases', [CustomerCaseController::class, 'index']);
    Route::get('orders/{order}/care-items', [CustomerCaseController::class, 'items']);
    Route::post('orders/{order}/cases', [CustomerCaseController::class, 'store'])->middleware('throttle:10,1');
    Route::get('me/cases/{case}', [CustomerCaseController::class, 'show']);
    Route::post('me/cases/{case}/messages', [CustomerCaseController::class, 'message'])->middleware('throttle:20,1');

    Route::post('orders/{order}/reviews', [ReviewController::class, 'store']);
    Route::patch('reviews/{review}', [ReviewController::class, 'update'])->whereNumber('review');

    Route::get('me/reports/spending', [SpendingReportController::class, 'show']);
    Route::get('me/reports/spending.csv', [SpendingReportController::class, 'csv'])->middleware('throttle:10,1');
});
