<?php

declare(strict_types=1);

namespace App\Domain\Payments\Contracts;

use App\Domain\Payments\Gateways\ChargeSession;
use App\Domain\Payments\Gateways\GatewayEvent;
use App\Domain\Payments\Gateways\RefundResult;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;

/**
 * The only door to a payment provider. Card data never touches our servers: the app talks to the
 * provider's hosted fields or SDK, and we only create the charge and confirm it from a signed callback.
 */
interface PaymentGateway
{
    public function name(): string;

    public function createCharge(Payment $payment): ChargeSession;

    /**
     * Verifies the provider's signature and turns the callback into an event.
     *
     * @param  array<string, list<string|null>>  $headers
     *
     * @throws \App\Domain\Payments\Gateways\InvalidWebhook when the signature or body is not acceptable
     */
    public function parseWebhook(string $rawBody, array $headers): GatewayEvent;

    public function refund(Refund $refund, Payment $payment): RefundResult;
}
