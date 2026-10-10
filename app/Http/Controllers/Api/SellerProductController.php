<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SellerProductController extends Controller
{
    public function index(Request $request)
    {
        $seller = $request->attributes->get(
            'seller'
        );

        $products = Product::query()
            ->where(
                'seller_id',
                $seller->id
            )
            ->with([
                'category:id,name_ar,name_en,slug',
            ])
            ->latest('id')
            ->paginate(20);

        return response()->json(
            $products
        );
    }

    public function show(
        Request $request,
        Product $product
    ) {
        $seller = $request->attributes->get(
            'seller'
        );

        $product = Product::query()
            ->where('id', $product->id)
            ->where(
                'seller_id',
                $seller->id
            )
            ->with('category')
            ->firstOrFail();

        return response()->json([
            'data' => $product,
        ]);
    }

    public function store(Request $request)
    {
        $seller = $request->attributes->get(
            'seller'
        );

        $validated = $request->validate([
            'category_id' => [
                'required',
                'integer',
                'exists:categories,id',
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

            'description_ar' => [
                'nullable',
                'string',
            ],

            'description_en' => [
                'nullable',
                'string',
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
        ]);

        $category = Category::findOrFail(
            $validated['category_id']
        );

        if (!$category->is_active) {
            return response()->json([
                'message' =>
                    'Selected category is inactive.',
            ], 422);
        }

        $slug = $this->uniqueSlug(
            $validated['name_en']
        );

        $product = Product::create([
            'category_id' =>
                $validated['category_id'],

            'seller_id' =>
                $seller->id,

            'name_ar' =>
                $validated['name_ar'],

            'name_en' =>
                $validated['name_en'],

            'slug' =>
                $slug,

            'description_ar' =>
                $validated['description_ar']
                ?? null,

            'description_en' =>
                $validated['description_en']
                ?? null,

            'price' =>
                config(
                    'store.product_price',
                    5.75
                ),

            'cost_price' =>
                null,

            'stock' =>
                $validated['stock'],

            'sku' =>
                $validated['sku']
                ?? null,

            'image' =>
                $validated['image']
                ?? null,

            'is_active' =>
                true,

            'is_featured' =>
                false,
        ]);

        return response()->json([
            'message' =>
                'Product created successfully.',

            'data' =>
                $product->load('category'),
        ], 201);
    }

    public function update(
        Request $request,
        Product $product
    ) {
        $seller = $request->attributes->get(
            'seller'
        );

        $product = Product::query()
            ->where('id', $product->id)
            ->where(
                'seller_id',
                $seller->id
            )
            ->firstOrFail();

        $validated = $request->validate([
            'category_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:categories,id',
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

            'description_ar' => [
                'nullable',
                'string',
            ],

            'description_en' => [
                'nullable',
                'string',
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

                Rule::unique(
                    'products',
                    'sku'
                )->ignore($product->id),
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
        ]);

        if (
            isset($validated['category_id'])
        ) {
            $category = Category::findOrFail(
                $validated['category_id']
            );

            if (!$category->is_active) {
                return response()->json([
                    'message' =>
                        'Selected category is inactive.',
                ], 422);
            }
        }

        if (
            isset($validated['name_en'])
        ) {
            $validated['slug'] =
                $this->uniqueSlug(
                    $validated['name_en'],
                    $product->id
                );
        }

        // Seller can never change price.
        $validated['price'] =
            config(
                'store.product_price',
                5.75
            );

        $product->update(
            $validated
        );

        return response()->json([
            'message' =>
                'Product updated successfully.',

            'data' =>
                $product
                    ->fresh()
                    ->load('category'),
        ]);
    }

    public function destroy(
        Request $request,
        Product $product
    ) {
        $seller = $request->attributes->get(
            'seller'
        );

        $product = Product::query()
            ->where('id', $product->id)
            ->where(
                'seller_id',
                $seller->id
            )
            ->firstOrFail();

        // Keep historical order data safe.
        $product->update([
            'is_active' => false,
        ]);

        return response()->json([
            'message' =>
                'Product deactivated successfully.',
        ]);
    }

    private function uniqueSlug(
        string $name,
        ?int $ignoreId = null
    ): string {
        $baseSlug = Str::slug($name);

        if (!$baseSlug) {
            $baseSlug = 'product';
        }

        $slug = $baseSlug;
        $counter = 1;

        while (
            Product::query()
                ->where('slug', $slug)
                ->when(
                    $ignoreId,
                    fn ($query) =>
                        $query->where(
                            'id',
                            '!=',
                            $ignoreId
                        )
                )
                ->exists()
        ) {
            $slug =
                $baseSlug .
                '-' .
                $counter;

            $counter++;
        }

        return $slug;
    }
}
