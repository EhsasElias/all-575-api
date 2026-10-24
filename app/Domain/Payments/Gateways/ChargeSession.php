<?php

declare(strict_types=1);

namespace App\Domain\Payments\Gateways;

/** What the app needs to finish paying: a hosted page to open, or a client secret for the provider SDK. */
final readonly class ChargeSession
{
    public function __construct(
        public string $providerReference,
        public ?string $redirectUrl = null,
        public ?string $clientSecret = null,
    ) {}
}
