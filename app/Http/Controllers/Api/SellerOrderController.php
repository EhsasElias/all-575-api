<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;

class SellerOrderController extends Controller
{
    public function index(Request $request)
    {
        $seller = $request->attributes->get(
            'seller'
        );

        $orders = Order::query()
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
                            'seller_commission_percentage',
                            'seller_amount',
                        ]);
                },
            ])

            ->latest('id')
            ->paginate(20);

        $orders
            ->getCollection()
            ->transform(function ($order) {
                $order->seller_subtotal =
                    round(
                        (float)
                        $order
                            ->items
                            ->sum('line_total'),
                        2
                    );

                $order->seller_earnings =
                    round(
                        (float)
                        $order
                            ->items
                            ->sum('seller_amount'),
                        2
                    );

                    $order->makeHidden([
    'user_id',
    'address_id',
    'subtotal',
    'delivery_fee',
    'total',
]);
                return $order;
            });

        return response()->json(
            $orders
        );
    }

    public function show(
        Request $request,
        Order $order
    ) {
        $seller = $request->attributes->get(
            'seller'
        );

        $order = Order::query()
            ->where('id', $order->id)

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
                    $query->where(
                        'seller_id',
                        $seller->id
                    );
                },

                'statusHistory',
            ])

            ->firstOrFail();

        $order->seller_subtotal =
            round(
                (float)
                $order
                    ->items
                    ->sum('line_total'),
                2
            );

        $order->seller_earnings =
            round(
                (float)
                $order
                    ->items
                    ->sum('seller_amount'),
                2
            );
$order->makeHidden([
    'user_id',
    'address_id',
    'subtotal',
    'delivery_fee',
    'total',
]);
        return response()->json([
            'data' => $order,
        ]);
    }
}
