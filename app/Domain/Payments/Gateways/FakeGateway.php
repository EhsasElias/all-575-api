<?php

declare(strict_types=1);

namespace App\Domain\Payments\Gateways;

use App\Domain\Payments\Contracts\PaymentGateway;
use App\Domain\Payments\Enums\RefundStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Support\Money;
use Illuminate\Support\Str;

/**
 * A stand-in provider for local development and tests. Callbacks are signed with an HMAC so the real
 * verification path (reject unsigned or tampered bodies) is exercised, exactly as a real provider would be.
 */
final class FakeGateway implements PaymentGateway
{
    public const SIGNATURE_HEADER = 'x-fake-signature';

    public function __construct(private readonly string $secret) {}

    public function name(): string
    {
        return 'fake';
    }

    public function createCharge(Payment $payment): ChargeSession
    {
        $reference = 'fake_ch_'.Str::lower((string) Str::ulid());

        return new ChargeSession($reference, redirectUrl: null, clientSecret: $reference.'_secret');
    }

    public function parseWebhook(string $rawBody, array $headers): GatewayEvent
    {
        $given = (string) ($headers[self::SIGNATURE_HEADER][0] ?? '');

        if (! hash_equals($this->sign($rawBody), $given)) {
            throw new InvalidWebhook('Signature mismatch.');
        }

        $body = json_decode($rawBody, true);

        if (! is_array($body) || ! isset($body['id'], $body['type'], $body['reference'])) {
            throw new InvalidWebhook('Malformed body.');
        }

        return new GatewayEvent(
            eventId: (string) $body['id'],
            type: (string) $body['type'],
            providerReference: (string) $body['reference'],
            amount: isset($body['amount']) ? Money::halalas((int) $body['amount']) : null,
            failureCode: isset($body['failure_code']) ? (string) $body['failure_code'] : null,
            payload: $body,
        );
    }

    public function refund(Refund $refund, Payment $payment): RefundResult
    {
        return new RefundResult('fake_re_'.Str::lower((string) Str::ulid()), RefundStatus::Pending);
    }

    public function sign(string $rawBody): string
    {
        return hash_hmac('sha256', $rawBody, $this->secret);
    }

    /**
     * Builds a signed callback, the same way the provider would send it.
     *
     * @param  array<string, mixed>  $payload
     * @return array{body: string, headers: array<string, string>}
     */
    public function signedCallback(array $payload): array
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return ['body' => $body, 'headers' => [self::SIGNATURE_HEADER => $this->sign($body)]];
    }
}
