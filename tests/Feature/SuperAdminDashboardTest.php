<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuperAdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_view_super_admin_dashboard(): void
    {
        $this->getJson('/api/v1/platform/super-admin/dashboard')->assertUnauthorized();
    }

    public function test_regular_customer_cannot_view_super_admin_dashboard(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['customer']);
        $this->getJson('/api/v1/platform/super-admin/dashboard')->assertForbidden();
    }

    public function test_inactive_grant_cannot_view_super_admin_dashboard(): void
    {
        $user = User::factory()->create();
        DB::table('platform_super_admin_grants')->insert([
            'user_id' => $user->id, 'is_active' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Sanctum::actingAs($user, ['customer']);
        $this->getJson('/api/v1/platform/super-admin/dashboard')->assertForbidden();
    }

    public function test_super_admin_can_view_platform_statistics(): void
    {
        $user = User::factory()->create();
        DB::table('platform_super_admin_grants')->insert([
            'user_id' => $user->id, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Sanctum::actingAs($user, ['customer']);
        $this->getJson('/api/v1/platform/super-admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.customers', 1)
            ->assertJsonPath('data.sellers', 0)
            ->assertJsonPath('data.orders', 0)
            ->assertJsonPath('data.sales.currency', 'SAR')
            ->assertJsonStructure(['data' => [
                'customers', 'sellers', 'active_sellers', 'products',
                'published_products', 'orders', 'order_statuses',
                'suborder_statuses', 'pending_seller_applications',
                'pending_product_reviews', 'sales', 'generated_at',
            ]]);
    }
}
