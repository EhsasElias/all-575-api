<?php

declare(strict_types=1);

namespace App\Domain\Payments\Gateways;

use App\Support\Money;

final readonly class GatewayEvent
{
    public const CHARGE_SUCCEEDED = 'charge.succeeded';

    public const CHARGE_FAILED = 'charge.failed';

    public const REFUND_SUCCEEDED = 'refund.succeeded';

    public const REFUND_FAILED = 'refund.failed';

    /** @param  array<string, mixed>  $payload  the verified callback body, stored for audit */
    public function __construct(
        public string $eventId,
        public string $type,
        public string $providerReference,
        public ?Money $amount,
        public ?string $failureCode,
        public array $payload,
    ) {}
}
