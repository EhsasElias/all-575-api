<?php

use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\SpendingReportController;
use Illuminate\Support\Facades\Route;

Route::get('products/{slug}/reviews', [ReviewController::class, 'index']);

Route::middleware(['auth:sanctum', 'abilities:customer', 'active'])->group(function (): void {
    Route::post('orders/{order}/reviews', [ReviewController::class, 'store']);
    Route::patch('reviews/{review}', [ReviewController::class, 'update'])->whereNumber('review');

    Route::get('me/reports/spending', [SpendingReportController::class, 'show']);
    Route::get('me/reports/spending.csv', [SpendingReportController::class, 'csv'])->middleware('throttle:10,1');
});
