<?php

namespace Tests\Feature\Checkout;

use App\Domain\Cart\Models\PromoCode;
use App\Domain\Orders\Models\Order;
use App\Domain\Payments\Contracts\PaymentGateway;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Gateways\FakeGateway;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentEvent;
use App\Domain\Payments\Models\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Support\CheckoutScenario;
use Tests\Support\FlakyGateway;
use Tests\TestCase;

/** What happens when payments do not arrive in the tidy order: retries, late duplicates, outages and early callbacks. */
class PaymentRobustnessTest extends TestCase
{
    use RefreshDatabase;

    private function flakyProvider(): FlakyGateway
    {
        $gateway = new FlakyGateway(new FakeGateway((string) config('store.payments.fake_webhook_secret')));
        $this->app->instance(PaymentGateway::class, $gateway);

        return $gateway;
    }

    /** A checkout whose first payment attempt was replaced by a second one (a declined or abandoned card, then another try). */
    private function orderWithTwoAttempts(CheckoutScenario $s): array
    {
        $quote = $s->quote();
        $first = $s->checkout($quote, 'idem-two-attempts')->assertCreated()->json('data');
        $this->postJson("/api/v1/orders/{$first['order']['id']}/payments", ['payment_method' => 'card'])->assertCreated();

        $payments = Payment::query()->where('order_id', $first['order']['id'])->orderBy('id')->get();

        return [$quote, $first['order']['id'], $payments[0], $payments[1]];
    }

    public function test_repeating_a_checkout_after_a_second_payment_attempt_returns_the_newest_attempt(): void
    {
        $s = (new CheckoutScenario($this))->withCart();
        [$quote, , , $second] = $this->orderWithTwoAttempts($s);

        $replay = $s->checkout($quote, 'idem-two-attempts')->assertOk();

        $this->assertSame($second->id, $replay->json('data.payment.id'));
    }

