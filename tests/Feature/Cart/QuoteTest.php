<?php

namespace Tests\Feature\Cart;

use App\Domain\Addressing\Models\Address;
use App\Domain\Cart\Models\PromoCode;
use App\Domain\Cart\Models\PromoRedemption;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CatalogBuilder;
use Tests\TestCase;

class QuoteTest extends TestCase
{
    use RefreshDatabase;

    private CatalogBuilder $catalog;

    private User $user;

    private Address $address;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalog = new CatalogBuilder;
        $this->user = $this->actingAsCustomer();
        $this->address = Address::factory()->create(['user_id' => $this->user->id, 'service_zone_id' => $this->catalog->zone->id]);
    }

    /** @param  array<string, mixed>  $product */
    private function addToCart(int $quantity, array $product = [], array $variant = [], int $onHand = 100): array
    {
        $built = $this->catalog->stockedProduct($onHand, $product, $variant);
        $this->putJson('/api/v1/cart/items/'.$built['variant']->id, ['quantity' => $quantity])->assertOk();

        return $built;
    }

    private function quote(array $body = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/quotes', ['address_id' => $this->address->id] + $body);
    }

    public function test_vat_is_reported_inside_the_gross_price_and_never_added_twice(): void
    {
        $this->addToCart(3);

        // 3 x 5.75 = 17.25 goods + 10.00 delivery (below the 50.00 free-delivery threshold) = 27.25.
        $this->quote()->assertCreated()
            ->assertJsonPath('data.subtotal', 1725)
            ->assertJsonPath('data.delivery_fee', 1000)
            ->assertJsonPath('data.total', 2725)
            ->assertJsonPath('data.vat_included', 355)
            ->assertJsonPath('data.vat.0.vat_class', 'standard')
            ->assertJsonPath('data.vat.0.gross', 2725)
            ->assertJsonPath('data.vat.0.taxable_net', 2370)
            ->assertJsonPath('data.can_checkout', true);
    }

    public function test_one_item_at_5_75_inside_a_free_delivery_zone_splits_into_5_00_and_0_75(): void
    {
        $this->catalog->zone->update(['delivery_fee_halalas' => 0]);
        $this->addToCart(1);

        $this->quote()->assertCreated()
            ->assertJsonPath('data.total', 575)
            ->assertJsonPath('data.vat.0.taxable_net', 500)
            ->assertJsonPath('data.vat.0.vat', 75);
    }

    public function test_delivery_is_free_above_the_threshold(): void
    {
        $this->addToCart(9); // 51.75

        $this->quote()->assertCreated()->assertJsonPath('data.delivery_fee', 0)->assertJsonPath('data.total', 5175);
    }

    public function test_a_price_typed_without_vat_is_charged_with_vat_added_once(): void
    {
        $this->catalog->zone->update(['delivery_fee_halalas' => 0]);
        $this->addToCart(1, ['price_includes_vat' => false], ['price_halalas' => 10_000]);

        $this->quote()->assertCreated()
            ->assertJsonPath('data.total', 11_500)
            ->assertJsonPath('data.vat_included', 1500);
    }

    public function test_zero_rated_goods_get_their_own_vat_line_with_no_vat(): void
    {
        $this->catalog->zone->update(['delivery_fee_halalas' => 0]);
        $this->addToCart(1);
        $this->addToCart(1, ['vat_class' => 'zero']);

        $response = $this->quote()->assertCreated()->assertJsonPath('data.total', 1150);

        $this->assertSame(['standard', 'zero'], collect($response->json('data.vat'))->pluck('vat_class')->all());
        $this->assertSame([75, 0], collect($response->json('data.vat'))->pluck('vat')->all());
    }

    public function test_a_percent_promo_is_taken_from_goods_only_and_spread_without_losing_a_halala(): void
    {
        PromoCode::query()->create(['code' => 'SAVE10', 'type' => 'percent', 'value' => 1000]);
        $this->addToCart(3);
        $this->addToCart(1);

        $response = $this->quote(['promo_code' => ' save10 '])->assertCreated()
            ->assertJsonPath('data.subtotal', 2300)
            ->assertJsonPath('data.discount', 230)
            ->assertJsonPath('data.promo_code', 'SAVE10');

        $this->assertSame(230, array_sum(array_column($response->json('data.lines'), 'discount')));
        $this->assertSame(2300 - 230 + $response->json('data.delivery_fee'), $response->json('data.total'));
    }

    public function test_a_fixed_promo_never_exceeds_the_goods_total(): void
    {
        PromoCode::query()->create(['code' => 'BIG', 'type' => 'fixed', 'value' => 100_000]);
        $this->catalog->zone->update(['delivery_fee_halalas' => 0]);
        $this->addToCart(2);

        $this->quote(['promo_code' => 'BIG'])->assertCreated()->assertJsonPath('data.discount', 1150)->assertJsonPath('data.total', 0);
    }

    public function test_a_percent_promo_respects_its_cap(): void
    {
        PromoCode::query()->create(['code' => 'HALF', 'type' => 'percent', 'value' => 5000, 'max_discount_halalas' => 300]);
        $this->addToCart(4);

        $this->quote(['promo_code' => 'HALF'])->assertCreated()->assertJsonPath('data.discount', 300);
    }

    public function test_invalid_promo_codes_are_refused_with_a_specific_reason(): void
    {
        $this->addToCart(1);
        PromoCode::query()->create(['code' => 'OFF', 'type' => 'fixed', 'value' => 100, 'is_active' => false]);
        PromoCode::query()->create(['code' => 'OLD', 'type' => 'fixed', 'value' => 100, 'ends_at' => now()->subDay()]);
        PromoCode::query()->create(['code' => 'SOON', 'type' => 'fixed', 'value' => 100, 'starts_at' => now()->addDay()]);
        PromoCode::query()->create(['code' => 'BIGONLY', 'type' => 'fixed', 'value' => 100, 'min_subtotal_halalas' => 10_000]);
        $used = PromoCode::query()->create(['code' => 'ONCE', 'type' => 'fixed', 'value' => 100, 'per_user_limit' => 1]);
        PromoRedemption::query()->create(['promo_code_id' => $used->id, 'user_id' => $this->user->id, 'discount_halalas' => 100]);
        $capped = PromoCode::query()->create(['code' => 'FIRST', 'type' => 'fixed', 'value' => 100, 'max_redemptions' => 1, 'per_user_limit' => 5]);
        PromoRedemption::query()->create(['promo_code_id' => $capped->id, 'user_id' => User::factory()->create()->id, 'discount_halalas' => 100]);

        foreach ([
            'NOPE' => 'promo_invalid', 'OFF' => 'promo_invalid', 'OLD' => 'promo_expired', 'SOON' => 'promo_not_started',
            'BIGONLY' => 'promo_min_subtotal', 'ONCE' => 'promo_already_used', 'FIRST' => 'promo_used_up',
        ] as $code => $reason) {
            $this->quote(['promo_code' => $code])->assertUnprocessable()->assertJsonPath('code', $reason);
        }
    }

    public function test_unavailable_lines_are_flagged_not_silently_dropped_and_block_checkout(): void
    {
        $this->addToCart(1);
        $this->addToCart(5, onHand: 2);

        $response = $this->quote()->assertCreated()
            ->assertJsonPath('data.can_checkout', false)
            ->assertJsonPath('data.issues.0.code', 'insufficient_stock')
            ->assertJsonPath('data.issues.0.available_units', 2)
            ->assertJsonCount(2, 'data.lines');

        $this->assertSame([true, false], array_column($response->json('data.lines'), 'available'));
        $this->assertSame(575, $response->json('data.subtotal'));
    }

    public function test_reserved_stock_is_not_available_to_quote(): void
    {
        $built = $this->addToCart(1, onHand: 3);
        $built['stock']->update(['reserved' => 3]);

        $this->quote()->assertCreated()->assertJsonPath('data.issues.0.code', 'unavailable');
    }

    public function test_an_empty_cart_or_an_address_outside_service_cannot_be_quoted(): void
    {
        $this->quote()->assertUnprocessable()->assertJsonPath('code', 'cart_empty');

        $this->addToCart(1);
        $outside = Address::factory()->outsideService()->create(['user_id' => $this->user->id]);
        $this->postJson('/api/v1/quotes', ['address_id' => $outside->id])->assertUnprocessable()->assertJsonPath('code', 'address_not_serviceable');
    }

    public function test_someone_elses_address_cannot_be_used(): void
    {
        $this->addToCart(1);
        $theirs = Address::factory()->create(['service_zone_id' => $this->catalog->zone->id]);

        $this->postJson('/api/v1/quotes', ['address_id' => $theirs->id])->assertNotFound();
    }

    public function test_a_quote_is_stored_with_an_expiry_and_a_fingerprint(): void
    {
        $this->addToCart(1);

        $id = $this->quote()->assertCreated()->json('data.id');

        $this->assertDatabaseHas('quotes', ['id' => $id, 'user_id' => $this->user->id, 'total_halalas' => 1575]);
        $this->assertTrue(\App\Domain\Cart\Models\QuoteRecord::query()->findOrFail($id)->expires_at->isFuture());
    }

    public function test_guessing_promo_codes_is_rate_limited(): void
    {
        $this->addToCart(1);

        foreach (range(1, 10) as $_) {
            $this->quote(['promo_code' => 'GUESS'])->assertUnprocessable();
        }

        $this->quote(['promo_code' => 'GUESS'])->assertStatus(429);
    }
}
