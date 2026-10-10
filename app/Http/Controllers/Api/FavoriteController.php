<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    public function index(Request $request)
    {
        $products = $request->user()
            ->favorites()
            ->select([
                'products.id',
                'products.category_id',
                'products.seller_id',
                'products.name_ar',
                'products.name_en',
                'products.slug',
                'products.price',
                'products.stock',
                'products.image',
                'products.is_featured',
            ])
            ->with([
                'category:id,name_ar,name_en,slug',
                'seller:id,name_ar,name_en,slug,logo',
            ])

            /*
            |--------------------------------------------------------------------------
            | Product must be active
            |--------------------------------------------------------------------------
            */

            ->where(
                'products.is_active',
                true
            )

            /*
            |--------------------------------------------------------------------------
            | Category must be active
            |--------------------------------------------------------------------------
            */

            ->whereHas(
                'category',
                function ($query) {
                    $query->where(
                        'is_active',
                        true
                    );
                }
            )

            /*
            |--------------------------------------------------------------------------
            | Seller must be approved + active
            |--------------------------------------------------------------------------
            */

            ->where(function ($query) {
                $query
                    ->whereNull(
                        'products.seller_id'
                    )
                    ->orWhereHas(
                        'seller',
                        function ($sellerQuery) {
                            $sellerQuery
                                ->where(
                                    'status',
                                    'approved'
                                )
                                ->where(
                                    'is_active',
                                    true
                                );
                        }
                    );
            })

            ->orderByDesc(
                'favorites.created_at'
            )
            ->get();

        return response()->json([
            'data' => $products,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => [
                'required',
                'integer',
                'exists:products,id',
            ],
        ]);

        $product = Product::with([
            'category',
            'seller',
        ])->findOrFail(
            $validated['product_id']
        );

        /*
        |--------------------------------------------------------------------------
        | Product Availability
        |--------------------------------------------------------------------------
        */

        if (!$product->is_active) {
            return response()->json([
                'message' =>
                    'Product is unavailable.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Category Availability
        |--------------------------------------------------------------------------
        */

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

        /*
        |--------------------------------------------------------------------------
        | Seller Availability
        |--------------------------------------------------------------------------
        */

        if (
            $product->seller_id
            &&
            (
                !$product->seller
                ||
                $product->seller->status
                    !== 'approved'
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
        | Add Favorite
        |--------------------------------------------------------------------------
        */

        $request->user()
            ->favorites()
            ->syncWithoutDetaching([
                $product->id,
            ]);

        return response()->json([
            'message' =>
                'Product added to favorites.',
        ], 201);
    }

    public function destroy(
        Request $request,
        Product $product
    ) {
        /*
        |--------------------------------------------------------------------------
        | Remove Only From Current User Favorites
        |--------------------------------------------------------------------------
        */

        $request->user()
            ->favorites()
            ->detach(
                $product->id
            );

        return response()->json([
            'message' =>
                'Product removed from favorites.',
        ]);
    }
}