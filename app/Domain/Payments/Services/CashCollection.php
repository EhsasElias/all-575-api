<?php

declare(strict_types=1);

namespace App\Domain\Payments\Services;

use App\Domain\Orders\Enums\OrderStatus;
use App\Domain\Orders\Models\Order;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\Payment;
use App\Support\Exceptions\DomainRuleViolation;
use Illuminate\Support\Facades\DB;

/** Called by trusted operations after confirming the full cash amount, never by a customer. */
final class CashCollection
{
    public function record(Order $order, int $amount, string $reference): void
    {
        DB::transaction(function () use ($order, $amount, $reference): void {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $payment = Payment::query()->where('order_id', $locked->id)
                ->where('method', PaymentMethod::CashOnDelivery->value)->lockForUpdate()->first();
            if ($payment === null || $locked->status !== OrderStatus::Completed || $amount !== $payment->amount_halalas || $locked->suborders()->where('status', '!=', 'delivered')->exists()) {
                throw new DomainRuleViolation('cash_collection_invalid', 'Confirm delivery and the exact cash amount first.', 409);
            }
            if ($payment->status === PaymentStatus::Paid) {
                if ($payment->provider_reference !== $reference) {
                    throw new DomainRuleViolation('cash_already_collected', 'Cash was already recorded with another reference.', 409);
                }

                return;
            }
            if ($payment->status !== PaymentStatus::Pending) {
                throw new DomainRuleViolation('cash_collection_invalid', 'This cash payment is closed.', 409);
            }
            $payment->forceFill(['status' => PaymentStatus::Paid, 'paid_at' => now(), 'provider_reference' => $reference])->save();
            $locked->forceFill(['paid_at' => now()])->save();
            $locked->record('payment_confirmed', 'operations', null, ['reference' => $reference]);
        });
    }
}
