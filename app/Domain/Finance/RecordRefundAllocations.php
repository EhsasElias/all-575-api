<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Internal finance operation. Call only after independent authorization;
 * no public seller route may invoke this service.
 */
final class RecordRefundAllocations
{
    public function __construct(private readonly RefundAllocationValidator $validator) {}

    /**
     * @param list<array{suborder_id: string, seller_id: int, amount_halalas: int}> $allocations
     */
    public function record(string $refundId, array $allocations, int $approverUserId): int
    {
        if ($approverUserId < 1 || $allocations === []) {
            throw new InvalidArgumentException('Approver and allocations are required.');
        }

        return DB::transaction(function () use ($refundId, $allocations, $approverUserId): int {
            // Validation locks the refund row before reading current allocations.
            $this->validator->validate($refundId, $allocations);

            $now = now();
            foreach ($allocations as $allocation) {
                DB::table('seller_refund_allocations')->insert([
                    'refund_id' => $refundId,
                    'suborder_id' => $allocation['suborder_id'],
                    'seller_id' => $allocation['seller_id'],
                    'amount_halalas' => $allocation['amount_halalas'],
                    'approved_by' => $approverUserId,
                    'approved_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return count($allocations);
        }, 3);
    }
}
