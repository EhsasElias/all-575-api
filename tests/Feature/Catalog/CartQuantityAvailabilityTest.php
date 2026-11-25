<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Addressing\Models\Address;
use App\Domain\Cart\Models\Cart;
use App\Domain\Catalog\Models\Branch;
use App\Domain\Catalog\Models\BranchStock;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\ServiceZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CartQuantityAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_cart_flags_a_line_unavailable_before_checkout_when_quantity_exceeds_stock(): void
    {
        $user = $this->actingAsCustomer();
        $zone = ServiceZone::factory()->create();
        $address = Address::factory()->create(['user_id' => $user->id, 'service_zone_id' => $zone->id]);
        $product = Product::factory()->create(['price_halalas' => 300]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $branch = Branch::factory()->create(['seller_id' => $product->seller_id, 'service_zone_id' => $zone->id]);

        BranchStock::factory()->create([
            'branch_id' => $branch->id, 'variant_id' => $variant->id, 'on_hand' => 2, 'reserved' => 0,
        ]);
        Cart::forUser($user)->items()->create(['variant_id' => $variant->id, 'quantity' => 3]);

        // The address is no longer required to determine sellable stock.
        $this->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.lines.0.availability', 'out_of_stock');

        $this->getJson('/api/v1/cart?address_id='.$address->id)
            ->assertOk()
            ->assertJsonPath('data.lines.0.availability', 'out_of_stock');

        Cart::forUser($user)->items()->where('variant_id', $variant->id)->update(['quantity' => 2]);

        $this->getJson('/api/v1/cart?address_id='.$address->id)
            ->assertOk()
            ->assertJsonPath('data.lines.0.availability', 'low_stock');
    }
}
