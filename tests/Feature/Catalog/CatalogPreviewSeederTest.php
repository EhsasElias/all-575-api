<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Branch;
use App\Domain\Catalog\Models\BranchStock;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Seller;
use Database\Seeders\CatalogPreviewSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogPreviewSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_catalog_is_browsable_but_has_no_sellable_inventory(): void
    {
        $this->seed(CatalogPreviewSeeder::class);

        $this->assertSame(14, Product::query()->where('slug', 'like', 'preview-%')->count());
        $this->assertSame(14, Category::query()->count());
        $this->assertSame(14, BranchStock::query()->count());
        $this->assertSame(0, BranchStock::query()->sum('on_hand'));
        $this->assertFalse(Branch::query()->firstOrFail()->is_active);

        $this->getJson('/api/v1/products?per_page=20')->assertOk()->assertJsonCount(14, 'data');
        $this->getJson('/api/v1/products/preview-vegetables')->assertOk()->assertJsonPath('data.slug', 'preview-vegetables');
    }

    public function test_repeat_run_preserves_existing_products_and_stock(): void
    {
        $this->seed(CatalogPreviewSeeder::class);
        $product = Product::query()->where('slug', 'preview-clothing')->firstOrFail();
        $product->update(['price_halalas' => 199, 'name_en' => 'Reviewed listing']);
        $stock = BranchStock::query()->firstOrFail();
        $stock->update(['on_hand' => 7]);

        $this->seed(CatalogPreviewSeeder::class);

        $this->assertSame(14, Product::query()->count());
        $this->assertSame(14, Category::query()->count());
        $this->assertSame(1, Seller::query()->count());
        $this->assertSame(14, BranchStock::query()->count());
        $this->assertSame(7, $stock->refresh()->on_hand);
        $this->assertSame(199, $product->refresh()->price_halalas);
        $this->assertSame('Reviewed listing', $product->name_en);
    }
}
