<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CheckoutScenario;
use Tests\TestCase;

class CustomerShoppingTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_details_are_validated_scoped_and_snapshotted_on_the_order(): void
    {
        $s = (new CheckoutScenario($this))->withCart();
        $fields = ['buyer_type' => 'business', 'company_name' => 'Buyer Company', 'registration_number' => '7001234567', 'vat_number' => '300000000000003', 'billing_address' => 'Riyadh, Building 1234'];
        $this->putJson('/api/v1/me/billing-profile', $fields)->assertOk();
        $this->putJson('/api/v1/me/billing-profile', array_replace($fields, ['vat_number' => '123']))->assertUnprocessable();
        $id = $s->checkout(method: 'cash_on_delivery')->json('data.order.id');
        $this->putJson('/api/v1/me/billing-profile', array_replace($fields, ['company_name' => 'Changed']))->assertOk();
        $this->getJson('/api/v1/orders/'.$id)->assertOk()->assertJsonPath('data.billing_details.company_name', 'Buyer Company');
        $this->actingAsCustomer(User::factory()->create());
        $this->getJson('/api/v1/me/billing-profile')->assertOk()->assertJsonPath('data.company_name', null);
        $this->getJson('/api/v1/orders/'.$id)->assertNotFound();
    }

    public function test_saved_products_are_idempotent_private_and_removable(): void
    {
        $s = (new CheckoutScenario($this))->withCart();
        $slug = $s->item['product']->slug;
        $this->putJson('/api/v1/me/saved-products/'.$slug)->assertOk();
        $this->putJson('/api/v1/me/saved-products/'.$slug)->assertOk();
        $this->getJson('/api/v1/me/saved-products')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAsCustomer(User::factory()->create());
        $this->getJson('/api/v1/me/saved-products')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson('/api/v1/me/saved-products/'.$slug)->assertOk();
        $this->assertDatabaseCount('customer_saved_products', 1);
        $this->actingAsCustomer($s->user);
        $this->deleteJson('/api/v1/me/saved-products/'.$slug)->assertOk();
        $this->assertDatabaseCount('customer_saved_products', 0);
    }

    public function test_customer_requests_retry_safely_and_other_customers_never_see_them(): void
    {
        $user = User::factory()->create();
        $this->actingAsCustomer($user);
        $fields = ['request_key' => 'request-0001', 'kind' => 'bulk_quote', 'subject' => 'Need 100 towels', 'description' => '100 cotton towels delivered to Riyadh next month'];
        $this->postJson('/api/v1/me/requests', $fields)->assertCreated();
        $this->postJson('/api/v1/me/requests', $fields)->assertOk();
        $this->postJson('/api/v1/me/requests', array_replace($fields, ['subject' => 'Different']))->assertConflict();
        $this->getJson('/api/v1/me/requests')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAsCustomer(User::factory()->create());
        $this->getJson('/api/v1/me/requests')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('customer_requests', 1);
    }

    public function test_product_filters_combine_seller_category_budget_and_stock(): void
    {
        $s = (new CheckoutScenario($this))->withCart();
        $item = $s->item;
        $this->getJson('/api/v1/products?max_price=500')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/products?sort=rating&in_stock=1&zone_id='.$s->catalog->zone->id)->assertOk()->assertJsonCount(1, 'data');
        $item['stock']->update(['on_hand' => 0]);
        $this->getJson('/api/v1/products?in_stock=1&zone_id='.$s->catalog->zone->id)->assertOk()->assertJsonCount(0, 'data');
    }
}
