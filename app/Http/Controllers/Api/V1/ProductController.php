<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\Availability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductIndexRequest;
use App\Http\Resources\ProductDetailResource;
use App\Http\Resources\ProductListResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function index(ProductIndexRequest $request, Availability $availability): AnonymousResourceCollection
    {
        $products = Product::query()->visible()
            ->with(['seller', 'category'])
            ->when($request->filled('category'), fn (Builder $query) => $query->whereIn(
                'category_id',
                $this->categoryIds($request->string('category')->toString()),
            ))
            ->when($request->filled('seller'), fn (Builder $query) => $query->whereHas(
                'seller',
                fn (Builder $seller) => $seller->where('slug', $request->string('seller')->toString()),
            ))
            ->when($request->filled('q'), fn (Builder $query) => $this->search($query, $request->string('q')->toString()))
            ->when($request->filled('min_price'), fn (Builder $query) => $query->where('max_gross_price_halalas', '>=', $request->integer('min_price')))
            ->when($request->filled('max_price'), fn (Builder $query) => $query->where('min_gross_price_halalas', '<=', $request->integer('max_price')));

        $this->applySort($products, $request->string('sort')->toString())
            ->orderBy('id');

        $page = $products->cursorPaginate($request->integer('per_page', 20))->withQueryString();

        $zoneId = $request->filled('zone_id') ? $request->integer('zone_id') : null;
        $units = $zoneId === null ? null : $availability->forProducts($page->pluck('id')->all(), $zoneId);

        $page->getCollection()->each(fn (Product $product) => $product->setAttribute(
            'available_units',
            $units === null ? null : ($units[$product->id] ?? 0),
        ));

        return ProductListResource::collection($page);
    }

    public function show(Request $request, string $slug, Availability $availability): ProductDetailResource
    {
        $product = Product::query()->visible()->where('slug', $slug)->with(['seller', 'category', 'variants'])->firstOrFail();

        $zoneId = $request->integer('zone_id') ?: null;
        $variantUnits = $zoneId === null ? null : $availability->forVariants($product->variants->pluck('id')->all(), $zoneId);
        $total = $variantUnits === null ? null : array_sum($variantUnits);
        $product->setAttribute('available_units', $total);

        return new ProductDetailResource($product, $variantUnits);
    }

    /** @return list<int> */
    private function categoryIds(string $slug): array
    {
        $category = Category::query()->active()->where('slug', $slug)->first();

        return $category === null ? [] : $category->selfAndChildIds();
    }

    /** @param  Builder<Product>  $query */
    private function search(Builder $query, string $term): void
    {
        // '!' is declared as the escape character so a literal % or _ in the term cannot act as a wildcard.
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';

        $query->where(fn (Builder $q) => $q
            ->whereRaw("name_ar LIKE ? ESCAPE '!'", [$like])
            ->orWhereRaw("name_en LIKE ? ESCAPE '!'", [$like]));
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    private function applySort(Builder $query, string $sort): Builder
    {
        return match ($sort) {
            'price_asc' => $query->orderBy('min_gross_price_halalas'),
            'price_desc' => $query->orderByDesc('max_gross_price_halalas'),
            default => $query->orderByDesc('is_featured')->orderByDesc('created_at'),
        };
    }
}
