<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Informational payout-readiness checklist. No disbursement or accounting entries.
 */
final class SellerSettlementReadinessController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['seller_id' => ['required', 'integer', 'min:1']]);
        $sellerId = (int) $data['seller_id'];

        $allowed = DB::table('seller_staff_access')
            ->join('sellers', 'sellers.id', '=', 'seller_staff_access.seller_id')
            ->where('seller_staff_access.seller_id', $sellerId)
            ->where('seller_staff_access.user_id', $request->user()->id)
            ->where('seller_staff_access.role', 'owner')
            ->where('seller_staff_access.is_active', true)
            ->whereNull('seller_staff_access.branch_id')
            ->where('sellers.status', 'approved')
            ->exists();
        abort_unless($allowed, 404);

        return response()->json(['data' => [
            'seller_id' => $sellerId,
            'currency' => 'SAR',
            'state' => 'awaiting_finance_configuration',
            'commission_configured' => false,
            'refund_allocation_verified' => false,
            'finance_approval_verified' => false,
            'payable_halalas' => null,
            'transfer_enabled' => false,
        ]]);
    }
}
