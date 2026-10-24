<?php

declare(strict_types=1);

namespace App\Domain\Payments\Services;

use App\Domain\Orders\Models\Order;
use App\Domain\Payments\Contracts\PaymentGateway;
use App\Domain\Payments\Enums\RefundStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Support\Money;

final class Refunds
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /** Records the intent to refund. Sending it to the provider is a separate step, done after the database commit. */
    public function request(Order $order, Payment $payment, Money $amount, string $reason): Refund
    {
        return Refund::query()->create([
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'status' => RefundStatus::Pending,
            'amount_halalas' => $amount->halalas,
            'reason' => $reason,
            'requested_at' => now(),
        ]);
    }

    /** A refund counts as done only when the provider confirms it, never when it is merely requested. */
    public function send(Refund $refund): void
    {
        $result = $this->gateway->refund($refund, $refund->payment);

        $refund->forceFill(['provider_reference' => $result->providerReference])->save();

        if ($result->status !== RefundStatus::Pending) {
            $this->settle($refund, $result->status);
        }
    }

    public function settle(Refund $refund, RefundStatus $status): void
    {
        if ($refund->status !== RefundStatus::Pending) {
            return;
        }

        $refund->forceFill([
            'status' => $status,
            'completed_at' => now(),
            'credit_note_number' => $status === RefundStatus::Succeeded ? $this->nextCreditNoteNumber() : null,
        ])->save();

        $refund->order->record($status === RefundStatus::Succeeded ? 'refund_succeeded' : 'refund_failed', 'system', null, [
            'amount' => $refund->amount_halalas,
        ]);
    }

    private function nextCreditNoteNumber(): string
    {
        $next = Refund::query()->whereNotNull('credit_note_number')->count() + 1;

        return 'CN-'.now()->format('Y').'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
