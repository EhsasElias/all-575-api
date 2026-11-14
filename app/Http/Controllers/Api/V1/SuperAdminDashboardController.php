<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SuperAdminDashboardController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->is_active && DB::table('platform_super_admin_grants')
            ->where('user_id', $request->user()->id)
            ->where('is_active', true)->exists(), 403);

        $orderCounts = DB::table('orders')->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')->pluck('total', 'status');
        $suborderCounts = DB::table('suborders')->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')->pluck('total', 'status');

        $sales = DB::table('orders')->whereIn('status', ['confirmed', 'completed'])
            ->whereNotNull('paid_at')
            ->selectRaw('COUNT(*) AS paid_orders, COALESCE(SUM(total_halalas), 0) AS paid_total_halalas')
            ->first();

        return response()->json(['data' => [
            'customers' => DB::table('users')->count(),
            'sellers' => DB::table('sellers')->count(),
            'active_sellers' => DB::table('sellers')->where('status', 'approved')->count(),
            'products' => DB::table('products')->count(),
            'published_products' => DB::table('products')->where('status', 'published')->count(),
            'orders' => DB::table('orders')->count(),
            'order_statuses' => $orderCounts,
            'suborder_statuses' => $suborderCounts,
            'pending_seller_applications' => DB::table('seller_applications')->where('status', 'submitted')->count(),
            'pending_product_reviews' => DB::table('products')->where('status', 'pending_review')->count(),
            'pending_category_requests' => DB::table('seller_category_requests')->where('status', 'pending')->count(),
            'sales' => [
                'paid_orders' => (int) ($sales->paid_orders ?? 0),
                'paid_total_halalas' => (int) ($sales->paid_total_halalas ?? 0),
                'currency' => 'SAR',
            ],
            'generated_at' => now()->toIso8601String(),
        ]]);
    }
}
