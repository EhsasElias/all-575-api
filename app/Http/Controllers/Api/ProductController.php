<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Public Products
    |--------------------------------------------------------------------------
    */

    public function index(Request $request)
    {
        $products = Product::query()
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
                'is_active',
                'is_featured',
                'created_at',
                'updated_at',
            ])
            ->with([
                'category:id,name_ar,name_en,slug,image',
                'seller:id,name_ar,name_en,slug,logo,status,is_active',
            ])
            ->where('is_active', true)
            ->whereHas('category', function ($query) {
                $query->where('is_active', true);
            })
            ->where(function ($query) {
                $query
                    ->whereNull('seller_id')
                    ->orWhereHas('seller', function ($sellerQuery) {
                        $sellerQuery
                            ->where('status', 'approved')
                            ->where('is_active', true);
                    });
            })

            // Search
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->trim()->toString();

                $query->where(function ($q) use ($search) {
                    $q->where('name_ar', 'like', "%{$search}%")
                        ->orWhere('name_en', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                });
            })

            // Category filter
            ->when($request->filled('category_id'), function ($query) use ($request) {
                $query->where(
                    'category_id',
                    $request->integer('category_id')
                );
            })

            // Seller filter
            ->when($request->filled('seller_id'), function ($query) use ($request) {
                $query->where(
                    'seller_id',
                    $request->integer('seller_id')
                );
            })

            // Featured
            ->when(
                $request->boolean('featured'),
                fn ($query) => $query->where('is_featured', true)
            )

            ->orderByDesc('is_featured')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json($products);
    }

    public function show(Product $product)
    {
        if (! $product->is_active) {
            return response()->json([
                'message' => 'Product not found.',
            ], 404);
        }

        if (! $product->category || ! $product->category->is_active) {
            return response()->json([
                'message' => 'Product not found.',
            ], 404);
        }

        if (
            $product->seller_id &&
            (
                ! $product->seller ||
                $product->seller->status !== 'approved' ||
                ! $product->seller->is_active
            )
        ) {
            return response()->json([
                'message' => 'Product not found.',
            ], 404);
        }

        $product->makeHidden([
            'cost_price',
        ]);

        $product->load([
            'category:id,name_ar,name_en,slug,image',
            'seller:id,name_ar,name_en,slug,logo',
        ]);

        return response()->json([
            'data' => $product,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Admin Products
    |--------------------------------------------------------------------------
    */

    public function adminIndex(Request $request)
    {
        $products = Product::query()
            ->with([
                'category:id,name_ar,name_en,slug',
                'seller:id,name_ar,name_en,slug,status',
            ])

            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->trim()->toString();

                $query->where(function ($q) use ($search) {
                    $q->where('name_ar', 'like', "%{$search}%")
                        ->orWhere('name_en', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                });
            })

            ->when($request->filled('category_id'), function ($query) use ($request) {
                $query->where(
                    'category_id',
                    $request->integer('category_id')
                );
            })

            ->when($request->filled('seller_id'), function ($query) use ($request) {
                $query->where(
                    'seller_id',
                    $request->integer('seller_id')
                );
            })

            ->orderByDesc('id')
            ->paginate(30);

        return response()->json($products);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'category_id' => [
                'required',
                'integer',
                'exists:categories,id',
            ],

            'seller_id' => [
                'nullable',
                'integer',
                'exists:sellers,id',
            ],

            'name_ar' => [
                'required',
                'string',
                'max:255',
            ],

            'name_en' => [
                'required',
                'string',
                'max:255',
            ],

            'slug' => [
                'nullable',
                'string',
                'max:255',
                'unique:products,slug',
            ],

            'description_ar' => [
                'nullable',
                'string',
            ],

            'description_en' => [
                'nullable',
                'string',
            ],

            'cost_price' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'stock' => [
                'required',
                'integer',
                'min:0',
            ],

            'sku' => [
                'nullable',
                'string',
                'max:100',
                'unique:products,sku',
            ],

            'image' => [
                'nullable',
                'string',
                'max:2048',
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],

            'is_featured' => [
                'nullable',
                'boolean',
            ],
        ]);

        $product = Product::create([
            'category_id' => $validated['category_id'],
            'seller_id' => $validated['seller_id'] ?? null,

            'name_ar' => $validated['name_ar'],
            'name_en' => $validated['name_en'],

            'slug' => $validated['slug']
                ?? Str::slug($validated['name_en']),

            'description_ar' => $validated['description_ar'] ?? null,

            'description_en' => $validated['description_en'] ?? null,

            // Fixed by backend
            'price' => config('store.product_price', 5.75),

            'cost_price' => $validated['cost_price'] ?? null,

            'stock' => $validated['stock'],

            'sku' => $validated['sku'] ?? null,

            'image' => $validated['image'] ?? null,

            'is_active' => $validated['is_active'] ?? true,

            'is_featured' => $validated['is_featured'] ?? false,
        ]);

        $product->load([
            'category:id,name_ar,name_en,slug',
            'seller:id,name_ar,name_en,slug',
        ]);

        return response()->json([
            'message' => 'Product created successfully.',
            'data' => $product,
        ], 201);
    }

    public function update(
        Request $request,
        Product $product
    ) {
        $validated = $request->validate([
            'category_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:categories,id',
            ],

            'seller_id' => [
                'nullable',
                'integer',
                'exists:sellers,id',
            ],

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

            'slug' => [
                'nullable',
                'string',
                'max:255',

                Rule::unique('products', 'slug')
                    ->ignore($product->id),
            ],

            'description_ar' => [
                'nullable',
                'string',
            ],

            'description_en' => [
                'nullable',
                'string',
            ],

            'cost_price' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'stock' => [
                'sometimes',
                'required',
                'integer',
                'min:0',
            ],

            'sku' => [
                'nullable',
                'string',
                'max:100',

                Rule::unique('products', 'sku')
                    ->ignore($product->id),
            ],

            'image' => [
                'nullable',
                'string',
                'max:2048',
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],

            'is_featured' => [
                'nullable',
                'boolean',
            ],
        ]);

        if (
            isset($validated['name_en']) &&
            ! array_key_exists('slug', $validated)
        ) {
            $validated['slug'] =
                Str::slug($validated['name_en']);
        }

        // Price can never be changed by client
        $validated['price'] =
            config('store.product_price', 5.75);

        $product->update($validated);

        $product->load([
            'category:id,name_ar,name_en,slug',
            'seller:id,name_ar,name_en,slug',
        ]);

        return response()->json([
            'message' => 'Product updated successfully.',
            'data' => $product->fresh(),
        ]);
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return response()->json([
            'message' => 'Product deleted successfully.',
        ]);
    }
}
