<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Product;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function show(Request $request)
    {
        $cart = Cart::firstOrCreate([
            'user_id' => $request->user()->id,
        ]);

        $cart->load([
            'items.product' => function ($query) {
                $query->select([
                    'id',
                    'category_id',
                    'seller_id',
                    'name_ar',
                    'name_en',
                    'slug',
                    'price',
                    'stock',
                    'image',
                    'is_active',
                ]);
            },

            'items.product.category:id,name_ar,name_en,slug,is_active',

            'items.product.seller:id,name_ar,name_en,slug,logo,status,is_active',
        ]);

        $items = $cart->items->map(function ($item) {
            $product = $item->product;

            /*
            |--------------------------------------------------------------------------
            | Product no longer exists
            |--------------------------------------------------------------------------
            */

            if (!$product) {
                return [
                    'id' => $item->id,
                    'product' => null,
                    'quantity' => $item->quantity,
                    'unit_price' => 0,
                    'line_total' => 0,
                    'available' => false,
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | Product Availability
            |--------------------------------------------------------------------------
            */

            $available =
                $product->is_active
                &&
                $product->category
                &&
                $product->category->is_active
                &&
                (
                    !$product->seller_id
                    ||
                    (
                        $product->seller
                        &&
                        $product->seller->status === 'approved'
                        &&
                        $product->seller->is_active
                    )
                )
                &&
                $product->stock >= $item->quantity;

            $unitPrice = (float) $product->price;

            $lineTotal = round(
                $unitPrice * $item->quantity,
                2
            );

            return [
                'id' => $item->id,

                'product' => $product,

                'quantity' => $item->quantity,

                'unit_price' => $unitPrice,

                'line_total' => $lineTotal,

                'available' => $available,
            ];
        });

        $subtotal = $items
            ->where('available', true)
            ->sum('line_total');

        return response()->json([
            'data' => [
                'id' => $cart->id,

                'items' => $items->values(),

                'items_count' => $cart
                    ->items
                    ->sum('quantity'),

                'subtotal' => round(
                    $subtotal,
                    2
                ),

                'currency' => 'SAR',
            ],
        ]);
    }

    public function add(Request $request)
    {
        $validated = $request->validate([
            'product_id' => [
                'required',
                'integer',
                'exists:products,id',
            ],

            'quantity' => [
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],
        ]);

        $quantity =
            $validated['quantity'] ?? 1;

        $product = Product::with([
            'category',
            'seller',
        ])->findOrFail(
            $validated['product_id']
        );

        /*
        |--------------------------------------------------------------------------
        | Validate Product
        |--------------------------------------------------------------------------
        */

        if (!$product->is_active) {
            return response()->json([
                'message' =>
                    'Product is unavailable.',
            ], 422);
        }

        if (
            !$product->category
            ||
            !$product->category->is_active
        ) {
            return response()->json([
                'message' =>
                    'Product category is unavailable.',
            ], 422);
        }

        if (
            $product->seller_id
            &&
            (
                !$product->seller
                ||
                $product->seller->status !== 'approved'
                ||
                !$product->seller->is_active
            )
        ) {
            return response()->json([
                'message' =>
                    'Seller is unavailable.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | User Cart
        |--------------------------------------------------------------------------
        */

        $cart = Cart::firstOrCreate([
            'user_id' => $request->user()->id,
        ]);

        $item = $cart
            ->items()
            ->where(
                'product_id',
                $product->id
            )
            ->first();

        $newQuantity =
            ($item?->quantity ?? 0)
            +
            $quantity;

        /*
        |--------------------------------------------------------------------------
        | Validate Stock
        |--------------------------------------------------------------------------
        */

        if (
            $newQuantity >
            $product->stock
        ) {
            return response()->json([
                'message' =>
                    'Requested quantity exceeds available stock.',

                'available_stock' =>
                    $product->stock,
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Add / Update Item
        |--------------------------------------------------------------------------
        */

        if ($item) {
            $item->update([
                'quantity' =>
                    $newQuantity,
            ]);
        } else {
            $item = $cart
                ->items()
                ->create([
                    'product_id' =>
                        $product->id,

                    'quantity' =>
                        $quantity,
                ]);
        }

        $unitPrice =
            (float) $product->price;

        return response()->json([
            'message' =>
                'Product added to cart.',

            'data' => [
                'product_id' =>
                    $product->id,

                'quantity' =>
                    $item->quantity,

                'unit_price' =>
                    $unitPrice,

                'line_total' =>
                    round(
                        $unitPrice
                        *
                        $item->quantity,
                        2
                    ),
            ],
        ], 201);
    }

    public function update(
        Request $request,
        Product $product
    ) {
        $validated = $request->validate([
            'quantity' => [
                'required',
                'integer',
                'min:1',
                'max:100',
            ],
        ]);

        /*
        |--------------------------------------------------------------------------
        | Load Category + Seller
        |--------------------------------------------------------------------------
        */

        $product->loadMissing([
            'category',
            'seller',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Validate Product
        |--------------------------------------------------------------------------
        */

        if (!$product->is_active) {
            return response()->json([
                'message' =>
                    'Product is unavailable.',
            ], 422);
        }

        if (
            !$product->category
            ||
            !$product->category->is_active
        ) {
            return response()->json([
                'message' =>
                    'Product category is unavailable.',
            ], 422);
        }

        if (
            $product->seller_id
            &&
            (
                !$product->seller
                ||
                $product->seller->status !== 'approved'
                ||
                !$product->seller->is_active
            )
        ) {
            return response()->json([
                'message' =>
                    'Seller is unavailable.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Stock
        |--------------------------------------------------------------------------
        */

        if (
            $validated['quantity'] >
            $product->stock
        ) {
            return response()->json([
                'message' =>
                    'Requested quantity exceeds available stock.',

                'available_stock' =>
                    $product->stock,
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Find User Cart
        |--------------------------------------------------------------------------
        */

        $cart = Cart::query()
            ->where(
                'user_id',
                $request->user()->id
            )
            ->first();

        if (!$cart) {
            return response()->json([
                'message' =>
                    'Cart item not found.',
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | Find User Cart Item
        |--------------------------------------------------------------------------
        */

        $item = $cart
            ->items()
            ->where(
                'product_id',
                $product->id
            )
            ->first();

        if (!$item) {
            return response()->json([
                'message' =>
                    'Cart item not found.',
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | Update Quantity
        |--------------------------------------------------------------------------
        */

        $item->update([
            'quantity' =>
                $validated['quantity'],
        ]);

        $unitPrice =
            (float) $product->price;

        return response()->json([
            'message' =>
                'Cart item updated.',

            'data' => [
                'product_id' =>
                    $product->id,

                'quantity' =>
                    $item->quantity,

                'unit_price' =>
                    $unitPrice,

                'line_total' =>
                    round(
                        $unitPrice
                        *
                        $item->quantity,
                        2
                    ),
            ],
        ]);
    }

    public function remove(
        Request $request,
        Product $product
    ) {
        $cart = Cart::query()
            ->where(
                'user_id',
                $request->user()->id
            )
            ->first();

        if ($cart) {
            $cart
                ->items()
                ->where(
                    'product_id',
                    $product->id
                )
                ->delete();
        }

        return response()->json([
            'message' =>
                'Product removed from cart.',
        ]);
    }

    public function clear(Request $request)
    {
        $cart = Cart::query()
            ->where(
                'user_id',
                $request->user()->id
            )
            ->first();

        if ($cart) {
            $cart
                ->items()
                ->delete();
        }

        return response()->json([
            'message' =>
                'Cart cleared successfully.',
        ]);
    }
}