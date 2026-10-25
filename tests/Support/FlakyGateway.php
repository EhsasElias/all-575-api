<?php

namespace Tests\Support;

use App\Domain\Payments\Contracts\PaymentGateway;
use App\Domain\Payments\Gateways\ChargeSession;
use App\Domain\Payments\Gateways\FakeGateway;
use App\Domain\Payments\Gateways\GatewayEvent;
use App\Domain\Payments\Gateways\RefundResult;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use RuntimeException;

/** The fake provider, except that its refund endpoint can be switched off to play an outage. */
final class FlakyGateway implements PaymentGateway
{
    public bool $refundsDown = false;

    public int $refundCalls = 0;

    public function __construct(private readonly FakeGateway $real) {}

    public function name(): string
    {
        return $this->real->name();
    }

    public function createCharge(Payment $payment): ChargeSession
    {
        return $this->real->createCharge($payment);
    }

    public function parseWebhook(string $rawBody, array $headers): GatewayEvent
    {
        return $this->real->parseWebhook($rawBody, $headers);
    }

    public function refund(Refund $refund, Payment $payment): RefundResult
    {
        $this->refundCalls++;

        if ($this->refundsDown) {
            throw new RuntimeException('The payment provider is not answering.');
        }

        return $this->real->refund($refund, $payment);
    }

    /** @param  array<string, mixed>  $payload */
    public function signedCallback(array $payload): array
    {
        return $this->real->signedCallback($payload);
    }
}
