<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\Request;

class SellerDashboardController extends Controller
{
    public function profile(Request $request)
    {
        $seller = $request->attributes->get(
            'seller'
        );

        return response()->json([
            'data' => $seller,
        ]);
    }

    public function updateProfile(Request $request)
    {
        $seller = $request->attributes->get(
            'seller'
        );

        $validated = $request->validate([
            'name_ar' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],

            'name_en' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],

            'phone' => [
                'nullable',
                'string',
                'max:50',
            ],

            'email' => [
                'nullable',
                'email',
                'max:255',
            ],

            'logo' => [
                'nullable',
                'string',
                'max:2048',
            ],

            'description_ar' => [
                'nullable',
                'string',
            ],

            'description_en' => [
                'nullable',
                'string',
            ],
        ]);

        $seller->update($validated);

        return response()->json([
            'message' =>
                'Seller profile updated successfully.',

            'data' =>
                $seller->fresh(),
        ]);
    }

    public function dashboard(Request $request)
    {
        $seller = $request->attributes->get(
            'seller'
        );

        $productsCount = Product::query()
            ->where('seller_id', $seller->id)
            ->count();

        $activeProducts = Product::query()
            ->where('seller_id', $seller->id)
            ->where('is_active', true)
            ->count();

        $ordersCount = Order::query()
            ->whereHas(
                'items',
                fn ($query) =>
                    $query->where(
                        'seller_id',
                        $seller->id
                    )
            )
            ->count();

        $activeOrdersCount = Order::query()
            ->whereHas(
                'items',
                fn ($query) =>
                    $query->where(
                        'seller_id',
                        $seller->id
                    )
            )
            ->whereIn('status', [
                'pending',
                'confirmed',
                'preparing',
                'out_for_delivery',
            ])
            ->count();

        $deliveredOrdersCount = Order::query()
            ->whereHas(
                'items',
                fn ($query) =>
                    $query->where(
                        'seller_id',
                        $seller->id
                    )
            )
            ->where('status', 'delivered')
            ->count();

        $deliveredItems = OrderItem::query()
            ->where(
                'seller_id',
                $seller->id
            )
            ->whereHas(
                'order',
                fn ($query) =>
                    $query->where(
                        'status',
                        'delivered'
                    )
            );

        $grossSales = (float)
            (clone $deliveredItems)
                ->sum('line_total');

        $earnings = (float)
            (clone $deliveredItems)
                ->sum('seller_amount');

        $platformCommission = round(
            $grossSales - $earnings,
            2
        );

        $recentOrders = Order::query()
            ->whereHas(
                'items',
                fn ($query) =>
                    $query->where(
                        'seller_id',
                        $seller->id
                    )
            )
            ->with([
                'items' => function ($query) use (
                    $seller
                ) {
                    $query
                        ->where(
                            'seller_id',
                            $seller->id
                        )
                        ->select([
                            'id',
                            'order_id',
                            'product_id',
                            'seller_id',
                            'product_name_ar',
                            'product_name_en',
                            'unit_price',
                            'quantity',
                            'line_total',
                            'seller_amount',
                        ]);
                },
            ])
            ->latest('id')
            ->limit(5)
            ->get();

        return response()->json([
            'data' => [
                'seller' => $seller,

                'products_count' =>
                    $productsCount,

                'active_products_count' =>
                    $activeProducts,

                'orders_count' =>
                    $ordersCount,

                'active_orders_count' =>
                    $activeOrdersCount,

                'delivered_orders_count' =>
                    $deliveredOrdersCount,

                'gross_sales' =>
                    round($grossSales, 2),

                'platform_commission' =>
                    $platformCommission,

                'earnings' =>
                    round($earnings, 2),

                'currency' =>
                    'SAR',

                'recent_orders' =>
                    $recentOrders,
            ],
        ]);
    }
}
