<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::group([], base_path('routes/api/identity.php'));
    Route::group([], base_path('routes/api/catalog.php'));
    Route::group([], base_path('routes/api/addresses.php'));
    Route::group([], base_path('routes/api/cart.php'));
    Route::group([], base_path('routes/api/checkout.php'));
    Route::group([], base_path('routes/api/aftercare.php'));
});
