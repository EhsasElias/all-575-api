<?php

declare(strict_types=1);

namespace App\Domain\Orders\Services;

use App\Domain\Cart\Models\PromoRedemption;
use App\Domain\Inventory\Services\StockReservations;
use App\Domain\Orders\Enums\OrderStatus;
use App\Domain\Orders\Enums\SuborderStatus;
use App\Domain\Orders\Models\DeliverySlot;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\StockReservation;
use App\Domain\Payments\Enums\PaymentStatus;
use Illuminate\Support\Facades\DB;

/** Gives back everything an unpaid order was holding: stock, the delivery slot and the promo use. */
final class UnpaidOrderCancellation
{
    public function __construct(private readonly StockReservations $stock) {}

    public function cancel(Order $order, string $reason): bool
    {
        return DB::transaction(function () use ($order, $reason): bool {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->status !== OrderStatus::PendingPayment) {
                return false;
            }

            $this->stock->release($locked);
            $this->releaseSlot($locked);
            PromoRedemption::query()->where('order_id', $locked->id)->delete();

            $locked->payments()->where('status', PaymentStatus::Pending->value)->update(['status' => PaymentStatus::Expired->value]);
            $locked->suborders()->update(['status' => SuborderStatus::Cancelled->value]);
            $locked->forceFill(['status' => OrderStatus::Cancelled, 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
            $locked->record('order_cancelled', 'system', null, ['reason' => $reason]);

            return true;
        });
    }

    /** Cancels every unpaid order whose held stock has run out of time. Returns how many were cancelled. */
    public function expireOverdue(): int
    {
        $overdue = Order::query()
            ->where('status', OrderStatus::PendingPayment->value)
            ->whereHas('reservations', fn ($query) => $query->where('status', StockReservation::HELD)->where('expires_at', '<=', now()))
            ->get();

        return $overdue->filter(fn (Order $order): bool => $this->cancel($order, 'payment_timeout'))->count();
    }

    private function releaseSlot(Order $order): void
    {
        if ($order->delivery_slot_id !== null) {
            DeliverySlot::query()->whereKey($order->delivery_slot_id)->where('booked', '>', 0)->decrement('booked');
        }
    }
}
