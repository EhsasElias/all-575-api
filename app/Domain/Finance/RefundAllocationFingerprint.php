<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use InvalidArgumentException;

final class RefundAllocationFingerprint
{
    public function hash(string $refundId, array $allocations): string
    {
        if ($refundId === '' || $allocations === []) {
            throw new InvalidArgumentException('Refund and allocations are required.');
        }

        $rows = [];
        foreach ($allocations as $allocation) {
            if (! isset($allocation['suborder_id'], $allocation['seller_id'], $allocation['amount_halalas'])
                || ! is_string($allocation['suborder_id'])
                || trim($allocation['suborder_id']) === ''
                || ! is_int($allocation['seller_id'])
                || ! is_int($allocation['amount_halalas'])
                || $allocation['seller_id'] <= 0 || $allocation['amount_halalas'] <= 0) {
                throw new InvalidArgumentException('Invalid allocation data.');
            }
            $rows[] = [
                'suborder_id' => $allocation['suborder_id'],
                'seller_id' => $allocation['seller_id'],
                'amount_halalas' => $allocation['amount_halalas'],
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['suborder_id'], $b['suborder_id']));

        return hash('sha256', json_encode(['refund_id' => $refundId, 'allocations' => $rows], JSON_THROW_ON_ERROR));
    }
}
