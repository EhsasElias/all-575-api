<?php

use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AdminOrderController;
use App\Http\Controllers\Api\AdminSellerApplicationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\HomeController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SellerApplicationController;
use App\Http\Controllers\Api\SellerController;
use App\Http\Controllers\Api\SellerDashboardController;
use App\Http\Controllers\Api\SellerOrderController;
use App\Http\Controllers\Api\SellerProductController;
use App\Http\Controllers\Api\SliderController;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\SellerMiddleware;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    Route::post('/register', [
        AuthController::class,
        'register',
    ]);

    Route::post('/login', [
        AuthController::class,
        'login',
    ]);

    /*
|--------------------------------------------------------------------------
| Home
|--------------------------------------------------------------------------
*/

    Route::get('/home', [
        HomeController::class,
        'index',
    ]);
    /*
    |--------------------------------------------------------------------------
    | Public Categories
    |--------------------------------------------------------------------------
    */

    Route::get('/categories', [
        CategoryController::class,
        'index',
    ]);

    Route::get('/categories/{category}', [
        CategoryController::class,
        'show',
    ]);

    /*
    |--------------------------------------------------------------------------
    | Public Sellers
    |--------------------------------------------------------------------------
    */

    Route::get('/sellers', [
        SellerController::class,
        'index',
    ]);

    Route::get('/sellers/{seller}', [
        SellerController::class,
        'show',
    ]);

    /*
    |--------------------------------------------------------------------------
    | Public Products
    |--------------------------------------------------------------------------
    */

    Route::get('/products', [
        ProductController::class,
        'index',
    ]);

    Route::get('/products/{product}', [
        ProductController::class,
        'show',
    ]);

    /*
    |--------------------------------------------------------------------------
    | Authenticated Routes
    |--------------------------------------------------------------------------
    */

   Route::middleware([
    'auth:sanctum',
    'active',
])->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Account
        |--------------------------------------------------------------------------
        */

        Route::get('/me', [
            AuthController::class,
            'me',
        ]);

       Route::post('/logout', [
    AuthController::class,
    'logout',
])->withoutMiddleware('active');

