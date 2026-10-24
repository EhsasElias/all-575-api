<?php

use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\DeliveryCodeController;
use App\Http\Controllers\Api\V1\DevPaymentController;
use App\Http\Controllers\Api\V1\OrderCancellationController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\SlotController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'abilities:customer', 'active'])->group(function (): void {
    Route::get('delivery/slots', [SlotController::class, 'index']);
    Route::post('checkout', [CheckoutController::class, 'store'])->middleware('throttle:checkout');
    Route::get('payments/{payment}', [PaymentController::class, 'show']);
    Route::post('orders/{order}/payments', [PaymentController::class, 'retry'])->middleware('throttle:checkout');
});

Route::post('webhooks/payments/{provider}', [PaymentWebhookController::class, 'handle'])->middleware('throttle:webhooks');

if (app()->environment(['local', 'testing'])) {
    Route::middleware(['auth:sanctum', 'abilities:customer'])
        ->post('dev/payments/{payment}/simulate', [DevPaymentController::class, 'simulate']);
}

Route::middleware(['auth:sanctum', 'abilities:customer', 'active'])->group(function (): void {
    Route::get('orders', [OrderController::class, 'index']);
    Route::get('orders/{order}', [OrderController::class, 'show']);
    Route::post('orders/{order}/cancel', [OrderCancellationController::class, 'store']);
    Route::get('orders/{order}/suborders/{suborder}/delivery-code', [DeliveryCodeController::class, 'show']);
});
