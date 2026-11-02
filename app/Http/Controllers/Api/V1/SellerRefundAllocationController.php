<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SellerRefundAllocationController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'seller_id' => ['required', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'between:1,50'],
        ]);
        $sellerId = (int) $data['seller_id'];

        $allowed = DB::table('seller_staff_access')
            ->join('sellers', 'sellers.id', '=', 'seller_staff_access.seller_id')
            ->where('seller_staff_access.seller_id', $sellerId)
            ->where('seller_staff_access.user_id', $request->user()->id)
            ->where('seller_staff_access.role', 'owner')
            ->where('seller_staff_access.is_active', true)
            ->whereNull('seller_staff_access.branch_id')
            ->where('sellers.status', 'approved')->exists();
        abort_unless($allowed, 404);

        $items = DB::table('seller_refund_allocations')
            ->join('suborders', 'suborders.id', '=', 'seller_refund_allocations.suborder_id')
            ->join('refunds', 'refunds.id', '=', 'seller_refund_allocations.refund_id')
            ->where('seller_refund_allocations.seller_id', $sellerId)
            ->where('suborders.seller_id', $sellerId)
            ->whereColumn('suborders.order_id', 'refunds.order_id')
            ->select('seller_refund_allocations.id', 'seller_refund_allocations.refund_id',
                'seller_refund_allocations.suborder_id', 'seller_refund_allocations.amount_halalas',
                'seller_refund_allocations.approved_at', 'refunds.status as refund_status')
            ->orderByDesc('seller_refund_allocations.id')
            ->limit($data['limit'] ?? 20)->get();

        return response()->json(['data' => $items, 'currency' => 'SAR', 'payout_calculated' => false]);
    }
}
