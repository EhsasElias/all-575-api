<?php

declare(strict_types=1);

namespace App\Domain\Payments\Services;

use App\Domain\Orders\Models\Order;
use App\Domain\Payments\Contracts\PaymentGateway;
use App\Domain\Payments\Enums\RefundStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

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

    /**
     * A refund counts as done only when the provider confirms it, never when it is merely requested.
     * Sending twice is harmless: a refund the provider already knows about is left alone.
     */
    public function send(Refund $refund): void
    {
        if ($refund->status !== RefundStatus::Pending || $refund->provider_reference !== null) {
            return;
        }

        $result = $this->gateway->refund($refund, $refund->payment);

        $refund->forceFill(['provider_reference' => $result->providerReference])->save();

        if ($result->status !== RefundStatus::Pending) {
            $this->settle($refund, $result->status);
        }
    }

    /**
     * Like `send`, for code that has already committed its own work and must not fail because the provider is down:
     * the problem is reported, the refund stays pending, and `retryUnsent` sends it again later.
     */
    public function sendSafely(Refund $refund): void
    {
        try {
            $this->send($refund);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Sends the refunds that were recorded but never reached the provider. Returns how many it tried. */
    public function retryUnsent(int $olderThanSeconds = 60): int
    {
        $unsent = Refund::query()
            ->where('status', RefundStatus::Pending->value)
            ->whereNull('provider_reference')
            ->where('requested_at', '<=', now()->subSeconds($olderThanSeconds))
            ->get();

        $unsent->each(fn (Refund $refund) => $this->sendSafely($refund));

        return $unsent->count();
    }

    public function settle(Refund $refund, RefundStatus $status): void
    {
        if ($refund->status !== RefundStatus::Pending) {
            return;
        }

        $this->complete($refund, $status);

        $refund->order->record($status === RefundStatus::Succeeded ? 'refund_succeeded' : 'refund_failed', 'system', null, [
            'amount' => $refund->amount_halalas,
        ]);
    }

    /** Two refunds settling at once can pick the same credit-note number: the unique index refuses one, which picks again. */
    private function complete(Refund $refund, RefundStatus $status): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                DB::transaction(fn () => $refund->forceFill([
                    'status' => $status,
                    'completed_at' => now(),
                    'credit_note_number' => $status === RefundStatus::Succeeded ? $this->nextCreditNoteNumber() : null,
                ])->save());

                return;
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 5) {
                    throw $e;
                }
            }
        }
    }

    /** The next number of this year's series: one more than the highest issued, so a gap or a deleted row never reuses one. */
    private function nextCreditNoteNumber(): string
    {
        $prefix = 'CN-'.now()->format('Y').'-';
        $last = Refund::query()->where('credit_note_number', 'like', $prefix.'%')->max('credit_note_number');
        $next = $last === null ? 1 : (int) substr((string) $last, strlen($prefix)) + 1;

        return $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
