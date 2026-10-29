<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\Product;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductListResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class SavedProductController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $products = Product::query()->visible()->with(['seller', 'category'])
            ->whereIn('id', DB::table('customer_saved_products')->where('user_id', $request->user()->id)->select('product_id'))
            ->orderByDesc('id')->paginate(20);
        $products->getCollection()->each(fn (Product $product) => $product->setAttribute('available_units', null));

        return ProductListResource::collection($products);
    }

    /** @response array{data: array{saved: bool}} */
    public function show(Request $request, string $slug): JsonResponse
    {
        $id = Product::query()->where('slug', $slug)->value('id');

        return response()->json(['data' => ['saved' => DB::table('customer_saved_products')->where('user_id', $request->user()->id)->where('product_id', $id)->exists()]]);
    }

    /** @response array{data: array{saved: bool}} */
    public function store(Request $request, string $slug): JsonResponse
    {
        $product = Product::query()->visible()->where('slug', $slug)->firstOrFail();
        DB::table('customer_saved_products')->insertOrIgnore([
            'user_id' => $request->user()->id, 'product_id' => $product->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['data' => ['saved' => true]]);
    }

    /** @response array{data: array{saved: bool}} */
    public function destroy(Request $request, string $slug): JsonResponse
    {
        $id = Product::query()->where('slug', $slug)->value('id');
        DB::table('customer_saved_products')->where('user_id', $request->user()->id)->where('product_id', $id)->delete();

        return response()->json(['data' => ['saved' => false]]);
    }
}
