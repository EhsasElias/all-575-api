<?php

namespace Tests\Support;

use App\Domain\Catalog\Models\Branch;
use App\Domain\Catalog\Models\BranchStock;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\Seller;
use App\Domain\Catalog\Models\ServiceZone;

/** Builds a product that can actually be bought: published, in stock, inside a delivery zone. */
final class CatalogBuilder
{
    public ServiceZone $zone;

    public function __construct(?ServiceZone $zone = null)
    {
        $this->zone = $zone ?? ServiceZone::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $product
     * @param  array<string, mixed>  $variant
     * @return array{product: Product, variant: ProductVariant, branch: Branch, stock: BranchStock}
     */
    public function stockedProduct(int $onHand = 100, array $product = [], array $variant = [], ?Seller $seller = null, ?Category $category = null): array
    {
        $seller ??= Seller::factory()->create();
        $category ??= Category::factory()->create();

        $product = Product::factory()->create($product + ['seller_id' => $seller->id, 'category_id' => $category->id]);
        $variant = ProductVariant::factory()->create($variant + ['product_id' => $product->id]);
        $branch = Branch::query()->where('seller_id', $seller->id)->where('service_zone_id', $this->zone->id)->first()
            ?? Branch::factory()->create(['seller_id' => $seller->id, 'service_zone_id' => $this->zone->id]);
        $stock = BranchStock::factory()->create(['branch_id' => $branch->id, 'variant_id' => $variant->id, 'on_hand' => $onHand]);

        return compact('product', 'variant', 'branch', 'stock');
    }
}