Route::post('/logout-all', [
    AuthController::class,
    'logoutAll',
])->withoutMiddleware('active');

        /*
        |--------------------------------------------------------------------------
        | Favorites
        |--------------------------------------------------------------------------
        */

        Route::get('/favorites', [
            FavoriteController::class,
            'index',
        ]);

        Route::post('/favorites', [
            FavoriteController::class,
            'store',
        ]);

        Route::delete('/favorites/{product}', [
            FavoriteController::class,
            'destroy',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Cart
        |--------------------------------------------------------------------------
        */

        Route::get('/cart', [
            CartController::class,
            'show',
        ]);

        Route::post('/cart/items', [
            CartController::class,
            'add',
        ]);

        Route::patch('/cart/items/{product}', [
            CartController::class,
            'update',
        ]);

        Route::delete('/cart/items/{product}', [
            CartController::class,
            'remove',
        ]);

        Route::delete('/cart', [
            CartController::class,
            'clear',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Addresses
        |--------------------------------------------------------------------------
        */

        Route::get('/addresses', [
            AddressController::class,
            'index',
        ]);

        Route::post('/addresses', [
            AddressController::class,
            'store',
        ]);

        Route::get('/addresses/{address}', [
            AddressController::class,
            'show',
        ]);

        Route::put('/addresses/{address}', [
            AddressController::class,
            'update',
        ]);

        Route::patch('/addresses/{address}/default', [
            AddressController::class,
            'setDefault',
        ]);

        Route::delete('/addresses/{address}', [
            AddressController::class,
            'destroy',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Checkout
        |--------------------------------------------------------------------------
        */

        Route::post('/checkout', [
            CheckoutController::class,
            'store',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Customer Orders
        |--------------------------------------------------------------------------
        */

        Route::get('/orders', [
            OrderController::class,
            'index',
        ]);

        Route::get('/orders/{order}', [
            OrderController::class,
            'show',
        ]);

        Route::post('/orders/{order}/cancel', [
            OrderController::class,
            'cancel',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Seller Applications
        |--------------------------------------------------------------------------
        */

        Route::get('/seller-applications', [
            SellerApplicationController::class,
            'index',
        ]);

        Route::post('/seller-applications', [
            SellerApplicationController::class,
            'store',
        ]);

        Route::get('/seller-applications/{sellerApplication}', [
            SellerApplicationController::class,
            'show',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Seller Dashboard
        |--------------------------------------------------------------------------
        */

        /*
|--------------------------------------------------------------------------
| Notifications
|--------------------------------------------------------------------------
*/

        Route::get('/notifications', [
            NotificationController::class,
            'index',
        ]);

        Route::get('/notifications/unread', [
            NotificationController::class,
            'unread',
        ]);

        Route::patch('/notifications/read-all', [
            NotificationController::class,
            'markAllAsRead',
        ]);

        Route::patch('/notifications/{notification}/read', [
            NotificationController::class,
            'markAsRead',
        ]);

        Route::delete('/notifications/{notification}', [
            NotificationController::class,
            'destroy',
        ]);
        Route::prefix('seller')
            ->middleware(SellerMiddleware::class)
            ->group(function () {

                Route::get('/profile', [
                    SellerDashboardController::class,
                    'profile',
                ]);

                Route::put('/profile', [
                    SellerDashboardController::class,
                    'updateProfile',
                ]);

                Route::get('/dashboard', [
                    SellerDashboardController::class,
                    'dashboard',
                ]);

                /*
                |--------------------------------------------------------------------------
                | Seller Products
                |--------------------------------------------------------------------------
                */

                Route::get('/products', [
                    SellerProductController::class,
                    'index',
                ]);

                Route::post('/products', [
                    SellerProductController::class,
                    'store',
                ]);

                Route::get('/products/{product}', [
                    SellerProductController::class,
                    'show',
                ]);

                Route::put('/products/{product}', [
                    SellerProductController::class,
                    'update',
                ]);

                Route::delete('/products/{product}', [
                    SellerProductController::class,
                    'destroy',
                ]);

                /*
                |--------------------------------------------------------------------------
                | Seller Orders
                |--------------------------------------------------------------------------
                */

                Route::get('/orders', [
                    SellerOrderController::class,
                    'index',
                ]);

                Route::get('/orders/{order}', [
                    SellerOrderController::class,
                    'show',
                ]);
            });
        /*
        |--------------------------------------------------------------------------
        | Admin Routes
        |--------------------------------------------------------------------------
        */

        Route::prefix('admin')
            ->middleware(AdminMiddleware::class)
            ->group(function () {

                /*
                |--------------------------------------------------------------------------
                | Admin Dashboard
                |--------------------------------------------------------------------------
                */

                Route::get('/dashboard', [
                    AdminDashboardController::class,
                    'index',
                ]);
                /*
                |--------------------------------------------------------------------------
                | Admin Categories
                |--------------------------------------------------------------------------
                */

                Route::get('/categories', [
                    CategoryController::class,
                    'adminIndex',
                ]);

                Route::post('/categories', [
                    CategoryController::class,
                    'store',
                ]);

                Route::put('/categories/{category}', [
                    CategoryController::class,
                    'update',
                ]);

                Route::delete('/categories/{category}', [
                    CategoryController::class,
                    'destroy',
                ]);

                /*
                |--------------------------------------------------------------------------
                | Admin Sellers
                |--------------------------------------------------------------------------
                */

                Route::get('/sellers', [
                    SellerController::class,
                    'adminIndex',
                ]);

                Route::post('/sellers', [
                    SellerController::class,
                    'store',
                ]);

                Route::put('/sellers/{seller}', [
                    SellerController::class,
                    'update',
                ]);

                Route::patch('/sellers/{seller}/status', [
                    SellerController::class,
                    'updateStatus',
                ]);

                Route::delete('/sellers/{seller}', [
                    SellerController::class,
                    'destroy',
                ]);

                /*
                |--------------------------------------------------------------------------
                | Admin Products
                |--------------------------------------------------------------------------
                */

                Route::get('/products', [
                    ProductController::class,
                    'adminIndex',
                ]);

                Route::post('/products', [
                    ProductController::class,
                    'store',
                ]);

                Route::put('/products/{product}', [
                    ProductController::class,
                    'update',
                ]);

                Route::delete('/products/{product}', [
                    ProductController::class,
                    'destroy',
                ]);

                /*
                |--------------------------------------------------------------------------
                | Admin Orders
                |--------------------------------------------------------------------------
                */

                Route::get('/orders', [
                    AdminOrderController::class,
                    'index',
                ]);

                Route::get('/orders/{order}', [
                    AdminOrderController::class,
                    'show',
                ]);

                Route::patch('/orders/{order}/status', [
                    AdminOrderController::class,
                    'updateStatus',
                ]);

                /*
                |--------------------------------------------------------------------------
                | Admin Seller Applications
                |--------------------------------------------------------------------------
                */

                Route::get('/seller-applications', [
                    AdminSellerApplicationController::class,
                    'index',
                ]);

                Route::get('/seller-applications/{sellerApplication}', [
                    AdminSellerApplicationController::class,
                    'show',
                ]);

                Route::patch(
                    '/seller-applications/{sellerApplication}/review',
                    [
                        AdminSellerApplicationController::class,
                        'review',
                    ]
                );

                /*
|--------------------------------------------------------------------------
| Admin Sliders
|--------------------------------------------------------------------------
*/

                Route::get('/sliders', [
                    SliderController::class,
                    'adminIndex',
                ]);

                Route::post('/sliders', [
                    SliderController::class,
                    'store',
                ]);

                Route::put('/sliders/{slider}', [
                    SliderController::class,
                    'update',
                ]);

                Route::delete('/sliders/{slider}', [
                    SliderController::class,
                    'destroy',
                ]);
            });
    });
});
