<?php

declare(strict_types=1);

namespace App\Domain\Orders\Actions;

use App\Domain\Orders\Enums\OrderStatus;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\StockReservation;
use App\Domain\Payments\Contracts\PaymentGateway;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Gateways\ChargeSession;
use App\Domain\Payments\Models\Payment;
use App\Support\Exceptions\DomainRuleViolation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** A new payment attempt for an unpaid order whose stock is still held (declined card, lost response, closed app). */
final class RetryPayment
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /** @return array{0: Payment, 1: ChargeSession} */
    public function handle(Order $order, PaymentMethod $method): array
    {
        if ($method === PaymentMethod::CashOnDelivery) {
            throw new DomainRuleViolation('order_not_payable', 'Place a new cash order after cancelling the unpaid order.', 409);
        }

        $payment = DB::transaction(function () use ($order, $method): Payment {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->status !== OrderStatus::PendingPayment) {
                throw new DomainRuleViolation('order_not_payable', 'This order cannot be paid any more.', 409);
            }

            $heldUntil = $locked->reservations()->where('status', StockReservation::HELD)->max('expires_at');

            if ($heldUntil === null || now()->gte($heldUntil)) {
                throw new DomainRuleViolation('payment_window_closed', 'The time to pay for this order has run out.', 409);
            }

            $locked->payments()->where('status', PaymentStatus::Pending->value)->update(['status' => PaymentStatus::Expired->value]);

            return $locked->payments()->create([
                'provider' => $this->gateway->name(), 'method' => $method, 'status' => PaymentStatus::Pending,
                'amount_halalas' => $locked->total_halalas, 'currency' => $locked->currency,
                'expires_at' => Carbon::parse($heldUntil),
            ]);
        });

        $session = $this->gateway->createCharge($payment);
        $payment->forceFill(['provider_reference' => $session->providerReference])->save();

        return [$payment, $session];
    }
}
