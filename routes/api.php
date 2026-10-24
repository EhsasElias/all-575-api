<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::group([], base_path('routes/api/identity.php'));
});
