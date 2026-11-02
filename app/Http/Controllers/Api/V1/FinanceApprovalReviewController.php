<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Finance\FinanceApprovalAuditReader;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class FinanceApprovalReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'status' => ['sometimes', 'in:pending_second_approval,approved,rejected,executed'],
            'limit' => ['sometimes', 'integer', 'between:1,50'],
        ]);

        $this->authorizeReviewer((int) $request->user()->id);

        $query = DB::table('finance_approval_requests')
            ->select('id', 'operation', 'reference', 'status', 'first_approver_id',
                'second_approver_id', 'first_approved_at', 'second_approved_at',
                'rejected_by', 'rejected_at', 'rejection_reason', 'created_at');

        if (isset($input['status'])) {
            $query->where('status', $input['status']);
        }

        return response()->json(['data' => $query->orderByDesc('id')
            ->limit($input['limit'] ?? 20)->get()]);
    }

    public function audit(Request $request, int $approval, FinanceApprovalAuditReader $reader): JsonResponse
    {
        return response()->json(['data' => $reader->forRequest($approval, (int) $request->user()->id)]);
    }

    private function authorizeReviewer(int $userId): void
    {
        abort_unless(DB::table('finance_approver_grants')
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->whereIn('role', ['first', 'second'])->exists(), 403);
    }
}
