<?php

use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\QuoteController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'abilities:customer', 'active'])->group(function (): void {
    Route::get('cart', [CartController::class, 'show']);
    Route::delete('cart', [CartController::class, 'clear']);
    Route::post('cart/merge', [CartController::class, 'merge']);
    Route::put('cart/items/{variant}', [CartController::class, 'setItem'])->whereNumber('variant');

    Route::post('quotes', [QuoteController::class, 'store'])->middleware('throttle:quotes');
});
