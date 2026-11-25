<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\Availability;
use App\Domain\Catalog\Services\ProductDisplayStock;
use App\Domain\Catalog\Services\CatalogSort;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductIndexRequest;
use App\Http\Resources\ProductDetailResource;
use App\Http\Resources\ProductListResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function index(ProductIndexRequest $request, Availability $availability, ProductDisplayStock $displayStock): AnonymousResourceCollection
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
            ->when($request->filled('min_price'), fn (Builder $q) => $q->where('products.price_halalas', '>=', $request->integer('min_price')))
            ->when($request->filled('max_price'), fn (Builder $q) => $q->where('products.price_halalas', '<=', $request->integer('max_price')))
            ->when($request->boolean('in_stock'), function (Builder $q): void {
                $q->whereHas('variants', function (Builder $variant): void {
                    $variant->where('is_active', true)->whereHas('stock', function (Builder $stock): void {
                        $stock->whereColumn('on_hand', '>', 'reserved')
                            ->whereHas('branch', fn (Builder $branch) => $branch
                                ->where('is_active', true)
                                ->whereColumn('branches.seller_id', 'products.seller_id'));
                    });
                });
            });

        CatalogSort::apply($products, $request->input('sort', 'featured'));

        $page = $products->cursorPaginate($request->integer('per_page', 20))->withQueryString();

        // Availability is always known from active warehouse stock, even without a delivery address.
        $productIds = $page->pluck('id')->all();
        $units = $availability->forProducts($productIds);
        $branches = $displayStock->branchesForProducts($productIds);

        $page->getCollection()->each(function (Product $product) use ($units, $branches): void {
            $product->setAttribute('available_units', $units[$product->id] ?? 0);
            $product->setAttribute('stock_branch', $branches[$product->id]['branch'] ?? null);
            $product->setAttribute('purchase_variant_id', $branches[$product->id]['variant_id'] ?? null);
        });

        return ProductListResource::collection($page);
    }

    public function show(Request $request, string $slug, Availability $availability, ProductDisplayStock $displayStock): ProductDetailResource
    {
        $product = Product::query()->visible()->where('slug', $slug)->with(['seller', 'category', 'variants'])->firstOrFail();

        $variantUnits = $availability->forVariants($product->variants->pluck('id')->all());
        $total = array_sum($variantUnits);
        $product->setAttribute('available_units', $total);
        $display = $displayStock->branchesForProducts([$product->id])[$product->id] ?? null;
        $product->setAttribute('stock_branch', $display['branch'] ?? null);
        $product->setAttribute('purchase_variant_id', $display['variant_id'] ?? null);

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
}
