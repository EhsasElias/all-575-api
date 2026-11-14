<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PlatformAdminDashboardController extends Controller
{
    public function orders(Request $request): JsonResponse
    {
        $input = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,50'],
            'status' => ['sometimes', 'string', 'in:awaiting_seller,preparing,ready,on_the_way,delivered,cancelled'],
            'before_id' => ['sometimes', 'ulid'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'seller_id' => ['sometimes', 'integer', 'min:1'],
            'branch_id' => ['sometimes', 'integer', 'min:1'],
            'q' => ['sometimes', 'string', 'max:100'],
        ]);

        $sellerIds = DB::table('platform_admin_access')
            ->where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->where('can_view_orders', true)
            ->pluck('seller_id')->all();

        abort_unless($request->user()->is_active && $sellerIds !== [], 403);

        $rows = DB::table('suborders')
            ->join('orders', 'orders.id', '=', 'suborders.order_id')
            ->join('sellers', 'sellers.id', '=', 'suborders.seller_id')
            ->whereIn('suborders.seller_id', $sellerIds)
            ->when(isset($input['seller_id']), fn ($q) => $q->where('suborders.seller_id', $input['seller_id']))
            ->when(isset($input['branch_id']), fn ($q) => $q->where('suborders.branch_id', $input['branch_id']))
            ->when(isset($input['from']), fn ($q) => $q->whereDate('orders.placed_at', '>=', $input['from']))
            ->when(isset($input['to']), fn ($q) => $q->whereDate('orders.placed_at', '<=', $input['to']))
            ->when(isset($input['q']), function ($q) use ($input): void {
                $value = '%' . addcslashes(trim($input['q']), '%_\\\\') . '%';
                $q->where(function ($where) use ($value): void {
                    $where->where('orders.number', 'like', $value)
                        ->orWhere('sellers.name_ar', 'like', $value)
                        ->orWhere('sellers.name_en', 'like', $value);
                });
            })
            ->whereIn('orders.status', ['confirmed', 'completed'])
            ->when(isset($input['status']), fn ($q) => $q->where('suborders.status', $input['status']))
            ->when(isset($input['before_id']), fn ($q) => $q->where('suborders.id', '<', $input['before_id']))
            ->select('suborders.id', 'orders.number as order_number',
                'suborders.seller_id', 'sellers.name_ar as seller_name_ar',
                'sellers.name_en as seller_name_en',
                'suborders.branch_id', 'suborders.status',
                'orders.placed_at', 'suborders.ready_at', 'suborders.picked_up_at')
            ->orderByDesc('suborders.id')
            ->limit($input['limit'] ?? 20)->get();

        return response()->json(['data' => $rows]);
    }

    public function inventory(Request $request): JsonResponse
    {
        $input = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'before_id' => ['sometimes', 'integer', 'min:1'],
            'seller_id' => ['sometimes', 'integer', 'min:1'],
            'branch_id' => ['sometimes', 'integer', 'min:1'],
            'low_stock_only' => ['sometimes', 'boolean'],
        ]);

        $sellerIds = DB::table('platform_admin_access')
            ->where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->where('can_view_inventory', true)
            ->pluck('seller_id')->all();
        abort_unless($request->user()->is_active && $sellerIds !== [], 403);

        $records = DB::table('branch_stock')
            ->join('branches', 'branches.id', '=', 'branch_stock.branch_id')
            ->whereIn('branches.seller_id', $sellerIds)
            ->when(isset($input['seller_id']), fn ($q) => $q->where('branches.seller_id', $input['seller_id']))
            ->when(isset($input['branch_id']), fn ($q) => $q->where('branch_stock.branch_id', $input['branch_id']))
            ->when(!empty($input['low_stock_only']), fn ($q) =>
                $q->whereRaw('(branch_stock.on_hand - branch_stock.reserved) <= ?', [5]))
            ->when(isset($input['before_id']), fn ($q) => $q->where('branch_stock.id', '<', $input['before_id']))
            ->select('branch_stock.id', 'branches.seller_id', 'branch_stock.branch_id',
                'branch_stock.on_hand', 'branch_stock.reserved')
            ->orderByDesc('branch_stock.id')
            ->limit($input['limit'] ?? 30)->get();

        return response()->json(['data' => $records]);
    }

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
