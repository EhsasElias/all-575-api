<?php

declare(strict_types=1);

namespace App\Domain\Payments\Gateways;

use App\Domain\Payments\Enums\RefundStatus;

final readonly class RefundResult
{
    public function __construct(public string $providerReference, public RefundStatus $status) {}
}
