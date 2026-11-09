<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Day 1 regression checks for the existing cross-role API boundary.
 *
 * These tests intentionally verify today's routes without asserting that
 * the future Admin dispatch or Courier APIs already exist.
 */
class MarketplaceIntegrationAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_and_delivery_details_require_customer_authentication(): void
    {
        $this->getJson('/api/v1/orders')->assertUnauthorized();
        $this->getJson('/api/v1/orders/01J00000000000000000000000')->assertUnauthorized();
        $this->getJson('/api/v1/orders/01J00000000000000000000000/suborders/01J00000000000000000000000/delivery-code')
            ->assertUnauthorized();
        $this->postJson('/api/v1/checkout', [])->assertUnauthorized();
    }

    public function test_seller_operations_require_seller_scoped_token(): void
    {
        $customer = User::factory()->create();
        Sanctum::actingAs($customer, ['customer']);

        $this->getJson('/api/v1/seller/orders')->assertForbidden();
        $this->getJson('/api/v1/seller/stock')->assertForbidden();
        $this->patchJson('/api/v1/seller/orders/01J00000000000000000000000/status', [
            'status' => 'preparing',
        ])->assertForbidden();
    }

    public function test_review_queue_is_not_available_to_ungranted_customer(): void
    {
        $customer = User::factory()->create();
        Sanctum::actingAs($customer, ['customer']);

        $this->getJson('/api/v1/seller-review/applications')->assertForbidden();
        $this->getJson('/api/v1/seller-review/categories')->assertForbidden();
    }
}