    public function test_cancelling_refunds_the_payment_that_confirmed_the_order_not_a_late_duplicate(): void
    {
        $s = (new CheckoutScenario($this))->withCart();
        [, $orderId, $first, $second] = $this->orderWithTwoAttempts($s);

        // The second attempt is paid and confirms the order. Then the first one, which we had given up on, is charged too.
        $s->chargeSucceeded($second->provider_reference, $second->amount_halalas, 'evt_second')->assertOk()->assertJsonPath('outcome', 'confirmed');
        $s->chargeSucceeded($first->provider_reference, $first->amount_halalas, 'evt_first')->assertOk()->assertJsonPath('outcome', 'late_payment_refunded');

        $this->postJson("/api/v1/orders/{$orderId}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(2, Refund::query()->count());
        $this->assertSame('late_payment', Refund::query()->where('payment_id', $first->id)->firstOrFail()->reason);
        $this->assertSame('customer_cancelled', Refund::query()->where('payment_id', $second->id)->firstOrFail()->reason);
    }

    public function test_cancelling_an_order_a_promo_covered_in_full_needs_no_refund(): void
    {
        PromoCode::query()->create(['code' => 'FREE', 'type' => 'fixed', 'value' => 100_000]);
        $s = (new CheckoutScenario($this))->withCart(2, 10);
        $s->catalog->zone->update(['delivery_fee_halalas' => 0]);
        $order = $s->checkout($s->quote('FREE'))->assertCreated()->assertJsonPath('data.order.status', 'confirmed')->json('data.order.id');

        $this->postJson("/api/v1/orders/{$order}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(10, $s->item['stock']->refresh()->on_hand);
    }

    public function test_a_refund_the_provider_could_not_take_stays_pending_and_is_sent_again_later(): void
    {
        $provider = $this->flakyProvider();
        $s = (new CheckoutScenario($this))->withCart();
        $order = $s->pay();
        $provider->refundsDown = true;

        // The cancellation stands even though the provider is down.
        $this->postJson("/api/v1/orders/{$order->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $refund = Refund::query()->firstOrFail();
        $this->assertNull($refund->provider_reference);
        $this->assertSame('pending', $refund->status->value);

        // Too soon to retry, then an outage that lasts, then the provider is back.
        $this->artisan('payments:retry-refunds')->assertSuccessful();
        $this->assertSame(1, $provider->refundCalls);

        $this->travel(2)->minutes();
        $this->artisan('payments:retry-refunds')->assertSuccessful();
        $this->assertSame(2, $provider->refundCalls);
        $this->assertNull($refund->refresh()->provider_reference);

        $provider->refundsDown = false;
        $this->artisan('payments:retry-refunds')->assertSuccessful();
        $this->assertNotNull($refund->refresh()->provider_reference);

        // A refund the provider already has is never sent twice.
        $this->artisan('payments:retry-refunds')->assertSuccessful();
        $this->assertSame(3, $provider->refundCalls);
    }

    public function test_a_refund_whose_send_fails_during_a_callback_does_not_make_the_callback_fail(): void
    {
        $provider = $this->flakyProvider();
        $s = (new CheckoutScenario($this))->withCart();
        $placed = $s->checkout()->assertCreated()->json('data');
        $payment = Payment::query()->findOrFail($placed['payment']['id']);
        $payment->update(['amount_halalas' => $payment->amount_halalas + 100]);
        $provider->refundsDown = true;

        // The provider says it charged the original amount: a mismatch, which we refund. The refund call fails.
        $s->chargeSucceeded($payment->provider_reference, $payment->amount_halalas - 100, 'evt_mismatch')
            ->assertOk()
            ->assertJsonPath('outcome', 'amount_mismatch_refunded');

        $this->assertNull(Refund::query()->firstOrFail()->provider_reference);
        $this->assertSame(PaymentStatus::Failed, $payment->refresh()->status);
    }

    public function test_a_callback_that_comes_before_we_know_the_charge_is_asked_to_come_again_and_not_remembered(): void
    {
        $s = (new CheckoutScenario($this))->withCart();
        $placed = $s->checkout()->assertCreated()->json('data');
        $payment = Payment::query()->findOrFail($placed['payment']['id']);
        $reference = $payment->provider_reference;
        $payment->update(['provider_reference' => null]);

        $s->chargeSucceeded($reference, $payment->amount_halalas, 'evt_early')->assertStatus(409)->assertJsonPath('outcome', 'unknown_payment');
        $this->assertSame(0, PaymentEvent::query()->count());
        $this->assertSame('pending_payment', Order::query()->firstOrFail()->status->value);

        // The provider sends it again once we have saved the reference.
        $payment->update(['provider_reference' => $reference]);
        $s->chargeSucceeded($reference, $payment->amount_halalas, 'evt_early')->assertOk()->assertJsonPath('outcome', 'confirmed');
    }

    public function test_credit_note_numbers_continue_from_the_highest_one_issued(): void
    {
        $s = (new CheckoutScenario($this))->withCart();
        $order = $s->pay();
        $this->postJson("/api/v1/orders/{$order->id}/cancel")->assertOk();
        $refund = Refund::query()->firstOrFail();

        $year = now()->format('Y');
        Refund::query()->forceCreate([
            'order_id' => $order->id, 'payment_id' => $refund->payment_id, 'status' => 'succeeded', 'amount_halalas' => 100,
            'reason' => 'earlier', 'requested_at' => now(), 'credit_note_number' => "CN-{$year}-000007",
        ]);

        $s->providerCallback(['id' => 'evt_refund', 'type' => 'refund.succeeded', 'reference' => $refund->provider_reference])->assertOk();

        $this->assertSame("CN-{$year}-000008", $refund->refresh()->credit_note_number);
    }

    public function test_stock_that_no_longer_matches_what_was_held_is_reported_but_the_paid_order_is_still_confirmed(): void
    {
        Log::spy();
        $s = (new CheckoutScenario($this))->withCart(2, 10);
        $placed = $s->checkout()->assertCreated()->json('data');
        $s->item['stock']->newQuery()->whereKey($s->item['stock']->id)->update(['reserved' => 0]);
        $payment = Payment::query()->findOrFail($placed['payment']['id']);

        $s->chargeSucceeded($payment->provider_reference, $payment->amount_halalas, 'evt_stock')->assertOk()->assertJsonPath('outcome', 'confirmed');

        Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'Stock commit'))->once();
    }
}
