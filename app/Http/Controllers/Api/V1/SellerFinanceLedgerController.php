<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SellerFinanceLedgerController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'seller_id' => ['required', 'integer', 'min:1'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'limit' => ['sometimes', 'integer', 'between:1,50'],
            'before_id' => ['sometimes', 'ulid'],
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

        $rows = DB::table('suborders')
            ->join('orders', 'orders.id', '=', 'suborders.order_id')
            ->where('suborders.seller_id', $sellerId)
            ->where('suborders.status', 'delivered')
            ->where('orders.currency', 'SAR')
            ->whereDate('orders.placed_at', '>=', $data['from'])
            ->whereDate('orders.placed_at', '<=', $data['to'])
            ->when(isset($data['before_id']), fn ($q) => $q->where('suborders.id', '<', $data['before_id']))
            ->select('suborders.id', 'suborders.branch_id', 'suborders.subtotal_halalas',
                'suborders.discount_halalas', 'orders.number as order_number', 'orders.placed_at')
            ->orderByDesc('suborders.id')
            ->limit($data['limit'] ?? 20)
            ->get();

        return response()->json([
            'data' => $rows->map(static fn ($row): array => [
                'suborder_id' => $row->id,
                'order_number' => $row->order_number,
                'branch_id' => $row->branch_id,
                'placed_at' => $row->placed_at,
                'currency' => 'SAR',
                'merchandise_halalas' => (int) $row->subtotal_halalas - (int) $row->discount_halalas,
            ])->all(),
            'payout_calculated' => false,
        ]);
    }
}
