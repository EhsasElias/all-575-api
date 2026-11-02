<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Seller;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SellerWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_endpoints_require_a_seller_token(): void
    {
        $this->getJson('/api/v1/seller/orders')->assertUnauthorized();

        $customer = User::factory()->create();
        $this->actingAsCustomer($customer);
        $this->getJson('/api/v1/seller/orders')->assertForbidden();
    }

    public function test_seller_token_without_an_approved_staff_membership_sees_nothing(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['seller']);

        $this->getJson('/api/v1/seller/orders')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson('/api/v1/seller/orders/01JXXXXXXXXXXXXXXX00000000/status', [
            'status' => 'preparing',
        ])->assertNotFound();
    }

    public function test_staff_access_is_disabled_by_default(): void
    {
        $user = User::factory()->create();
        $seller = Seller::factory()->create();
        DB::table('seller_staff_access')->insert([
            'user_id' => $user->id,
            'seller_id' => $seller->id,
            'role' => 'owner',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($user, ['seller']);
        $this->getJson('/api/v1/seller/orders')->assertOk()->assertJsonCount(0, 'data');
    }
}
