<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\Seller;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class SellerProductReviewTest extends TestCase
{
    use RefreshDatabase;

    private function reviewer(): void
    {
        $user = User::factory()->create();
        DB::table('seller_review_grants')->insert([
            'user_id' => $user->id, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Sanctum::actingAs($user, ['customer']);
    }

    public function test_reviewer_cannot_publish_without_image_or_active_variant(): void
    {
        Storage::fake('public');
        $this->reviewer();
        $seller = Seller::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->for($seller)->for($category)->create([
            'status' => 'pending_review', 'price_halalas' => 575,
        ]);

        $this->postJson('/api/v1/seller-review/products/'.$product->id.'/decision',
            ['decision' => 'publish'])->assertStatus(422);

        Storage::disk('public')->put('products/cover.jpg', 'fake-image-content');
        $product->update(['image_path' => 'products/cover.jpg']);
        $this->postJson('/api/v1/seller-review/products/'.$product->id.'/decision',
            ['decision' => 'publish'])->assertStatus(422);

        ProductVariant::factory()->for($product)->create();
        $this->postJson('/api/v1/seller-review/products/'.$product->id.'/decision',
            ['decision' => 'publish'])->assertOk()->assertJsonPath('data.status', 'published');

        $this->assertDatabaseHas('seller_review_audits', [
            'subject_type' => 'product', 'subject_id' => $product->id,
            'previous_status' => 'pending_review', 'new_status' => 'published',
        ]);

        $this->postJson('/api/v1/seller-review/products/'.$product->id.'/decision',
            ['decision' => 'reject', 'reason' => 'Please fix the product image first.'])->assertStatus(409);
    }

    public function test_rejection_requires_reason_and_cannot_be_decided_twice(): void
    {
        $this->reviewer();
        $product = Product::factory()->create(['status' => 'pending_review', 'price_halalas' => 500]);

        $this->postJson('/api/v1/seller-review/products/'.$product->id.'/decision',
            ['decision' => 'reject'])->assertUnprocessable();

        $this->postJson('/api/v1/seller-review/products/'.$product->id.'/decision', [
            'decision' => 'reject', 'reason' => 'The submitted description needs correction.',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');
    }
}
