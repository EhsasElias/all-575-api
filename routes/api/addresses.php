<?php

use App\Http\Controllers\Api\V1\AddressController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'abilities:customer', 'active'])->prefix('addresses')->group(function (): void {
    Route::get('/', [AddressController::class, 'index']);
    Route::post('/', [AddressController::class, 'store']);
    Route::get('{address}', [AddressController::class, 'show'])->whereNumber('address');
    Route::put('{address}', [AddressController::class, 'update'])->whereNumber('address');
    Route::put('{address}/default', [AddressController::class, 'makeDefault'])->whereNumber('address');
    Route::delete('{address}', [AddressController::class, 'destroy'])->whereNumber('address');
});
