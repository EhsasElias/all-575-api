<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/** Kept distinct on purpose: zero-rated and exempt both charge 0% but are reported differently. */
enum VatClass: string
{
    case Standard = 'standard';
    case Zero = 'zero';
    case Exempt = 'exempt';
}
