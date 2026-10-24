<?php

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Models\Seller;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Enums\SuborderStatus;
use App\Domain\Orders\Models\Suborder;
use App\Domain\Orders\Services\DeliveryCodes;
use App\Domain\Orders\Services\SuborderTransitions;
use App\Domain\Payments\Models\Refund;
use App\Support\Exceptions\DomainRuleViolation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CheckoutScenario;
use Tests\TestCase;

class FulfilmentTest extends TestCase
{
    use RefreshDatabase;

    private function move(Suborder $suborder, SuborderStatus $to): void
    {
        app(SuborderTransitions::class)->move($suborder, $to, 'seller');
    }

    public function test_a_delivery_goes_through_its_stages_and_the_timeline_tells_the_customer_each_one(): void
    {
        $s = (new CheckoutScenario($this))->withCart(2, 10);
        $order = $s->pay();
        $suborder = $order->suborders()->firstOrFail();

        foreach ([SuborderStatus::Preparing, SuborderStatus::Ready, SuborderStatus::OnTheWay, SuborderStatus::Delivered] as $stage) {
            $this->move($suborder, $stage);
        }

        $response = $this->getJson('/api/v1/orders/'.$order->id)->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.suborders.0.status', 'delivered')
            ->assertJsonPath('data.can_cancel', false);

        $this->assertSame(
            ['order_placed', 'payment_confirmed', 'order_confirmed', 'preparing', 'ready', 'on_the_way', 'delivered', 'order_completed'],
            array_column($response->json('data.timeline'), 'type'),
        );
    }

    public function test_a_delivery_cannot_skip_stages_or_go_backwards(): void
    {
        $s = (new CheckoutScenario($this))->withCart();
        $suborder = $s->pay()->suborders()->firstOrFail();

        try {
            $this->move($suborder, SuborderStatus::Delivered);
            $this->fail('Skipping stages must be refused.');
        } catch (DomainRuleViolation $e) {
            $this->assertSame('invalid_transition', $e->errorCode);
        }

        $this->move($suborder, SuborderStatus::Preparing);
        $this->expectException(DomainRuleViolation::class);
        $this->move($suborder, SuborderStatus::AwaitingSeller);
    }

    public function test_an_order_with_two_sellers_has_two_deliveries_and_completes_only_when_both_arrive(): void
    {
        $s = (new CheckoutScenario($this))->withCart();
        $s->addItem(1, Seller::factory()->create());
        $order = $s->pay();
        $deliveries = $order->suborders;

        $this->assertCount(2, $deliveries);
        $this->assertCount(2, array_unique($deliveries->pluck('seller_id')->all()));

        foreach ($deliveries as $delivery) {
            foreach ([SuborderStatus::Preparing, SuborderStatus::Ready, SuborderStatus::OnTheWay] as $stage) {
                $this->move($delivery, $stage);
            }
        }

        $this->move($deliveries[0], SuborderStatus::Delivered);
        $this->getJson('/api/v1/orders/'.$order->id)->assertJsonPath('data.status', 'confirmed');

        $this->move($deliveries[1], SuborderStatus::Delivered);
        $this->getJson('/api/v1/orders/'.$order->id)->assertJsonPath('data.status', 'completed')->assertJsonCount(2, 'data.suborders');
    }

    public function test_a_revised_eta_comes_with_its_reason(): void
    {
        $s = (new CheckoutScenario($this))->withCart();
        $order = $s->pay();

        app(SuborderTransitions::class)->reviseEta($order->suborders->first(), now()->addHours(5), 'Heavy traffic', 'admin');

        $this->getJson('/api/v1/orders/'.$order->id)->assertOk()
            ->assertJsonPath('data.suborders.0.eta.reason', 'Heavy traffic')
            ->assertJsonPath('data.timeline.3.type', 'eta_revised');
    }

    public function test_the_handover_code_appears_only_while_the_delivery_is_on_its_way_and_only_to_its_owner(): void
    {
        $s = (new CheckoutScenario($this))->withCart();
        $order = $s->pay();
        $suborder = $order->suborders()->firstOrFail();
        $url = "/api/v1/orders/{$order->id}/suborders/{$suborder->id}/delivery-code";

        $this->getJson($url)->assertNotFound()->assertJsonPath('code', 'delivery_code_unavailable');

        foreach ([SuborderStatus::Preparing, SuborderStatus::Ready, SuborderStatus::OnTheWay] as $stage) {
            $this->move($suborder, $stage);
        }

        $code = $this->getJson($url)->assertOk()->json('data.code');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        $this->actingAsCustomer(User::factory()->create());
        $this->getJson($url)->assertNotFound();
    }

    public function test_the_handover_code_is_checked_with_a_limited_number_of_tries(): void
    {
        $s = (new CheckoutScenario($this))->withCart();
        $suborder = $s->pay()->suborders()->firstOrFail();
        foreach ([SuborderStatus::Preparing, SuborderStatus::Ready, SuborderStatus::OnTheWay] as $stage) {
            $this->move($suborder, $stage);
        }
        $codes = app(DeliveryCodes::class);
        $right = $codes->visibleTo($suborder)->code_encrypted;
        $wrong = $right === '000000' ? '111111' : '000000';

        foreach ([4, 3, 2, 1, 0] as $remaining) {
            try {
                $codes->verify($suborder, $wrong);
                $this->fail('A wrong code must be refused.');
            } catch (DomainRuleViolation $e) {
                $this->assertSame('delivery_code_invalid', $e->errorCode);
                $this->assertSame($remaining, $e->meta['attempts_remaining']);
            }
        }

        try {
            $codes->verify($suborder, $right);
            $this->fail('A locked code must stay locked, even for the right value.');
        } catch (DomainRuleViolation $e) {
            $this->assertSame('delivery_code_locked', $e->errorCode);
        }
    }

