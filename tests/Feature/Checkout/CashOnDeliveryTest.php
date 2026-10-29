<?php

namespace Tests\Feature\Checkout;

use App\Domain\Orders\Enums\SuborderStatus;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\SuborderTransitions;
use App\Domain\Payments\Services\CashCollection;
use App\Support\Exceptions\DomainRuleViolation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CheckoutScenario;
use Tests\TestCase;

class CashOnDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_order_commits_stock_and_clears_cart_without_marking_money_paid_or_opening_a_bank_session(): void
    {
        $s = (new CheckoutScenario($this))->withCart(2, 10);
        $quote = $s->quote();
        $first = $s->checkout($quote, method: 'cash_on_delivery')->assertCreated()
            ->assertJsonPath('data.order.status', 'confirmed')->assertJsonPath('data.order.paid_at', null)
            ->assertJsonPath('data.order.suborders.0.status', 'awaiting_seller')
            ->assertJsonPath('data.payment.status', 'pending')->assertJsonPath('data.payment.action', null);
        $this->assertSame(8, $s->item['stock']->refresh()->on_hand);
        $this->assertSame(0, $s->item['stock']->reserved);
        $this->assertDatabaseCount('cart_items', 0);
        $s->checkout($quote, method: 'cash_on_delivery')->assertOk()->assertJsonPath('data.order.id', $first->json('data.order.id'));
        $this->travel(20)->minutes();
        $this->artisan('orders:expire-unpaid')->assertSuccessful();
        $this->assertSame('confirmed', Order::first()->status->value);
    }

    public function test_cancelling_uncollected_cash_restores_stock_and_slot_without_creating_a_refund(): void
    {
        $s = (new CheckoutScenario($this))->withCart(2, 10);
        $order = $s->checkout(method: 'cash_on_delivery')->json('data.order.id');
        $this->postJson('/api/v1/orders/'.$order.'/cancel')->assertOk();
        $this->assertSame(10, $s->item['stock']->refresh()->on_hand);
        $this->assertSame(0, $s->slot->refresh()->booked);
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_cash_is_recorded_only_after_delivery_with_the_exact_amount_and_is_idempotent(): void
    {
        $s = (new CheckoutScenario($this))->withCart();
        $id = $s->checkout(method: 'cash_on_delivery')->json('data.order.id');
        $order = Order::findOrFail($id);
        try {
            app(CashCollection::class)->record($order, $order->total_halalas, 'receipt-0001');
            $this->fail('Undelivered order accepted cash');
        } catch (DomainRuleViolation) {
            $this->assertNull($order->refresh()->paid_at);
        }
        foreach ([SuborderStatus::Preparing, SuborderStatus::Ready, SuborderStatus::OnTheWay, SuborderStatus::Delivered] as $status) {
            foreach ($order->suborders as $suborder) {
                app(SuborderTransitions::class)->move($suborder, $status, 'test');
            }
        }
        $this->expectException(DomainRuleViolation::class);
        app(CashCollection::class)->record($order, 1, 'receipt-wrong');
    }

    public function test_a_delivered_cash_receipt_is_not_counted_twice(): void
    {
        $s = (new CheckoutScenario($this))->withCart();
        $id = $s->checkout(method: 'cash_on_delivery')->json('data.order.id');
        $order = Order::findOrFail($id);
        $order->forceFill(['status' => 'completed'])->save();
        $order->suborders()->update(['status' => 'delivered']);
        $collection = app(CashCollection::class);
        $collection->record($order, $order->total_halalas, 'receipt-0001');
        $collection->record($order, $order->total_halalas, 'receipt-0001');
        $this->assertNotNull($order->refresh()->paid_at);
        $this->assertSame(1, $order->events()->where('type', 'payment_confirmed')->count());
        $this->postJson('/api/v1/orders/'.$order->id.'/payments', ['payment_method' => 'cash_on_delivery'])->assertUnprocessable();
    }
}
