<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'address_id' => [
                'required',
                'integer',
                'exists:addresses,id',
            ],
            'customer_notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        $user = $request->user();

        $order = DB::transaction(function () use ($user, $validated) {
            /*
            |--------------------------------------------------------------------------
            | Address
            |--------------------------------------------------------------------------
            */
            $address = Address::query()
                ->where('id', $validated['address_id'])
                ->where('user_id', $user->id)
                ->first();

            if (!$address) {
                throw ValidationException::withMessages([
                    'address_id' => [
                        'The selected address is invalid.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Cart
            |--------------------------------------------------------------------------
            |
            | Lock the cart row before loading its items. This prevents two concurrent
            | checkout requests from creating two orders from the same cart.
            */
            $cart = Cart::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (!$cart) {
                throw ValidationException::withMessages([
                    'cart' => [
                        'Your cart is empty.',
                    ],
                ]);
            }

            $cart->load('items');

            if ($cart->items->isEmpty()) {
                throw ValidationException::withMessages([
                    'cart' => [
                        'Your cart is empty.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Lock Products
            |--------------------------------------------------------------------------
            */
            $productIds = $cart->items
                ->pluck('product_id')
                ->unique()
                ->values();

            $products = Product::query()
                ->with([
                    'category',
                    'seller',
                ])
                ->whereIn('id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /*
            |--------------------------------------------------------------------------
            | Validate + Calculate
            |--------------------------------------------------------------------------
            */
            $subtotal = 0;
            $preparedItems = [];

            foreach ($cart->items as $cartItem) {
                $product = $products->get($cartItem->product_id);

                if (!$product) {
                    throw ValidationException::withMessages([
                        'cart' => [
                            'One of the products no longer exists.',
                        ],
                    ]);
                }

                if (!$product->is_active) {
                    throw ValidationException::withMessages([
                        'cart' => [
                            "{$product->name_en} is unavailable.",
                        ],
                    ]);
                }

                if (
                    !$product->category ||
                    !$product->category->is_active
                ) {
                    throw ValidationException::withMessages([
                        'cart' => [
                            "{$product->name_en} category is unavailable.",
                        ],
                    ]);
                }

                if (
                    $product->seller_id &&
                    (
                        !$product->seller ||
                        $product->seller->status !== 'approved' ||
                        !$product->seller->is_active
                    )
                ) {
                    throw ValidationException::withMessages([
                        'cart' => [
                            "{$product->name_en} seller is unavailable.",
                        ],
                    ]);
                }

                $quantity = (int) $cartItem->quantity;

                if ($quantity < 1) {
                    throw ValidationException::withMessages([
                        'cart' => [
                            'Cart item quantity must be at least 1.',
                        ],
                    ]);
                }

                if ($quantity > $product->stock) {
                    throw ValidationException::withMessages([
                        'cart' => [
                            "{$product->name_en} has only {$product->stock} items available.",
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Backend Controlled Price
                |--------------------------------------------------------------------------
                */
                $unitPrice = (float) config(
                    'store.product_price',
                    5.75
                );

                $lineTotal = round(
                    $unitPrice * $quantity,
                    2
                );

                /*
                |--------------------------------------------------------------------------
                | Marketplace Commission
                |--------------------------------------------------------------------------
                */
                $commissionPercentage = $product->seller
                    ? (float) $product->seller->commission_percentage
                    : 0;

                $commissionPercentage = max(
                    0,
                    min(100, $commissionPercentage)
                );

                $platformCommission = round(
                    $lineTotal * ($commissionPercentage / 100),
                    2
                );

                $sellerAmount = round(
                    $lineTotal - $platformCommission,
                    2
                );

                $subtotal += $lineTotal;

                $preparedItems[] = [
                    'product' => $product,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                    'commission_percentage' => $commissionPercentage,
                    'seller_amount' => $sellerAmount,
                ];
            }

            $subtotal = round($subtotal, 2);

            $deliveryFee = round(
                (float) config(
                    'store.delivery_fee',
                    0
                ),
                2
            );

            $total = round(
                $subtotal + $deliveryFee,
                2
            );

            /*
            |--------------------------------------------------------------------------
            | Order
            |--------------------------------------------------------------------------
            */
            $orderNumber =
                'ORD-' .
                now()->format('YmdHis') .
                '-' .
                Str::upper(Str::random(5));

            $order = Order::create([
                'order_number' => $orderNumber,
                'user_id' => $user->id,
                'address_id' => $address->id,

                /* Address Snapshot */
                'customer_name' => $address->name,
                'customer_phone' => $address->phone,
                'city' => $address->city,
                'district' => $address->district,
                'street' => $address->street,
                'building' => $address->building,
                'address_notes' => $address->notes,

                /* Totals */
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'total' => $total,
                'currency' => 'SAR',

                /* Payment */
                'payment_method' => 'cash_on_delivery',
                'payment_status' => 'pending',

                /* Order Status */
                'status' => 'pending',
                'customer_notes' => $validated['customer_notes'] ?? null,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Order Items
            |--------------------------------------------------------------------------
            */
            foreach ($preparedItems as $prepared) {
                $product = $prepared['product'];

                $order->items()->create([
                    'product_id' => $product->id,
                    'seller_id' => $product->seller_id,
                    'product_name_ar' => $product->name_ar,
                    'product_name_en' => $product->name_en,
                    'sku' => $product->sku,
                    'unit_price' => $prepared['unit_price'],
                    'quantity' => $prepared['quantity'],
                    'line_total' => $prepared['line_total'],
                    'seller_commission_percentage' =>
                        $prepared['commission_percentage'],
                    'seller_amount' =>
                        $prepared['seller_amount'],
                ]);

                /* Reduce Stock */
                $product->decrement(
                    'stock',
                    $prepared['quantity']
                );
            }

            /*
            |--------------------------------------------------------------------------
            | First Status History
            |--------------------------------------------------------------------------
            */
            $order->statusHistory()->create([
                'changed_by' => $user->id,
                'from_status' => null,
                'to_status' => 'pending',
                'note' => 'Order created.',
            ]);

            /*
            |--------------------------------------------------------------------------
            | Clear Cart
            |--------------------------------------------------------------------------
            */
            $cart->items()->delete();

            return $order;
        });

        /*
        |--------------------------------------------------------------------------
        | Customer-safe response
        |--------------------------------------------------------------------------
        |
        | Do not expose internal seller commission fields to the customer.
        */
        $order->load([
            'items' => function ($query) {
                $query->select([
                    'id',
                    'order_id',
                    'product_id',
                    'seller_id',
                    'product_name_ar',
                    'product_name_en',
                    'sku',
                    'unit_price',
                    'quantity',
                    'line_total',
                ]);
            },
            'statusHistory' => function ($query) {
                $query
                    ->select([
                        'id',
                        'order_id',
                        'from_status',
                        'to_status',
                        'note',
                        'created_at',
                    ])
                    ->oldest('id');
            },
        ]);

        return response()->json([
            'message' => 'Order created successfully.',
            'data' => $order,
        ], 201);
    }
}
