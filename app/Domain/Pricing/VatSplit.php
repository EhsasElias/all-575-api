<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Support\Money;

final readonly class VatSplit
{
    public function __construct(
        public Money $net,
        public Money $vat,
        public Money $gross,
    ) {}
}
