<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SellerFinanceController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $data = $request->validate([
            'seller_id' => ['required', 'integer', 'min:1'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $sellerId = (int) $data['seller_id'];
        abort_unless(DB::table('seller_staff_access')
            ->where('seller_id', $sellerId)
            ->where('user_id', $request->user()->id)
            ->where('role', 'owner')
            ->where('is_active', true)
            ->whereNull('branch_id')
            ->exists(), 404);

        $totals = DB::table('suborders')
            ->join('orders', 'orders.id', '=', 'suborders.order_id')
            ->where('suborders.seller_id', $sellerId)
            ->where('suborders.status', 'delivered')
            ->where('orders.currency', 'SAR')
            ->whereDate('orders.placed_at', '>=', $data['from'])
            ->whereDate('orders.placed_at', '<=', $data['to'])
            ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(suborders.subtotal_halalas), 0) as subtotal, COALESCE(SUM(suborders.discount_halalas), 0) as discount')
            ->first();

        return response()->json(['data' => [
            'seller_id' => $sellerId,
            'currency' => 'SAR',
            'delivered_orders' => (int) $totals->order_count,
            'subtotal_halalas' => (int) $totals->subtotal,
            'discount_halalas' => (int) $totals->discount,
            'merchandise_total_halalas' => (int) $totals->subtotal - (int) $totals->discount,
            'payout_calculated' => false,
        ]]);
    }
}
