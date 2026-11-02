<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Internal approval state machine. The caller MUST authorize finance approvers
 * before invoking either method. This service does not move money.
 */
final class TwoPersonApproval
{
    public function request(string $operation, string $reference, int $approverId): int
    {
        if ($operation === '' || $reference === '' || $approverId < 1 ||
            strlen($operation) > 60 || strlen($reference) > 100) {
            throw ValidationException::withMessages(['approval' => 'Invalid approval request.']);
        }

        $this->requireGrant($approverId, 'first');

        return DB::transaction(function () use ($operation, $reference, $approverId): int {
            if (DB::table('finance_approval_requests')
                ->where('operation', $operation)->where('reference', $reference)->exists()) {
                throw ValidationException::withMessages(['approval' => 'Request already exists.']);
            }

            return DB::table('finance_approval_requests')->insertGetId([
                'operation' => $operation,
                'reference' => $reference,
                'status' => 'pending_second_approval',
                'first_approver_id' => $approverId,
                'first_approved_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 3);
    }

    public function approve(int $requestId, int $secondApproverId): void
    {
        if ($requestId < 1 || $secondApproverId < 1) {
            throw ValidationException::withMessages(['approval' => 'Invalid approval identifiers.']);
        }

        $this->requireGrant($secondApproverId, 'second');

        DB::transaction(function () use ($requestId, $secondApproverId): void {
            $approval = DB::table('finance_approval_requests')
                ->where('id', $requestId)->lockForUpdate()->first();

            if ($approval === null || $approval->status !== 'pending_second_approval') {
                throw ValidationException::withMessages(['approval' => 'Approval is unavailable or completed.']);
            }

            if ((int) $approval->first_approver_id === $secondApproverId) {
                throw ValidationException::withMessages(['approval' => 'A different approver is required.']);
            }

            DB::table('finance_approval_requests')->where('id', $requestId)->update([
                'status' => 'approved',
                'second_approver_id' => $secondApproverId,
                'second_approved_at' => now(),
                'updated_at' => now(),
            ]);
        }, 3);
    }
    public function reject(int $requestId, int $reviewerId, string $reason): void
    {
        $reason = trim($reason);
        if ($requestId < 1 || $reviewerId < 1 || mb_strlen($reason) < 10 || mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['reason' => 'Provide a rejection reason of 10 to 2000 characters.']);
        }

        $this->requireGrant($reviewerId, 'second');

        DB::transaction(function () use ($requestId, $reviewerId, $reason): void {
            $approval = DB::table('finance_approval_requests')
                ->where('id', $requestId)->lockForUpdate()->first();

            if ($approval === null || $approval->status !== 'pending_second_approval') {
                throw ValidationException::withMessages(['approval' => 'Request is unavailable or already closed.']);
            }

            if ((int) $approval->first_approver_id === $reviewerId) {
                throw ValidationException::withMessages(['approval' => 'A different reviewer is required.']);
            }

            DB::table('finance_approval_requests')->where('id', $requestId)->update([
                'status' => 'rejected',
                'rejected_by' => $reviewerId,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
                'updated_at' => now(),
            ]);
        }, 3);
    }

    private function requireGrant(int $userId, string $role): void
    {
        abort_unless(DB::table('finance_approver_grants')
            ->where('user_id', $userId)
            ->where('role', $role)
            ->where('is_active', true)
            ->exists(), 403, 'Finance approval permission is required.');
    }
}
