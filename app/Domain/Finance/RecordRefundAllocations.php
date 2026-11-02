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
    public function __construct(private readonly RefundAllocationValidator $validator, private readonly RefundAllocationFingerprint $fingerprint) {}

    /**
     * @param list<array{suborder_id: string, seller_id: int, amount_halalas: int}> $allocations
     */
    public function record(string $refundId, array $allocations, int $approverUserId): int
    {
        if ($approverUserId < 1 || $allocations === []) {
            throw new InvalidArgumentException('Approver and allocations are required.');
        }

        return DB::transaction(function () use ($refundId, $allocations, $approverUserId): int {
            $approval = DB::table('finance_approval_requests')
                ->where('operation', 'refund_allocation')
                ->where('reference', $refundId)
                ->lockForUpdate()->first();

            $authorized = DB::table('finance_approver_grants')
                ->where('user_id', $approverUserId)
                ->where('role', 'second')
                ->where('is_active', true)
                ->exists();
            abort_unless($authorized, 403, 'Finance execution permission is required.');

            $digest = $this->fingerprint->hash($refundId, $allocations);
            if ($approval === null || $approval->status !== 'approved'
                || $approval->payload_hash === null || ! hash_equals($approval->payload_hash, $digest)
                || (int) $approval->second_approver_id !== $approverUserId) {
                throw new InvalidArgumentException('Matching two-person approval is required.');
            }

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

            DB::table('finance_approval_requests')->where('id', $approval->id)->update([
                'status' => 'executed', 'updated_at' => now(),
            ]);

            DB::table('finance_audit_events')->insert([
                'approval_request_id' => $approval->id,
                'actor_user_id' => $approverUserId,
                'action' => 'refund_allocation_recorded',
                'payload_hash' => $digest,
                'occurred_at' => now(),
            ]);

            return count($allocations);
        }, 3);
    }
}
