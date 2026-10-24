<?php

declare(strict_types=1);

namespace App\Domain\Orders\Services;

use App\Domain\Cart\Models\Cart;
use App\Domain\Inventory\Services\StockReservations;
use App\Domain\Orders\Enums\OrderStatus;
use App\Domain\Orders\Enums\SuborderStatus;
use App\Domain\Orders\Events\OrderPaid;
use App\Domain\Orders\Models\Order;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\Payment;
use Illuminate\Support\Facades\DB;

final class OrderConfirmation
{
    public function __construct(private readonly StockReservations $stock) {}

    /** The one place an order becomes paid. Call it inside a transaction with the order and payment locked. */
    public function confirm(Order $order, Payment $payment): void
    {
        $payment->forceFill(['status' => PaymentStatus::Paid, 'paid_at' => now(), 'failure_code' => null])->save();

        $order->forceFill(['status' => OrderStatus::Confirmed, 'paid_at' => now()])->save();
        $order->suborders()->where('status', SuborderStatus::AwaitingPayment->value)->update(['status' => SuborderStatus::AwaitingSeller->value]);

        $this->stock->commit($order);
        $this->removePurchasedLinesFromCart($order);

        $order->record('payment_confirmed', 'system');
        $order->record('order_confirmed', 'system');

        DB::afterCommit(fn () => event(new OrderPaid($order)));
    }

    private function removePurchasedLinesFromCart(Order $order): void
    {
        $cart = Cart::query()->where('user_id', $order->user_id)->first();

        $cart?->items()->whereIn('variant_id', $order->items()->pluck('variant_id'))->delete();
    }
}