    public function test_the_right_handover_code_is_accepted_once_and_then_disappears_for_the_customer(): void
    {
        $s = (new CheckoutScenario($this))->withCart();
        $suborder = $s->pay()->suborders()->firstOrFail();
        foreach ([SuborderStatus::Preparing, SuborderStatus::Ready, SuborderStatus::OnTheWay] as $stage) {
            $this->move($suborder, $stage);
        }
        $codes = app(DeliveryCodes::class);

        $this->assertTrue($codes->verify($suborder, $codes->visibleTo($suborder)->code_encrypted));
        $this->assertNull($codes->visibleTo($suborder));

        $this->expectException(DomainRuleViolation::class);
        $codes->verify($suborder, '123456');
    }

    public function test_the_handover_code_is_stored_encrypted(): void
    {
        $s = (new CheckoutScenario($this))->withCart();
        $suborder = $s->pay()->suborders()->firstOrFail();
        foreach ([SuborderStatus::Preparing, SuborderStatus::Ready, SuborderStatus::OnTheWay] as $stage) {
            $this->move($suborder, $stage);
        }
        $plain = app(DeliveryCodes::class)->visibleTo($suborder)->code_encrypted;

        $this->assertNotSame($plain, DB::table('delivery_codes')->value('code_encrypted'));
    }

    public function test_cancelling_an_unpaid_order_frees_everything_at_once(): void
    {
        $s = (new CheckoutScenario($this))->withCart(2, 10);
        $orderId = $s->checkout()->assertCreated()->json('data.order.id');

        $this->postJson("/api/v1/orders/{$orderId}/cancel")->assertOk()
            ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancel_reason', 'customer_cancelled');

        $this->assertSame(0, $s->item['stock']->refresh()->reserved);
        $this->assertSame(0, $s->slot->refresh()->booked);
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.cancelled_by_customer']);
    }

    public function test_cancelling_a_paid_order_before_preparation_restocks_and_refunds_in_full_once_the_provider_confirms(): void
    {
        $s = (new CheckoutScenario($this))->withCart(2, 10);
        $order = $s->pay();
        $this->assertSame(8, $s->item['stock']->refresh()->on_hand);

        $this->postJson("/api/v1/orders/{$order->id}/cancel")->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.suborders.0.status', 'cancelled')
            ->assertJsonPath('data.refunds.0.status', 'pending')
            ->assertJsonPath('data.refunds.0.amount', $order->total_halalas)
            ->assertJsonPath('data.refunds.0.credit_note_number', null);

        $this->assertSame(10, $s->item['stock']->refresh()->on_hand);
        $this->assertSame(0, $s->slot->refresh()->booked);

        $refund = Refund::query()->firstOrFail();
        $this->assertNotNull($refund->provider_reference);
        $s->providerCallback(['id' => 'evt_r1', 'type' => 'refund.succeeded', 'reference' => $refund->provider_reference])->assertOk();

        $this->getJson("/api/v1/orders/{$order->id}")->assertJsonPath('data.refunds.0.status', 'succeeded')
            ->assertJsonPath('data.refunds.0.credit_note_number', fn ($n) => str_starts_with($n, 'CN-'));
    }

    public function test_a_paid_order_cannot_be_cancelled_once_the_seller_is_preparing_it(): void
    {
        $s = (new CheckoutScenario($this))->withCart();
        $order = $s->pay();
        $this->move($order->suborders()->firstOrFail(), SuborderStatus::Preparing);

        $this->getJson("/api/v1/orders/{$order->id}")->assertJsonPath('data.can_cancel', false);
        $this->postJson("/api/v1/orders/{$order->id}/cancel")->assertStatus(409)->assertJsonPath('code', 'order_not_cancellable');
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_cancelling_twice_does_not_refund_twice(): void
    {
        $s = (new CheckoutScenario($this))->withCart();
        $order = $s->pay();

        $this->postJson("/api/v1/orders/{$order->id}/cancel")->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/cancel")->assertStatus(409);

        $this->assertDatabaseCount('refunds', 1);
    }

    public function test_orders_are_listed_newest_first_and_only_for_their_owner(): void
    {
        $s = (new CheckoutScenario($this))->withCart();
        $first = $s->pay();
        $this->travel(1)->minute();
        $s->withCart();
        $second = $s->pay();

        $this->getJson('/api/v1/orders')->assertOk()
            ->assertJsonPath('data.0.id', $second->id)->assertJsonPath('data.1.id', $first->id)
            ->assertJsonPath('data.0.payment_status', 'paid');

        $this->actingAsCustomer(User::factory()->create());
        $this->getJson('/api/v1/orders')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/orders/{$first->id}")->assertNotFound();
        $this->postJson("/api/v1/orders/{$first->id}/cancel")->assertNotFound();
    }

    public function test_the_advance_command_moves_every_delivery_in_development_and_refuses_in_production(): void
    {
        $s = (new CheckoutScenario($this))->withCart();
        $order = $s->pay();

        $this->artisan('orders:advance', ['number' => $order->number, 'status' => 'preparing'])->assertSuccessful();
        $this->assertSame('preparing', $order->suborders()->firstOrFail()->status->value);

        $this->app['env'] = 'production';
        $this->artisan('orders:advance', ['number' => $order->number, 'status' => 'ready'])->assertFailed();
        $this->assertSame('preparing', $order->suborders()->firstOrFail()->status->value);
    }
}
