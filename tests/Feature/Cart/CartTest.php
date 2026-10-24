<?php

namespace Tests\Feature\Cart;

use App\Domain\Addressing\Models\Address;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CatalogBuilder;
use Tests\TestCase;

class CartTest extends TestCase
{
    use RefreshDatabase;

    private CatalogBuilder $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalog = new CatalogBuilder;
    }

    public function test_guests_have_no_server_cart(): void
    {
        $this->getJson('/api/v1/cart')->assertUnauthorized();
        $this->putJson('/api/v1/cart/items/1', ['quantity' => 1])->assertUnauthorized();
    }

    public function test_it_adds_changes_and_removes_a_line(): void
    {
        $this->actingAsCustomer();
        $item = $this->catalog->stockedProduct(product: ['slug' => 'tea']);
        $url = '/api/v1/cart/items/'.$item['variant']->id;

        $this->putJson($url, ['quantity' => 2])
            ->assertOk()
            ->assertJsonPath('data.items_count', 2)
            ->assertJsonPath('data.lines.0.unit_price', 575)
            ->assertJsonPath('data.lines.0.line_price', 1150)
            ->assertJsonPath('data.subtotal', 1150);

        $this->putJson($url, ['quantity' => 5])->assertJsonPath('data.subtotal', 2875);
        $this->putJson($url, ['quantity' => 0])->assertOk()->assertJsonPath('data.items_count', 0);
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_the_server_ignores_any_price_the_client_tries_to_send(): void
    {
        $this->actingAsCustomer();
        $item = $this->catalog->stockedProduct();

        $this->putJson('/api/v1/cart/items/'.$item['variant']->id, ['quantity' => 1, 'unit_price' => 1, 'price' => 1])
            ->assertOk()->assertJsonPath('data.subtotal', 575);
    }

    public function test_quantity_limits_and_unbuyable_variants_are_refused(): void
    {
        $this->actingAsCustomer();
        $ok = $this->catalog->stockedProduct();
        $draft = $this->catalog->stockedProduct(product: ['status' => 'draft']);

        $this->putJson('/api/v1/cart/items/'.$ok['variant']->id, ['quantity' => 21])->assertUnprocessable()->assertJsonPath('code', 'quantity_out_of_range');
        $this->putJson('/api/v1/cart/items/'.$ok['variant']->id, ['quantity' => -1])->assertUnprocessable();
        $this->putJson('/api/v1/cart/items/'.$draft['variant']->id, ['quantity' => 1])->assertNotFound()->assertJsonPath('code', 'variant_unavailable');
        $this->putJson('/api/v1/cart/items/999999', ['quantity' => 1])->assertNotFound();
    }

    public function test_carts_are_private_to_each_user(): void
    {
        $item = $this->catalog->stockedProduct();
        $this->actingAsCustomer();
        $this->putJson('/api/v1/cart/items/'.$item['variant']->id, ['quantity' => 3])->assertOk();

        $this->actingAsCustomer(User::factory()->create());
        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.items_count', 0);
    }

    public function test_a_guest_cart_is_merged_after_login_skipping_stale_lines_and_capping_quantities(): void
    {
        $this->actingAsCustomer();
        $one = $this->catalog->stockedProduct();
        $two = $this->catalog->stockedProduct();
        $gone = $this->catalog->stockedProduct(product: ['status' => 'draft']);

        $this->putJson('/api/v1/cart/items/'.$one['variant']->id, ['quantity' => 19])->assertOk();

        $this->postJson('/api/v1/cart/merge', ['items' => [
            ['variant_id' => $one['variant']->id, 'quantity' => 5],
            ['variant_id' => $two['variant']->id, 'quantity' => 2],
            ['variant_id' => $gone['variant']->id, 'quantity' => 1],
            ['variant_id' => 999999, 'quantity' => 1],
        ]])->assertOk()
            ->assertJsonCount(2, 'data.lines')
            ->assertJsonPath('data.lines.0.quantity', 20)
            ->assertJsonPath('data.lines.1.quantity', 2);
    }

    public function test_the_cart_shows_per_line_availability_for_an_address_zone(): void
    {
        $user = $this->actingAsCustomer();
        $few = $this->catalog->stockedProduct(onHand: 1);
        $address = Address::factory()->create(['user_id' => $user->id, 'service_zone_id' => $this->catalog->zone->id]);
        $this->putJson('/api/v1/cart/items/'.$few['variant']->id, ['quantity' => 1])->assertOk();

        $this->getJson('/api/v1/cart')->assertJsonPath('data.lines.0.availability', 'unknown');
        $this->getJson('/api/v1/cart?address_id='.$address->id)->assertJsonPath('data.lines.0.availability', 'low_stock');
    }

    public function test_it_clears_the_cart(): void
    {
        $this->actingAsCustomer();
        $item = $this->catalog->stockedProduct();
        $this->putJson('/api/v1/cart/items/'.$item['variant']->id, ['quantity' => 1])->assertOk();

        $this->deleteJson('/api/v1/cart')->assertNoContent();
        $this->getJson('/api/v1/cart')->assertJsonPath('data.items_count', 0);
    }
}
