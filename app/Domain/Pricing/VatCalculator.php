<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Support\Money;

final class VatCalculator
{
    public function __construct(private readonly int $standardRateBasisPoints) {}

    public function rateFor(VatClass $class): int
    {
        return match ($class) {
            VatClass::Standard => $this->standardRateBasisPoints,
            VatClass::Zero, VatClass::Exempt => 0,
        };
    }

    /** The price already contains VAT: 5.75 at 15% is 5.00 net + 0.75 VAT. */
    public function splitGross(Money $gross, int $rateBasisPoints): VatSplit
    {
        $net = Money::halalas(intdiv(
            $gross->halalas * 10_000 * 2 + (10_000 + $rateBasisPoints),
            2 * (10_000 + $rateBasisPoints),
        ));

        return new VatSplit($net, $gross->minus($net), $gross);
    }

    /** The price is before VAT: 100.00 at 15% is 100.00 net + 15.00 VAT = 115.00. */
    public function addToNet(Money $net, int $rateBasisPoints): VatSplit
    {
        $vat = $net->basisPoints($rateBasisPoints);

        return new VatSplit($net, $vat, $net->plus($vat));
    }
}
