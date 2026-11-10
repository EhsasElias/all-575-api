<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Catalog\Models\Seller;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformAdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        DB::table('platform_super_admin_grants')->insert([
            'user_id' => $user->id, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $user;
    }

    public function test_guest_and_customer_cannot_access_admin_dashboard(): void
    {
        $this->getJson('/api/v1/platform/admin/dashboard')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(), ['customer']);
        $this->getJson('/api/v1/platform/admin/dashboard')->assertForbidden();
    }

    public function test_customer_cannot_open_admin_order_queue(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['customer']);
        $this->getJson('/api/v1/platform/admin/orders')->assertForbidden();
    }

    public function test_admin_without_order_permission_cannot_open_order_queue(): void
    {
        $seller = Seller::factory()->create();
        $admin = User::factory()->create(['email_verified_at' => now()]);
        DB::table('platform_admin_access')->insert([
            'user_id' => $admin->id, 'seller_id' => $seller->id,
            'is_active' => true,
            'can_view_orders' => false, 'can_view_inventory' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Sanctum::actingAs($admin, ['customer']);
        $this->getJson('/api/v1/platform/admin/orders')->assertForbidden();
        $this->getJson('/api/v1/platform/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.order_access_sellers', 0);
    }

    public function test_only_owner_can_assign_admin_access(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['customer']);
        $this->getJson('/api/v1/platform/super-admin/admins')->assertForbidden();
        $this->postJson('/api/v1/platform/super-admin/admins', [])->assertForbidden();
    }

    public function test_admin_grant_is_limited_to_assigned_seller(): void
    {
        $seller = Seller::factory()->create();
        Seller::factory()->create();
        $admin = User::factory()->create(['email_verified_at' => now()]);
        Sanctum::actingAs($this->owner(), ['customer']);

        $this->postJson('/api/v1/platform/super-admin/admins', [
            'email' => $admin->email, 'seller_id' => $seller->id,
            'can_view_orders' => true, 'can_view_inventory' => true,
        ])->assertCreated();

        Sanctum::actingAs($admin, ['customer']);
        $this->getJson('/api/v1/platform/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.assigned_sellers', 1)
            ->assertJsonPath('data.suborders', 0)
            ->assertJsonPath('data.stock_records', 0);
    }

    public function test_super_admin_can_access_existing_review_tools(): void
    {
        Sanctum::actingAs($this->owner(), ['customer']);
        $this->getJson('/api/v1/seller-review/applications')->assertOk();
        $this->getJson('/api/v1/seller-review/categories')->assertOk();
    }

    public function test_seller_only_token_cannot_view_platform_admin_dashboard(): void
    {
        $seller = Seller::factory()->create();
        $admin = User::factory()->create();
        DB::table('platform_admin_access')->insert([
            'user_id' => $admin->id, 'seller_id' => $seller->id,
            'is_active' => true, 'can_view_orders' => true,
            'can_view_inventory' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Sanctum::actingAs($admin, ['seller']);
        $this->getJson('/api/v1/platform/admin/dashboard')->assertForbidden();
    }

    public function test_revoked_admin_is_forbidden(): void
    {
        $seller = Seller::factory()->create();
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $owner = $this->owner();
        Sanctum::actingAs($owner, ['customer']);
        $this->postJson('/api/v1/platform/super-admin/admins', [
            'email' => $admin->email, 'seller_id' => $seller->id,
            'can_view_orders' => true, 'can_view_inventory' => false,
        ])->assertCreated();

        $id = DB::table('platform_admin_access')->where('user_id', $admin->id)->value('id');
        $this->patchJson('/api/v1/platform/super-admin/admins/'.$id, ['is_active' => false])->assertOk();
        Sanctum::actingAs($admin, ['customer']);
        $this->getJson('/api/v1/platform/admin/dashboard')->assertForbidden();
    }
}
