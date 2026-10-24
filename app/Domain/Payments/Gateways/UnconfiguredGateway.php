<?php

declare(strict_types=1);

namespace App\Domain\Payments\Gateways;

use App\Domain\Payments\Contracts\PaymentGateway;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Support\Exceptions\DomainRuleViolation;

/** The default until a payment provider is contracted (roadmap decision D-02). It never pretends to take money. */
final class UnconfiguredGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'unconfigured';
    }

    public function createCharge(Payment $payment): ChargeSession
    {
        throw new DomainRuleViolation('payments_unavailable', 'Online payment is not available right now.', 503);
    }

    public function parseWebhook(string $rawBody, array $headers): GatewayEvent
    {
        throw new InvalidWebhook('No payment provider is configured.');
    }

    public function refund(Refund $refund, Payment $payment): RefundResult
    {
        throw new DomainRuleViolation('payments_unavailable', 'Refunds are not available right now.', 503);
    }
}
