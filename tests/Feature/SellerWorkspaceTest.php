<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Seller;
use App\Domain\Catalog\Models\Branch;
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
        $this->getJson('/api/v1/seller/dashboard')->assertUnauthorized();
        $this->getJson('/api/v1/seller/branches')->assertUnauthorized();
        $this->getJson('/api/v1/seller/products')->assertUnauthorized();
        $this->getJson('/api/v1/seller/stock')->assertUnauthorized();

        $customer = User::factory()->create();
        $this->actingAsCustomer($customer);
        $this->getJson('/api/v1/seller/orders')->assertForbidden();
        $this->getJson('/api/v1/seller/dashboard')->assertForbidden();
        $this->getJson('/api/v1/seller/branches')->assertForbidden();
        $this->getJson('/api/v1/seller/products')->assertForbidden();
        $this->getJson('/api/v1/seller/stock')->assertForbidden();
    }

    public function test_seller_token_without_an_approved_staff_membership_sees_nothing(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['seller']);

        $this->getJson('/api/v1/seller/orders')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/seller/dashboard')->assertOk()->assertJsonPath('data.preparing', 0);
        $this->getJson('/api/v1/seller/branches')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/seller/products')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/seller/stock')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson('/api/v1/seller/orders/01JXXXXXXXXXXXXXXX00000000/status', [
            'status' => 'preparing',
        ])->assertNotFound();
    }

    public function test_unassigned_customer_cannot_issue_seller_session(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAsCustomer($user);
        $this->postJson('/api/v1/seller/session', ['device_name' => 'test-device'])->assertForbidden();
    }

    public function test_inactive_seller_membership_cannot_issue_seller_session(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $seller = Seller::factory()->create();
        DB::table('seller_staff_access')->insert([
            'user_id' => $user->id,
            'seller_id' => $seller->id,
            'role' => 'owner',
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAsCustomer($user);
        $this->postJson('/api/v1/seller/session', ['device_name' => 'test-device'])->assertForbidden();
    }

    public function test_branch_listing_respects_seller_and_branch_membership(): void
    {
        $user = User::factory()->create();
        $seller = Seller::factory()->create();
        $otherSeller = Seller::factory()->create();
        $allowed = Branch::factory()->for($seller)->create();
        Branch::factory()->for($seller)->create();
        Branch::factory()->for($otherSeller)->create();

        DB::table('seller_staff_access')->insert([
            'user_id' => $user->id,
            'seller_id' => $seller->id,
            'branch_id' => $allowed->id,
            'role' => 'picker',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($user, ['seller']);
        $this->getJson('/api/v1/seller/branches')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $allowed->id);
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
