<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\Seller;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class SellerProductCodeLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_authorized_seller_may_lookup_own_scanned_product(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $otherSeller = Seller::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create([
            'seller_id' => $seller->id,
            'name_ar' => 'منتج ممسوح', 'name_en' => 'Scanned product',
        ]);
        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'SCAN-001',
            'barcode' => '6281234567890',
        ]);
        $owner = User::factory()->create();
        DB::table('seller_staff_access')->insert([
            'seller_id' => $seller->id,
            'user_id' => $owner->id,
            'branch_id' => null,
            'role' => 'owner',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Sanctum::actingAs($owner, ['seller']);

        $this->getJson('/api/v1/seller/products/code-lookup?seller_id='.$seller->id.'&code=6281234567890')
            ->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.name_en', 'Scanned product');
        $this->getJson('/api/v1/seller/products/code-lookup?seller_id='.$seller->id.'&code=SCAN-001')
            ->assertOk()->assertJsonPath('data.sku', 'SCAN-001');
        $this->getJson('/api/v1/seller/products/code-lookup?seller_id='.$seller->id.'&code=NOT-FOUND')
            ->assertOk()->assertJsonPath('data', null);
        $this->getJson('/api/v1/seller/products/code-lookup?seller_id='.$otherSeller->id.'&code=SCAN-001')
            ->assertNotFound();
    }
}
