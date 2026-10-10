<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Seller;
use App\Models\SellerApplication;
use App\Models\User;

class AdminDashboardController extends Controller
{
    public function index()
    {
        /*
        |--------------------------------------------------------------------------
        | Users
        |--------------------------------------------------------------------------
        */

        $usersCount = User::query()->count();

        $customersCount = User::query()
            ->where('role', 'customer')
            ->count();

        $sellersUsersCount = User::query()
            ->where('role', 'seller')
            ->count();

        /*
        |--------------------------------------------------------------------------
        | Categories
        |--------------------------------------------------------------------------
        */

        $categoriesCount = Category::query()->count();

        $activeCategoriesCount = Category::query()
            ->where('is_active', true)
            ->count();

        /*
        |--------------------------------------------------------------------------
        | Sellers
        |--------------------------------------------------------------------------
        */

        $sellersCount = Seller::query()->count();

        $activeSellersCount = Seller::query()
            ->where('status', 'approved')
            ->where('is_active', true)
            ->count();

        /*
        |--------------------------------------------------------------------------
        | Seller Applications
        |--------------------------------------------------------------------------
        */

        $pendingSellerApplications = SellerApplication::query()
            ->where('status', 'pending')
            ->count();

        /*
        |--------------------------------------------------------------------------
        | Products
        |--------------------------------------------------------------------------
        */

        $productsCount = Product::query()->count();

        $activeProductsCount = Product::query()
            ->where('is_active', true)
            ->count();

        $outOfStockProductsCount = Product::query()
            ->where('stock', '<=', 0)
            ->count();

        /*
        |--------------------------------------------------------------------------
        | Orders
        |--------------------------------------------------------------------------
        */

        $ordersCount = Order::query()->count();

        $pendingOrdersCount = Order::query()
            ->where('status', 'pending')
            ->count();

        $confirmedOrdersCount = Order::query()
            ->where('status', 'confirmed')
            ->count();

        $preparingOrdersCount = Order::query()
            ->where('status', 'preparing')
            ->count();

        $outForDeliveryOrdersCount = Order::query()
            ->where('status', 'out_for_delivery')
            ->count();

        $deliveredOrdersCount = Order::query()
            ->where('status', 'delivered')
            ->count();

        $cancelledOrdersCount = Order::query()
            ->where('status', 'cancelled')
            ->count();

        /*
        |--------------------------------------------------------------------------
        | Sales
        |--------------------------------------------------------------------------
        */

        $grossSales = (float) Order::query()
            ->where('status', 'delivered')
            ->sum('total');

        $todaySales = (float) Order::query()
            ->where('status', 'delivered')
            ->whereDate('created_at', today())
            ->sum('total');

        $monthSales = (float) Order::query()
            ->where('status', 'delivered')
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->sum('total');

        /*
        |--------------------------------------------------------------------------
        | Seller Earnings / Platform Commission
        |--------------------------------------------------------------------------
        */

        $deliveredItems = OrderItem::query()
            ->whereHas('order', function ($query) {
                $query->where('status', 'delivered');
            });

        $sellerEarnings = (float) (clone $deliveredItems)
            ->sum('seller_amount');

        $deliveredItemsTotal = (float) (clone $deliveredItems)
            ->sum('line_total');

        $platformCommission = round(
            $deliveredItemsTotal - $sellerEarnings,
            2
        );

        /*
        |--------------------------------------------------------------------------
        | Recent Orders
        |--------------------------------------------------------------------------
        */

        $recentOrders = Order::query()
            ->latest('id')
            ->limit(5)
            ->get([
                'id',
                'order_number',
                'customer_name',
                'total',
                'currency',
                'payment_status',
                'status',
                'created_at',
            ]);

        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'data' => [

                'users' => [
                    'total' => $usersCount,
                    'customers' => $customersCount,
                    'sellers' => $sellersUsersCount,
                ],

                'categories' => [
                    'total' => $categoriesCount,
                    'active' => $activeCategoriesCount,
                ],

                'sellers' => [
                    'total' => $sellersCount,
                    'active' => $activeSellersCount,
                    'pending_applications' =>
                        $pendingSellerApplications,
                ],

                'products' => [
                    'total' => $productsCount,
                    'active' => $activeProductsCount,
                    'out_of_stock' =>
                        $outOfStockProductsCount,
                ],

                'orders' => [
                    'total' => $ordersCount,
                    'pending' => $pendingOrdersCount,
                    'confirmed' => $confirmedOrdersCount,
                    'preparing' => $preparingOrdersCount,
                    'out_for_delivery' =>
                        $outForDeliveryOrdersCount,
                    'delivered' => $deliveredOrdersCount,
                    'cancelled' => $cancelledOrdersCount,
                ],

                'sales' => [
                    'gross_sales' =>
                        round($grossSales, 2),

                    'today_sales' =>
                        round($todaySales, 2),

                    'month_sales' =>
                        round($monthSales, 2),

                    'seller_earnings' =>
                        round($sellerEarnings, 2),

                    'platform_commission' =>
                        $platformCommission,

                    'currency' =>
                        'SAR',
                ],

                'recent_orders' =>
                    $recentOrders,
            ],
        ]);
    }
}
