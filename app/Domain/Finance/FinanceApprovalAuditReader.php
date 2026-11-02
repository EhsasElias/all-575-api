<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

/**
 * Internal read-only audit access; only active finance reviewers may inspect.
 * Does not expose customer data, payment credentials, or fund movements.
 */
final class FinanceApprovalAuditReader
{
    public function forRequest(int $requestId, int $viewerUserId): Collection
    {
        abort_unless($requestId > 0 && $viewerUserId > 0, 404);

        $authorized = DB::table('finance_approver_grants')
            ->where('user_id', $viewerUserId)
            ->where('is_active', true)
            ->whereIn('role', ['first', 'second'])
            ->exists();
        abort_unless($authorized, 403, 'Finance audit access is required.');

        $exists = DB::table('finance_approval_requests')
            ->where('id', $requestId)->exists();
        abort_unless($exists, 404);

        return DB::table('finance_audit_events')
            ->where('approval_request_id', $requestId)
            ->select('id', 'action', 'actor_user_id', 'payload_hash', 'occurred_at')
            ->orderBy('id')
            ->limit(200)
            ->get();
    }
}
