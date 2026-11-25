<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Branch;
use App\Domain\Catalog\Models\BranchStock;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\ServiceZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductStorefrontBranchTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_and_detail_show_seller_branch_and_purchasable_variant_from_real_stock(): void
    {
        $zone = ServiceZone::factory()->create();
        $product = Product::factory()->create(['price_halalas' => 500]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $branch = Branch::factory()->create([
            'seller_id' => $product->seller_id, 'service_zone_id' => $zone->id,
            'name_ar' => 'فرع الشمال', 'name_en' => 'North branch',
        ]);
        BranchStock::factory()->create([
            'variant_id' => $variant->id, 'branch_id' => $branch->id,
            'on_hand' => 10, 'reserved' => 2,
        ]);

        $this->getJson('/api/v1/products?in_stock=1')
            ->assertOk()
            ->assertJsonPath('data.0.stock_branch.en', 'North branch')
            ->assertJsonPath('data.0.purchase_variant_id', $variant->id);

        $this->getJson('/api/v1/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.stock_branch.en', 'North branch')
            ->assertJsonPath('data.purchase_variant_id', $variant->id);
    }

    public function test_product_with_multiple_options_requires_selection_in_product_page(): void
    {
        $zone = ServiceZone::factory()->create();
        $product = Product::factory()->create(['price_halalas' => 500]);
        $first = ProductVariant::factory()->create(['product_id' => $product->id]);
        $second = ProductVariant::factory()->create(['product_id' => $product->id]);
        $branch = Branch::factory()->create([
            'seller_id' => $product->seller_id, 'service_zone_id' => $zone->id,
        ]);

        foreach ([$first, $second] as $variant) {
            BranchStock::factory()->create([
                'branch_id' => $branch->id, 'variant_id' => $variant->id,
                'on_hand' => 4, 'reserved' => 0,
            ]);
        }

        $this->getJson('/api/v1/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.availability', 'in_stock')
            ->assertJsonPath('data.purchase_variant_id', null);
    }

    public function test_unavailable_product_has_no_quick_purchase_variant(): void
    {
        $zone = ServiceZone::factory()->create();
        $product = Product::factory()->create(['price_halalas' => 500]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $branch = Branch::factory()->create(['seller_id' => $product->seller_id, 'service_zone_id' => $zone->id]);
        BranchStock::factory()->create([
            'variant_id' => $variant->id, 'branch_id' => $branch->id,
            'on_hand' => 3, 'reserved' => 3,
        ]);

        $this->getJson('/api/v1/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.stock_branch', null)
            ->assertJsonPath('data.purchase_variant_id', null);
    }
}
