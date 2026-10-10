<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Seller;
use App\Models\Slider;

class HomeController extends Controller
{
    public function index()
    {
        /*
        |--------------------------------------------------------------------------
        | Sliders
        |--------------------------------------------------------------------------
        */

        $sliders = Slider::query()
            ->select([
                'id',
                'title_ar',
                'title_en',
                'description_ar',
                'description_en',
                'image',
                'button_text_ar',
                'button_text_en',
                'link',
                'sort_order',
            ])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();


        /*
        |--------------------------------------------------------------------------
        | Categories
        |--------------------------------------------------------------------------
        */

        $categories = Category::query()
            ->select([
                'id',
                'name_ar',
                'name_en',
                'slug',
                'image',
                'sort_order',
            ])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();


        /*
        |--------------------------------------------------------------------------
        | Sellers
        |--------------------------------------------------------------------------
        |
        | Do NOT expose:
        | - commission_percentage
        | - internal status data
        | - user_id
        |
        */

        $sellers = Seller::query()
            ->select([
                'id',
                'name_ar',
                'name_en',
                'slug',
                'logo',
                'description_ar',
                'description_en',
            ])
            ->where('status', 'approved')
            ->where('is_active', true)
            ->latest('id')
            ->limit(10)
            ->get();


        /*
        |--------------------------------------------------------------------------
        | Featured Products
        |--------------------------------------------------------------------------
        |
        | Never expose cost_price publicly.
        |
        */

        $featuredProducts = Product::query()
            ->select([
                'id',
                'category_id',
                'seller_id',
                'name_ar',
                'name_en',
                'slug',
                'description_ar',
                'description_en',
                'price',
                'stock',
                'sku',
                'image',
                'is_featured',
            ])

            ->with([
                'category:id,name_ar,name_en,slug,image',

                'seller:id,name_ar,name_en,slug,logo',
            ])

            ->where('is_active', true)
            ->where('is_featured', true)

            ->whereHas('category', function ($query) {
                $query->where('is_active', true);
            })

            ->where(function ($query) {
                $query
                    ->whereNull('seller_id')

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

            ->latest('id')
            ->limit(10)
            ->get();


        /*
        |--------------------------------------------------------------------------
        | Latest Products
        |--------------------------------------------------------------------------
        */

        $latestProducts = Product::query()
            ->select([
                'id',
                'category_id',
                'seller_id',
                'name_ar',
                'name_en',
                'slug',
                'description_ar',
                'description_en',
                'price',
                'stock',
                'sku',
                'image',
                'is_featured',
            ])

            ->with([
                'category:id,name_ar,name_en,slug,image',

                'seller:id,name_ar,name_en,slug,logo',
            ])

            ->where('is_active', true)

            ->whereHas('category', function ($query) {
                $query->where('is_active', true);
            })

            ->where(function ($query) {
                $query
                    ->whereNull('seller_id')

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

            ->latest('id')
            ->limit(20)
            ->get();


        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'data' => [

                'sliders' =>
                    $sliders,

                'categories' =>
                    $categories,

                'sellers' =>
                    $sellers,

                'featured_products' =>
                    $featuredProducts,

                'latest_products' =>
                    $latestProducts,

                'store' => [
                    'price' => (float) config(
                        'store.product_price',
                        5.75
                    ),

                    'delivery_fee' => (float) config(
                        'store.delivery_fee',
                        0
                    ),

                    'currency' =>
                        'SAR',
                ],
            ],
        ]);
    }
}
