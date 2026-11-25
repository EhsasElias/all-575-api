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

final class CustomerCatalogStockFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_is_visible_before_address_selection_and_can_be_filtered_without_zone(): void
    {
        $zone = ServiceZone::factory()->create();
        $product = Product::factory()->create(['price_halalas' => 300]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $branch = Branch::factory()->create([
            'seller_id' => $product->seller_id,
            'service_zone_id' => $zone->id,
        ]);

        BranchStock::factory()->create([
            'branch_id' => $branch->id, 'variant_id' => $variant->id,
            'on_hand' => 7, 'reserved' => 1,
        ]);

        $this->getJson('/api/v1/products?in_stock=1')
            ->assertOk()
            ->assertJsonFragment(['slug' => $product->slug, 'availability' => 'in_stock']);

        $this->getJson('/api/v1/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.availability', 'in_stock');
    }

    public function test_products_with_no_free_stock_are_not_recommended_even_without_zone(): void
    {
        $zone = ServiceZone::factory()->create();
        $product = Product::factory()->create(['price_halalas' => 300]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $branch = Branch::factory()->create([
            'seller_id' => $product->seller_id, 'service_zone_id' => $zone->id,
        ]);
        BranchStock::factory()->create([
            'branch_id' => $branch->id, 'variant_id' => $variant->id,
            'on_hand' => 4, 'reserved' => 4,
        ]);

        $this->getJson('/api/v1/products?in_stock=1')
            ->assertOk()
            ->assertDontSee($product->slug);

        $this->getJson('/api/v1/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.availability', 'out_of_stock');
    }
}
