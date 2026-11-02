<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Finance\CommissionCalculator;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Read-only settlement preview. Order-wide refunds cannot safely be
 * attributed to one seller in a multi-seller marketplace.
 */
final class SellerSettlementPreviewController extends Controller
{
    public function __invoke(Request $request, CommissionCalculator $calculator): JsonResponse
    {
        $input = $request->validate([
            'seller_id' => ['required', 'integer', 'min:1'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $sellerId = (int) $input['seller_id'];
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

        $rows = DB::table('suborders')
            ->join('orders', 'orders.id', '=', 'suborders.order_id')
            ->where('suborders.seller_id', $sellerId)
            ->where('suborders.status', 'delivered')
            ->where('orders.currency', 'SAR')
            ->whereDate('orders.placed_at', '>=', $input['from'])
            ->whereDate('orders.placed_at', '<=', $input['to'])
            ->select('suborders.subtotal_halalas', 'suborders.discount_halalas', 'orders.id as order_id', 'orders.placed_at')
            ->orderBy('orders.placed_at')
            ->limit(5001)->get();

        abort_if($rows->count() > 5000, 422, 'Select a shorter period.');

        $rates = DB::table('seller_commission_rates')
            ->where('seller_id', $sellerId)
            ->whereNotNull('approved_at')
            ->where('approved_at', '<=', now())
            ->orderBy('effective_from')
            ->get(['rate_basis_points', 'effective_from', 'approved_at']);

        $gross = 0;
        $commission = 0;
        $missingRates = 0;

        foreach ($rows as $row) {
            $amount = (int) $row->subtotal_halalas - (int) $row->discount_halalas;
            abort_if($amount < 0, 409, 'Invalid merchandise amount.');
            $gross += $amount;
            $rate = $rates->filter(static fn ($r) => $r->effective_from <= $row->placed_at && $r->approved_at <= $row->placed_at)->last();
            if ($rate === null) {
                ++$missingRates;
                continue;
            }
            $commission += $calculator->calculate($amount, (int) $rate->rate_basis_points);
        }

        $orderIds = $rows->pluck('order_id')->unique()->values()->all();
        $refunds = DB::table('refunds')
            ->whereIn('order_id', $orderIds)
            ->whereIn('status', ['pending', 'succeeded'])
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')->pluck('total', 'status');

        return response()->json(['data' => [
            'seller_id' => $sellerId,
            'currency' => 'SAR',
            'delivered_merchandise_halalas' => $gross,
            'estimated_commission_halalas' => $missingRates === 0 ? $commission : null,
            'orders_without_approved_rate' => $missingRates,
            'order_level_refunds_pending' => (int) ($refunds['pending'] ?? 0),
            'order_level_refunds_succeeded' => (int) ($refunds['succeeded'] ?? 0),
            'seller_refund_allocation_available' => false,
            'payable_halalas' => null,
            'can_approve_or_transfer' => false,
        ]]);
    }
}
