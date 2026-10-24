<?php

use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\SellerController;
use App\Http\Controllers\Api\V1\StoreController;
use App\Http\Controllers\Api\V1\WelcomeController;
use App\Http\Controllers\Api\V1\ZoneController;
use Illuminate\Support\Facades\Route;

Route::get('store', [StoreController::class, 'show']);
Route::get('welcome', [WelcomeController::class, 'index']);
Route::get('categories', [CategoryController::class, 'index']);
Route::get('products', [ProductController::class, 'index']);
Route::get('products/{slug}', [ProductController::class, 'show']);
Route::get('sellers/{slug}', [SellerController::class, 'show']);
Route::get('zones/resolve', [ZoneController::class, 'resolve']);
