<?php

declare(strict_types=1);

namespace App\Domain\Orders\Actions;

use App\Domain\Orders\Enums\OrderStatus;
use App\Domain\Orders\Enums\SuborderStatus;
use App\Domain\Orders\Models\DeliverySlot;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\StockReservation;
use App\Domain\Orders\Services\UnpaidOrderCancellation;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\Services\Refunds;
use App\Support\Exceptions\DomainRuleViolation;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/** The customer's own cancellation: free until the seller starts preparing, with a full refund once paid. */
final class CancelOrder
{
    public function __construct(
        private readonly UnpaidOrderCancellation $unpaid,
        private readonly Refunds $refunds,
    ) {}

    public function handle(Order $order): Order
    {
        if ($order->status === OrderStatus::PendingPayment && $this->unpaid->cancel($order, 'customer_cancelled')) {
            return $order->refresh();
        }

        // The order may have been paid a moment ago, so what we loaded is stale: the paid path reads it again
        // under a lock and refuses when it can no longer be cancelled.
        $refund = DB::transaction(fn (): ?Refund => $this->cancelPaid($order));

        if ($refund !== null) {
            // The provider is told only after our own books have committed. If it cannot be reached the refund
            // stays pending and `payments:retry-refunds` sends it again; the cancellation itself stands.
            $this->refunds->sendSafely($refund);
        }

        return $order->refresh();
    }

    private function cancelPaid(Order $order): ?Refund
    {
        $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

        if ($locked->status !== OrderStatus::Confirmed || ! $locked->suborders->every(fn ($s): bool => $s->status->customerMayCancel())) {
            throw new DomainRuleViolation('order_not_cancellable', 'This order is already being prepared. Contact support to cancel or return it.', 409);
        }

        $this->restock($locked);
        DeliverySlot::query()->whereKey($locked->delivery_slot_id)->where('booked', '>', 0)->decrement('booked');

        $locked->suborders()->update(['status' => SuborderStatus::Cancelled->value]);
        $locked->forceFill(['status' => OrderStatus::Cancelled, 'cancelled_at' => now(), 'cancel_reason' => 'customer_cancelled'])->save();
        $locked->record('order_cancelled', 'customer');

        // Nothing was charged for an order a promo covered in full, so there is nothing to give back.
        if ($locked->total_halalas === 0) {
            return null;
        }

        // The payment that confirmed the order: the earliest paid one that was not already sent back as a late duplicate.
        $payment = $locked->payments()->reorder()->orderBy('paid_at')->orderBy('id')
            ->where('status', PaymentStatus::Paid->value)
            ->whereDoesntHave('refunds')
            ->firstOrFail();

        return $this->refunds->request($locked, $payment, Money::halalas($locked->total_halalas), 'customer_cancelled');
    }

    /** Paid stock had left the shelf; cancelling puts it back where it came from. */
    private function restock(Order $order): void
    {
        foreach ($order->reservations()->where('status', StockReservation::COMMITTED)->get() as $reservation) {
            DB::table('branch_stock')->where('id', $reservation->branch_stock_id)->increment('on_hand', $reservation->quantity);
            $reservation->forceFill(['status' => StockReservation::RELEASED])->save();
        }
    }
}
