<?php

declare(strict_types=1);

namespace App\Domain\Payments\Services;

use App\Domain\Orders\Enums\OrderStatus;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\OrderConfirmation;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\RefundStatus;
use App\Domain\Payments\Gateways\GatewayEvent;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentEvent;
use App\Domain\Payments\Models\Refund;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Turns verified provider callbacks into order state. This is the only code that may mark a payment paid:
 * a customer returning to the app proves nothing. Each provider event is applied at most once.
 */
final class PaymentProcessor
{
    public function __construct(
        private readonly OrderConfirmation $confirmation,
        private readonly Refunds $refunds,
    ) {}

    public function process(string $provider, GatewayEvent $event): string
    {
        $refundsToSend = [];

        $outcome = DB::transaction(function () use ($provider, $event, &$refundsToSend): string {
            try {
                $record = PaymentEvent::query()->create([
                    'provider' => $provider, 'event_id' => $event->eventId, 'type' => $event->type, 'payload' => $event->payload,
                ]);
            } catch (UniqueConstraintViolationException) {
                return 'duplicate';
            }

            $outcome = match ($event->type) {
                GatewayEvent::CHARGE_SUCCEEDED => $this->chargeSucceeded($event, $refundsToSend),
                GatewayEvent::CHARGE_FAILED => $this->chargeFailed($event),
                GatewayEvent::REFUND_SUCCEEDED => $this->refundSettled($event, RefundStatus::Succeeded),
                GatewayEvent::REFUND_FAILED => $this->refundSettled($event, RefundStatus::Failed),
                default => 'ignored',
            };

            $record->forceFill(['outcome' => $outcome])->save();

            return $outcome;
        });

        // Network calls happen only after the database work has committed.
        foreach ($refundsToSend as $refund) {
            $this->refunds->send($refund);
        }

        return $outcome;
    }

    /** @param  list<Refund>  $refundsToSend */
    private function chargeSucceeded(GatewayEvent $event, array &$refundsToSend): string
    {
        $payment = Payment::query()->lockForUpdate()->where('provider_reference', $event->providerReference)->first();

        if ($payment === null) {
            return 'unknown_payment';
        }

        if ($payment->status === PaymentStatus::Paid) {
            return 'already_paid';
        }

        $order = Order::query()->lockForUpdate()->findOrFail($payment->order_id);

        if ($event->amount !== null && ! $event->amount->equals($payment->amount())) {
            $payment->forceFill(['status' => PaymentStatus::Failed, 'failure_code' => 'amount_mismatch'])->save();
            $order->record('payment_amount_mismatch', 'system', null, ['expected' => $payment->amount_halalas, 'received' => $event->amount->halalas]);
            $refundsToSend[] = $this->refunds->request($order, $payment, $event->amount, 'amount_mismatch');

            return 'amount_mismatch_refunded';
        }

        if ($order->status !== OrderStatus::PendingPayment) {
            // The money arrived after we gave the stock back. Never confirm without stock: return the money.
            $payment->forceFill(['status' => PaymentStatus::Paid, 'paid_at' => now()])->save();
            $order->record('late_payment_refunded', 'system');
            $refundsToSend[] = $this->refunds->request($order, $payment, $payment->amount(), 'late_payment');

            return 'late_payment_refunded';
        }

        $this->confirmation->confirm($order, $payment);

        return 'confirmed';
    }

    private function chargeFailed(GatewayEvent $event): string
    {
        $payment = Payment::query()->lockForUpdate()->where('provider_reference', $event->providerReference)->first();

        if ($payment === null || $payment->status !== PaymentStatus::Pending) {
            return 'ignored';
        }

        $payment->forceFill(['status' => PaymentStatus::Failed, 'failure_code' => $event->failureCode ?? 'declined'])->save();
        // The order stays open until its stock hold runs out, so the customer can try another card.
        $payment->order->record('payment_failed', 'system', null, ['code' => $payment->failure_code]);

        return 'payment_failed';
    }

    private function refundSettled(GatewayEvent $event, RefundStatus $status): string
    {
        $refund = Refund::query()->lockForUpdate()->where('provider_reference', $event->providerReference)->first();

        if ($refund === null) {
            return 'unknown_refund';
        }

        $this->refunds->settle($refund, $status);

        return 'refund_'.$status->value;
    }
}
