<?php

namespace Tests\Feature\Checkout;

use App\Domain\Cart\Models\PromoCode;
use App\Domain\Cart\Models\PromoRedemption;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\DeliverySlot;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\StockReservation;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\CheckoutScenario;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(int $quantity = 1, int $onHand = 100, array $product = [], array $variant = []): CheckoutScenario
    {
        return (new CheckoutScenario($this))->withCart($quantity, $onHand, $product, $variant);
    }

    public function test_checkout_creates_an_unpaid_order_that_holds_stock_and_a_slot_but_confirms_nothing(): void
    {
        $s = $this->scenario(quantity: 2, onHand: 10);

        $response = $s->checkout()->assertCreated()
            ->assertJsonPath('data.order.status', 'pending_payment')
            ->assertJsonPath('data.order.amounts.subtotal', 1150)
            ->assertJsonPath('data.order.suborders.0.status', 'awaiting_payment')
            ->assertJsonPath('data.payment.status', 'pending')
            ->assertJsonPath('data.payment.method', 'mada')
            ->assertJsonPath('data.replayed', false);

        $this->assertNotEmpty($response->json('data.payment.action.client_secret'));
        $this->assertMatchesRegularExpression('/^A575-[A-Z0-9]{8}$/', $response->json('data.order.number'));

        $stock = $s->item['stock']->refresh();
        $this->assertSame(10, $stock->on_hand);
        $this->assertSame(2, $stock->reserved);
        $this->assertSame(1, $s->slot->refresh()->booked);
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_the_order_keeps_its_own_copy_of_the_address_and_prices(): void
    {
        $s = $this->scenario();
        $orderId = $s->checkout()->assertCreated()->json('data.order.id');

        $s->address->update(['street' => 'Changed Street']);
        $s->item['variant']->update(['price_halalas' => 9999]);

        $this->getJson('/api/v1/orders/'.$orderId)->assertOk()
            ->assertJsonPath('data.address.street', 'King Fahd Road')
            ->assertJsonPath('data.suborders.0.items.0.unit_price', 575);
    }

    public function test_coming_back_to_the_app_proves_nothing_only_the_signed_callback_confirms_payment(): void
    {
        $s = $this->scenario();
        $payment = $s->checkout()->json('data.payment');

        $this->getJson('/api/v1/payments/'.$payment['id'])->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertSame('pending_payment', Order::query()->firstOrFail()->status->value);

        $reference = Payment::query()->firstOrFail()->provider_reference;
        $s->chargeSucceeded($reference, $payment['amount'])->assertOk()->assertJsonPath('outcome', 'confirmed');

        $order = Order::query()->firstOrFail();
        $this->assertSame('confirmed', $order->status->value);
        $this->assertNotNull($order->paid_at);
        $this->assertSame('paid', Payment::query()->firstOrFail()->status->value);
        $this->getJson('/api/v1/payments/'.$payment['id'])->assertJsonPath('data.status', 'paid');
    }

    public function test_confirmation_moves_stock_off_the_shelf_clears_the_cart_and_notifies_sellers_via_status(): void
    {
        $s = $this->scenario(quantity: 3, onHand: 10);
        $payment = $s->checkout()->json('data.payment');
        $s->chargeSucceeded(Payment::query()->firstOrFail()->provider_reference, $payment['amount'])->assertOk();

        $stock = $s->item['stock']->refresh();
        $this->assertSame(7, $stock->on_hand);
        $this->assertSame(0, $stock->reserved);
        $this->assertSame('committed', StockReservation::query()->firstOrFail()->status);
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertSame('awaiting_seller', Order::query()->firstOrFail()->suborders->first()->status->value);
        $this->assertSame(['order_placed', 'payment_confirmed', 'order_confirmed'], Order::query()->firstOrFail()->events->pluck('type')->all());
    }

    public function test_a_callback_delivered_twice_is_applied_once(): void
    {
        $s = $this->scenario();
        $payment = $s->checkout()->json('data.payment');
        $reference = Payment::query()->firstOrFail()->provider_reference;

        $s->chargeSucceeded($reference, $payment['amount'], 'evt_dup')->assertOk()->assertJsonPath('outcome', 'confirmed');
        $s->chargeSucceeded($reference, $payment['amount'], 'evt_dup')->assertOk()->assertJsonPath('outcome', 'duplicate');

        $this->assertDatabaseCount('payment_events', 1);
        $this->assertSame(1, Order::query()->firstOrFail()->events()->where('type', 'payment_confirmed')->count());
    }

    public function test_a_second_success_callback_for_an_already_paid_payment_changes_nothing(): void
    {
        $s = $this->scenario();
        $payment = $s->checkout()->json('data.payment');
        $reference = Payment::query()->firstOrFail()->provider_reference;

        $s->chargeSucceeded($reference, $payment['amount'], 'evt_a')->assertOk();
        $s->chargeSucceeded($reference, $payment['amount'], 'evt_b')->assertOk()->assertJsonPath('outcome', 'already_paid');
    }

    public function test_unsigned_or_tampered_callbacks_are_rejected_and_change_nothing(): void
    {
        $s = $this->scenario();
        $payment = $s->checkout()->json('data.payment');
        $reference = Payment::query()->firstOrFail()->provider_reference;

        $s->providerCallback(['id' => 'evt_x', 'type' => 'charge.succeeded', 'reference' => $reference, 'amount' => $payment['amount']], 'not-the-signature')->assertStatus(400);
        $this->postJson('/api/v1/webhooks/payments/fake', ['id' => 'evt_y', 'type' => 'charge.succeeded', 'reference' => $reference])->assertStatus(400);
        $this->postJson('/api/v1/webhooks/payments/other', [])->assertNotFound();

        $this->assertSame('pending_payment', Order::query()->firstOrFail()->status->value);
        $this->assertDatabaseCount('payment_events', 0);
    }

    public function test_repeating_a_checkout_with_the_same_key_returns_the_same_order(): void
    {
        $s = $this->scenario(onHand: 10);
        $quote = $s->quote();

        $first = $s->checkout($quote, 'idem-key-same')->assertCreated();
        $second = $s->checkout($quote, 'idem-key-same')->assertOk()->assertJsonPath('data.replayed', true);

        $this->assertSame($first->json('data.order.id'), $second->json('data.order.id'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('stock_reservations', 1);
        $this->assertSame(1, $s->item['stock']->refresh()->reserved);
        $this->assertSame(1, $s->slot->refresh()->booked);
    }

    public function test_the_same_key_cannot_be_reused_for_a_different_quote(): void
    {
        $s = $this->scenario(onHand: 10);
        $s->checkout($s->quote(), 'idem-key-one')->assertCreated();

        $s->checkout($s->quote(), 'idem-key-one')->assertUnprocessable()->assertJsonPath('code', 'idempotency_key_reused');
    }

    public function test_the_idempotency_key_is_required_and_well_formed(): void
    {
        $s = $this->scenario();
        $quote = $s->quote();

        $this->postJson('/api/v1/checkout', ['quote_id' => $quote, 'slot_id' => $s->slot->id, 'payment_method' => 'mada'])
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $s->checkout($quote, 'short')->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    }

    public function test_only_supported_payment_methods_are_accepted_cash_on_delivery_is_not(): void
    {
        $s = $this->scenario();

        $s->checkout(method: 'cash_on_delivery')->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $s->checkout(method: 'apple_pay', key: 'idem-apple-01')->assertCreated();
    }

    public function test_an_expired_quote_must_be_reviewed_again(): void
    {
        $s = $this->scenario();
        $quote = $s->quote();

        $this->travel(11)->minutes();

        $s->checkout($quote)->assertUnprocessable()->assertJsonPath('code', 'quote_expired');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_price_change_after_the_quote_blocks_checkout_and_returns_the_new_quote(): void
    {
        $s = $this->scenario();
        $quote = $s->quote();
        $s->item['variant']->update(['price_halalas' => 700]);

        $response = $s->checkout($quote)->assertStatus(409)->assertJsonPath('code', 'quote_changed')->assertJsonPath('quote.subtotal', 700);

        $this->assertNotSame($quote, $response->json('quote.id'));
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, $s->item['stock']->refresh()->reserved);
    }

    public function test_items_that_sell_out_between_quote_and_checkout_block_it(): void
    {
        $s = $this->scenario(quantity: 2, onHand: 5);
        $quote = $s->quote();
        $s->item['stock']->update(['on_hand' => 1]);

        $s->checkout($quote)->assertStatus(409)->assertJsonPath('code', 'cart_has_unavailable_items');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_the_last_units_cannot_be_oversold(): void
    {
        $catalog = new \Tests\Support\CatalogBuilder;
        $first = (new CheckoutScenario($this, $catalog))->withCart(1, onHand: 2);
        $item = $first->item;

        $buyers = [$first];
        foreach (range(2, 4) as $_) {
            $buyer = new CheckoutScenario($this, $catalog, slot: $first->slot);
            $buyer->item = $item;
            $this->actingAsCustomer($buyer->user);
            $this->putJson('/api/v1/cart/items/'.$item['variant']->id, ['quantity' => 1])->assertOk();
            $buyers[] = $buyer;
        }

        // Everyone priced their cart while stock was still there; now they all press pay.
        $quotes = array_map(fn (CheckoutScenario $buyer): string => $buyer->quote(), $buyers);
        $statuses = [];
        foreach ($buyers as $i => $buyer) {
            $statuses[] = $buyer->checkout($quotes[$i], 'idem-buyer-'.$i)->status();
        }

        $this->assertSame([201, 201, 409, 409], $statuses);
        $this->assertSame(2, $item['stock']->refresh()->reserved);
        $this->assertSame(2, Order::query()->count());
    }

    public function test_delivery_slots_have_capacity_and_a_lead_time(): void
    {
        $s = $this->scenario(onHand: 50);
        $full = CheckoutScenario::slotFor($s->catalog->zone, capacity: 1, hoursAhead: 6);
        $full->update(['booked' => 1]);
        $soon = DeliverySlot::query()->create(['service_zone_id' => $s->catalog->zone->id, 'starts_at' => now()->addMinutes(20), 'ends_at' => now()->addHours(2), 'capacity' => 5]);
        $otherZone = CheckoutScenario::slotFor(\App\Domain\Catalog\Models\ServiceZone::factory()->create());

        $s->checkout(slotId: $full->id, key: 'idem-slot-full')->assertStatus(409)->assertJsonPath('code', 'slot_full');
        $s->checkout(slotId: $soon->id, key: 'idem-slot-soon')->assertStatus(409)->assertJsonPath('code', 'slot_too_soon');
        $s->checkout(slotId: $otherZone->id, key: 'idem-slot-zone')->assertUnprocessable()->assertJsonPath('code', 'slot_invalid');
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, $s->item['stock']->refresh()->reserved);
    }

    public function test_a_declined_card_leaves_the_order_open_for_another_try_which_can_succeed(): void
    {
        $s = $this->scenario(quantity: 2, onHand: 10);
        $placed = $s->checkout()->assertCreated()->json('data');
        $first = Payment::query()->firstOrFail();

        $s->providerCallback(['id' => 'evt_f', 'type' => 'charge.failed', 'reference' => $first->provider_reference, 'failure_code' => 'card_declined'])
            ->assertOk()->assertJsonPath('outcome', 'payment_failed');

        $this->assertSame('failed', $first->refresh()->status->value);
        $this->assertSame('pending_payment', Order::query()->firstOrFail()->status->value);

        $retry = $this->postJson('/api/v1/orders/'.$placed['order']['id'].'/payments', ['payment_method' => 'card'])->assertCreated()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.method', 'card');
        $this->assertNotEmpty($retry->json('data.action.client_secret'));

        $second = Payment::query()->latest('created_at')->latest('id')->firstOrFail();
        $this->assertNotSame($first->id, $second->id);
        $s->chargeSucceeded($second->provider_reference, $second->amount_halalas, 'evt_ok')->assertOk()->assertJsonPath('outcome', 'confirmed');
        $this->assertSame(8, $s->item['stock']->refresh()->on_hand);
    }

    public function test_unpaid_orders_expire_and_give_back_stock_slot_and_promo(): void
    {
        $promo = PromoCode::query()->create(['code' => 'SAVE10', 'type' => 'percent', 'value' => 1000]);
        $s = $this->scenario(quantity: 2, onHand: 10);
        $s->checkout($s->quote('SAVE10'))->assertCreated();

        $this->assertSame(1, PromoRedemption::query()->count());
        $this->assertSame(2, $s->item['stock']->refresh()->reserved);

        $this->travel(16)->minutes();
        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $order = Order::query()->firstOrFail();
        $this->assertSame('cancelled', $order->status->value);
        $this->assertSame('payment_timeout', $order->cancel_reason);
        $this->assertSame(0, $s->item['stock']->refresh()->reserved);
        $this->assertSame(10, $s->item['stock']->on_hand);
        $this->assertSame(0, $s->slot->refresh()->booked);
        $this->assertSame(0, PromoRedemption::query()->count());
        $this->assertSame('expired', Payment::query()->firstOrFail()->status->value);
        $this->assertSame('released', StockReservation::query()->firstOrFail()->status);
        $this->assertDatabaseCount('cart_items', 1);
        $this->assertNotNull($promo->refresh());
    }

    public function test_orders_still_within_their_hold_are_not_expired(): void
    {
        $s = $this->scenario();
        $s->checkout()->assertCreated();

        $this->travel(5)->minutes();
        Artisan::call('orders:expire-unpaid');

        $this->assertSame('pending_payment', Order::query()->firstOrFail()->status->value);
    }

    public function test_money_that_arrives_after_the_hold_expired_is_refunded_not_confirmed(): void
    {
        $s = $this->scenario(quantity: 2, onHand: 10);
        $payment = $s->checkout()->json('data.payment');
        $reference = Payment::query()->firstOrFail()->provider_reference;

        $this->travel(16)->minutes();
        Artisan::call('orders:expire-unpaid');

        $s->chargeSucceeded($reference, $payment['amount'], 'evt_late')->assertOk()->assertJsonPath('outcome', 'late_payment_refunded');

        $this->assertSame('cancelled', Order::query()->firstOrFail()->status->value);
        $this->assertSame(10, $s->item['stock']->refresh()->on_hand);
        $refund = Refund::query()->firstOrFail();
        $this->assertSame($payment['amount'], $refund->amount_halalas);
        $this->assertSame('late_payment', $refund->reason);
        $this->assertSame('pending', $refund->status->value);
        $this->assertNotNull($refund->provider_reference);
    }

    public function test_a_wrong_amount_is_never_accepted_and_is_refunded(): void
    {
        $s = $this->scenario();
        $s->checkout()->assertCreated();
        $reference = Payment::query()->firstOrFail()->provider_reference;

        $s->chargeSucceeded($reference, 100, 'evt_short')->assertOk()->assertJsonPath('outcome', 'amount_mismatch_refunded');

        $this->assertSame('pending_payment', Order::query()->firstOrFail()->status->value);
        $this->assertSame('failed', Payment::query()->firstOrFail()->status->value);
        $this->assertSame(100, Refund::query()->firstOrFail()->amount_halalas);
    }

    public function test_a_refund_counts_as_done_only_when_the_provider_confirms_it(): void
    {
        $s = $this->scenario();
        $payment = $s->checkout()->json('data.payment');
        $reference = Payment::query()->firstOrFail()->provider_reference;
        $this->travel(16)->minutes();
        Artisan::call('orders:expire-unpaid');
        $s->chargeSucceeded($reference, $payment['amount'], 'evt_late2')->assertOk();

        $refund = Refund::query()->firstOrFail();
        $this->assertNull($refund->credit_note_number);

        $s->providerCallback(['id' => 'evt_refund', 'type' => 'refund.succeeded', 'reference' => $refund->provider_reference])
            ->assertOk()->assertJsonPath('outcome', 'refund_succeeded');

        $refund->refresh();
        $this->assertSame('succeeded', $refund->status->value);
        $this->assertMatchesRegularExpression('/^CN-\d{4}-\d{6}$/', $refund->credit_note_number);
        $this->assertNotNull($refund->completed_at);
    }

    public function test_an_order_fully_covered_by_a_promo_needs_no_payment(): void
    {
        PromoCode::query()->create(['code' => 'FREE', 'type' => 'fixed', 'value' => 100_000]);
        $s = $this->scenario();
        $s->catalog->zone->update(['delivery_fee_halalas' => 0]);

        $this->actingAsCustomer($s->user);
        $response = $s->checkout($s->quote('FREE'))->assertCreated()
            ->assertJsonPath('data.order.status', 'confirmed')
            ->assertJsonPath('data.order.amounts.total', 0)
            ->assertJsonPath('data.payment', null);

        $this->assertSame(99, $s->item['stock']->refresh()->on_hand);
        $this->assertNotNull($response->json('data.order.paid_at'));
    }

    public function test_when_the_payment_provider_is_not_configured_nothing_is_left_held(): void
    {
        config(['store.payments.driver' => 'unconfigured']);
        $s = $this->scenario(quantity: 2, onHand: 10);

        $s->checkout()->assertStatus(503)->assertJsonPath('code', 'payments_unavailable');

        $this->assertSame(0, $s->item['stock']->refresh()->reserved);
        $this->assertSame(0, $s->slot->refresh()->booked);
        $this->assertSame('cancelled', Order::query()->firstOrFail()->status->value);
    }

    public function test_nobody_else_can_see_or_retry_someone_elses_payment(): void
    {
        $s = $this->scenario();
        $placed = $s->checkout()->json('data');

        $this->actingAsCustomer(User::factory()->create());
        $this->getJson('/api/v1/payments/'.$placed['payment']['id'])->assertNotFound();
        $this->postJson('/api/v1/orders/'.$placed['order']['id'].'/payments', ['payment_method' => 'card'])->assertNotFound();
    }

    public function test_guests_cannot_check_out(): void
    {
        $this->postJson('/api/v1/checkout', [])->assertUnauthorized();
        $this->getJson('/api/v1/delivery/slots')->assertUnauthorized();
    }

    public function test_slots_for_an_address_list_only_future_bookable_windows(): void
    {
        $s = $this->scenario();
        DeliverySlot::query()->create(['service_zone_id' => $s->catalog->zone->id, 'starts_at' => now()->addMinutes(10), 'ends_at' => now()->addHours(2), 'capacity' => 5]);

        $response = $this->getJson('/api/v1/delivery/slots?address_id='.$s->address->id)->assertOk();

        $this->assertSame([$s->slot->id], array_column($response->json('data'), 'id'));
        $this->assertSame(20, $response->json('data.0.remaining'));
    }

    public function test_the_slot_generator_creates_windows_once(): void
    {
        $zone = \App\Domain\Catalog\Models\ServiceZone::factory()->create();

        $this->artisan('slots:generate')->assertSuccessful();
        $count = DeliverySlot::query()->where('service_zone_id', $zone->id)->count();
        $this->artisan('slots:generate')->assertSuccessful();

        $this->assertGreaterThan(20, $count);
        $this->assertSame($count, DeliverySlot::query()->where('service_zone_id', $zone->id)->count());
    }
}
