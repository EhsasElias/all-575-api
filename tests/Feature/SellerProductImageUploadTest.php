<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Seller;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class SellerProductImageUploadTest extends TestCase
{
    use RefreshDatabase;

    private function signInSeller(Seller $seller): void
    {
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
    }

    private function photo(): UploadedFile
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/8XcAAAAASUVORK5CYII=',
            true
        );
        $this->assertIsString($png);

        return UploadedFile::fake()->createWithContent('cover.png', $png);
    }

    public function test_authorized_seller_can_upload_png_to_draft(): void
    {
        Storage::fake('public');
        $seller = Seller::factory()->create(['status' => 'approved']);
        $product = Product::factory()->for($seller)->create(['status' => 'draft']);
        $this->signInSeller($seller);

        $response = $this->post('/api/v1/seller/products/'.$product->id.'/image', [
            'image' => $this->photo(),
        ], ['Accept' => 'application/json'])->assertOk();

        $path = $response->json('data.image_path');
        $this->assertIsString($path);
        Storage::disk('public')->assertExists($path);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'image_path' => $path]);
    }

    public function test_image_upload_is_not_allowed_for_a_different_seller(): void
    {
        Storage::fake('public');
        $seller = Seller::factory()->create(['status' => 'approved']);
        $otherSeller = Seller::factory()->create(['status' => 'approved']);
        $product = Product::factory()->for($otherSeller)->create(['status' => 'draft']);
        $this->signInSeller($seller);

        $this->post('/api/v1/seller/products/'.$product->id.'/image', [
            'image' => $this->photo(),
        ], ['Accept' => 'application/json'])->assertNotFound();
    }
}
