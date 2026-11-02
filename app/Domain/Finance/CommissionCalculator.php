<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use InvalidArgumentException;

/**
 * Calculate commission in integer halalas using an explicitly approved rate.
 * Does not calculate payable balances or initiate transfers.
 */
final class CommissionCalculator
{
    public function calculate(int $amountHalalas, int $rateBasisPoints): int
    {
        if ($amountHalalas < 0 || $rateBasisPoints < 0 || $rateBasisPoints > 10000) {
            throw new InvalidArgumentException('Invalid commission inputs.');
        }

        $whole = intdiv($amountHalalas, 10000) * $rateBasisPoints;
        $fraction = intdiv(($amountHalalas % 10000) * $rateBasisPoints + 5000, 10000);

        return $whole + $fraction;
    }
}
