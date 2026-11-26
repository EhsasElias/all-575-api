<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Catalog\Models\Branch;
use App\Domain\Catalog\Models\BranchStock;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class HomeProductStockBranchTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_returns_real_branch_and_variant_when_available(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $branch = Branch::factory()->for($seller)->create([
            'name_ar' => 'الفرع الرئيسي',
            'name_en' => 'Main branch',
            'is_active' => true,
        ]);
        $product = Product::factory()->for($seller)->create();
        $variant = ProductVariant::factory()->for($product)->create(['is_active' => true]);
        BranchStock::factory()->for($variant, 'variant')->for($branch)->create([
            'on_hand' => 8,
            'reserved' => 2,
        ]);

        $response = $this->getJson('/api/v1/products?in_stock=1')->assertOk();
        $found = collect($response->json('data'))->firstWhere('id', $product->id);

        $this->assertNotNull($found);
        $this->assertSame($variant->id, $found['purchase_variant_id']);
        $this->assertSame('Main branch', $found['stock_branch']['en']);
        $this->assertSame('الفرع الرئيسي', $found['stock_branch']['ar']);
    }

    public function test_out_of_stock_product_has_no_purchasable_variant(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $branch = Branch::factory()->for($seller)->create(['is_active' => true]);
        $product = Product::factory()->for($seller)->create();
        $variant = ProductVariant::factory()->for($product)->create(['is_active' => true]);
        BranchStock::factory()->for($variant, 'variant')->for($branch)->create([
            'on_hand' => 3,
            'reserved' => 3,
        ]);

        $response = $this->getJson('/api/v1/products')->assertOk();
        $found = collect($response->json('data'))->firstWhere('id', $product->id);

        $this->assertNotNull($found);
        $this->assertNull($found['purchase_variant_id']);
        $this->assertNull($found['stock_branch']);
    }
}
