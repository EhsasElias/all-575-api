<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use InvalidArgumentException;

/**
 * Integer-only validation for allocating a refund among seller suborders.
 * No persistence, provider call, or approval is performed here.
 */
final class RefundAllocationGuard
{
    /**
     * @param list<int> $amountsHalalas
     */
    public function validate(int $refundHalalas, array $amountsHalalas): void
    {
        if ($refundHalalas <= 0 || $amountsHalalas === []) {
            throw new InvalidArgumentException('Refund and allocations must be positive.');
        }

        $remaining = $refundHalalas;

        foreach ($amountsHalalas as $amount) {
            if (! is_int($amount) || $amount <= 0 || $amount > $remaining) {
                throw new InvalidArgumentException('Allocation exceeds available refund amount.');
            }

            $remaining -= $amount;
        }
    }
}
