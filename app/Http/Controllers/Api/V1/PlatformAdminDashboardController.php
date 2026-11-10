<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PlatformAdminDashboardController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $access = DB::table('platform_admin_access')
            ->where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->where('can_view_orders', true)->orWhere('can_view_inventory', true);
            })->get();

        abort_unless($request->user()->is_active && $access->isNotEmpty(), 403);

        $sellerIds = $access->pluck('seller_id')->unique()->all();
        $ordersAllowed = $access->where('can_view_orders', true)->pluck('seller_id')->unique()->all();
        $inventoryAllowed = $access->where('can_view_inventory', true)->pluck('seller_id')->unique()->all();

        $suborders = DB::table('suborders')->whereIn('seller_id', $ordersAllowed);
        $statusCounts = (clone $suborders)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');

        $inventory = DB::table('branch_stock')
            ->join('branches', 'branches.id', '=', 'branch_stock.branch_id')
            ->whereIn('branches.seller_id', $inventoryAllowed);

        $lowStock = (clone $inventory)->whereRaw('(branch_stock.on_hand - branch_stock.reserved) <= ?', [5])->count();

        return response()->json(['data' => [
            'assigned_sellers' => count($sellerIds),
            'order_access_sellers' => count($ordersAllowed),
            'inventory_access_sellers' => count($inventoryAllowed),
            'suborders' => (clone $suborders)->count(),
            'suborder_statuses' => $statusCounts,
            'stock_records' => (clone $inventory)->count(),
            'low_stock_records' => $lowStock,
            'generated_at' => now()->toIso8601String(),
        ]]);
    }
}
