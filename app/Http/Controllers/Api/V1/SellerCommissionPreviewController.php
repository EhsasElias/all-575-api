<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Finance\CommissionCalculator;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SellerCommissionPreviewController extends Controller
{
    public function __invoke(Request $request, CommissionCalculator $calculator): JsonResponse
    {
        $data = $request->validate([
            'seller_id' => ['required', 'integer', 'min:1'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $sellerId = (int) $data['seller_id'];
        $authorized = DB::table('seller_staff_access')
            ->join('sellers', 'sellers.id', '=', 'seller_staff_access.seller_id')
            ->where('seller_staff_access.seller_id', $sellerId)
            ->where('seller_staff_access.user_id', $request->user()->id)
            ->where('seller_staff_access.role', 'owner')
            ->where('seller_staff_access.is_active', true)
            ->whereNull('seller_staff_access.branch_id')
            ->where('sellers.status', 'approved')
            ->exists();
        abort_unless($authorized, 404);

        $orders = DB::table('suborders')
            ->join('orders', 'orders.id', '=', 'suborders.order_id')
            ->where('suborders.seller_id', $sellerId)
            ->where('suborders.status', 'delivered')
            ->where('orders.currency', 'SAR')
            ->whereDate('orders.placed_at', '>=', $data['from'])
            ->whereDate('orders.placed_at', '<=', $data['to'])
            ->select('suborders.subtotal_halalas', 'suborders.discount_halalas', 'orders.placed_at')
            ->orderBy('orders.placed_at')
            ->limit(5001)
            ->get();

        abort_if($orders->count() > 5000, 422, 'Please select a shorter reporting period.');

        $rates = DB::table('seller_commission_rates')
            ->where('seller_id', $sellerId)
            ->whereNotNull('approved_at')
            ->where('approved_at', '<=', now())
            ->where('effective_from', '<=', now())
            ->orderBy('effective_from')
            ->get(['rate_basis_points', 'effective_from', 'approved_at']);

        $commission = 0;
        $merchandise = 0;
        $unrated = 0;

        foreach ($orders as $order) {
            $amount = (int) $order->subtotal_halalas - (int) $order->discount_halalas;
            abort_if($amount < 0, 409, 'Invalid order amount.');
            $eligible = $rates->filter(static fn ($rate) => $rate->effective_from <= $order->placed_at
                && $rate->approved_at <= $order->placed_at)->last();

            if ($eligible === null) {
                ++$unrated;

                continue;
            }

            $merchandise += $amount;
            $commission += $calculator->calculate($amount, (int) $eligible->rate_basis_points);
        }

        return response()->json(['data' => [
            'seller_id' => $sellerId,
            'currency' => 'SAR',
            'covered_merchandise_halalas' => $merchandise,
            'illustrative_commission_halalas' => $unrated === 0 ? $commission : null,
            'orders_without_approved_rate' => $unrated,
            'payout_calculated' => false,
            'settlement_approved' => false,
        ]]);
    }
}
