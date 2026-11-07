<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Catalog\Models\Branch;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\Seller;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class SellerStockCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_initialize_stock_for_owned_sku_but_cannot_duplicate_it(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $branch = Branch::factory()->for($seller)->create();
        $product = Product::factory()->for($seller)->create();
        $variant = ProductVariant::factory()->for($product)->create(['sku' => 'STOCK-A1']);
        $user = User::factory()->create();

        DB::table('seller_staff_access')->insert([
            'seller_id' => $seller->id,
            'user_id' => $user->id,
            'branch_id' => null,
            'role' => 'owner',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Sanctum::actingAs($user, ['seller']);
        $payload = [
            'branch_id' => $branch->id,
            'sku' => 'STOCK-A1',
            'on_hand' => 17,
        ];
        $this->postJson('/api/v1/seller/stock', $payload)
            ->assertCreated()
            ->assertJsonPath('data.on_hand', 17)
            ->assertJsonPath('data.reserved', 0);
        $this->assertDatabaseHas('branch_stock', [
            'branch_id' => $branch->id, 'variant_id' => $variant->id,
            'on_hand' => 17, 'reserved' => 0,
        ]);
        $this->postJson('/api/v1/seller/stock', $payload)->assertStatus(409);
        $this->assertDatabaseCount('branch_stock', 1);
    }

    public function test_seller_cannot_add_another_sellers_sku_or_a_branch_without_permission(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $other = Seller::factory()->create(['status' => 'approved']);
        $branch = Branch::factory()->for($seller)->create();
        $otherBranch = Branch::factory()->for($other)->create();
        $product = Product::factory()->for($other)->create();
        ProductVariant::factory()->for($product)->create(['sku' => 'OTHER-A1']);
        $user = User::factory()->create();

        DB::table('seller_staff_access')->insert([
            'seller_id' => $seller->id, 'user_id' => $user->id,
            'branch_id' => $branch->id, 'role' => 'branch_manager',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Sanctum::actingAs($user, ['seller']);
        $this->postJson('/api/v1/seller/stock', [
            'branch_id' => $branch->id, 'sku' => 'OTHER-A1', 'on_hand' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('sku');
        $this->postJson('/api/v1/seller/stock', [
            'branch_id' => $otherBranch->id, 'sku' => 'OTHER-A1', 'on_hand' => 1,
        ])->assertNotFound();
        $this->assertDatabaseCount('branch_stock', 0);
    }
}
