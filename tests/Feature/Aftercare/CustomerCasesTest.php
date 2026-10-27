<?php

namespace Tests\Feature\Aftercare;

use App\Domain\Aftercare\Models\CustomerCase;
use App\Domain\Orders\Enums\SuborderStatus;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\SuborderTransitions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\CheckoutScenario;
use Tests\TestCase;

class CustomerCasesTest extends TestCase
{
    use RefreshDatabase;

    private function input(array $extra = []): array
    {
        return $extra + ['kind' => 'support', 'subject' => 'Late delivery', 'message' => 'Please check my delivery.', 'request_key' => (string) Str::uuid()];
    }

    private function delivered(CheckoutScenario $scenario): Order
    {
        $order = $scenario->pay();
        foreach ([SuborderStatus::Preparing, SuborderStatus::Ready, SuborderStatus::OnTheWay, SuborderStatus::Delivered] as $status) {
            foreach ($order->suborders as $suborder) {
                app(SuborderTransitions::class)->move($suborder, $status, 'admin');
            }
        }

        return $order->refresh();
    }

    public function test_support_request_replays_without_duplicate_messages_and_rejects_changed_payload(): void
    {
        $scenario = (new CheckoutScenario($this))->withCart();
        $order = $scenario->pay();
        $input = $this->input();
        $url = '/api/v1/orders/'.$order->id.'/cases';
        $id = $this->postJson($url, $input)->assertCreated()->assertJsonPath('data.status', 'submitted')->json('data.id');
        $this->postJson($url, $input)->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson($url, array_replace($input, ['message' => 'Different']))->assertStatus(409)->assertJsonPath('code', 'case_key_reused');
        $this->assertDatabaseCount('customer_cases', 1);
        $this->assertDatabaseCount('customer_case_messages', 1);
    }

    public function test_return_requires_delivered_item_and_prevents_excess_partial_returns(): void
    {
        $scenario = (new CheckoutScenario($this))->withCart(2);
        $order = $scenario->pay();
        $item = $order->items()->firstOrFail();
        $url = '/api/v1/orders/'.$order->id.'/cases';
        $input = $this->input(['kind' => 'return', 'order_item_id' => $item->id, 'quantity' => 1]);
        $this->postJson($url, $input)->assertStatus(409)->assertJsonPath('code', 'return_not_delivered');
        foreach ([SuborderStatus::Preparing, SuborderStatus::Ready, SuborderStatus::OnTheWay, SuborderStatus::Delivered] as $status) {
            app(SuborderTransitions::class)->move($order->suborders()->firstOrFail(), $status, 'admin');
        }
        $this->postJson($url, $input)->assertCreated();
        $this->getJson('/api/v1/orders/'.$order->id.'/care-items')->assertOk()->assertJsonPath('data.items.0.returnable_quantity', 1);
        $this->postJson($url, $this->input(['kind' => 'return', 'order_item_id' => $item->id, 'quantity' => 2]))
            ->assertStatus(409)->assertJsonPath('code', 'return_quantity_exceeded');
        $this->assertDatabaseCount('refunds', 0);
        $this->assertDatabaseCount('customer_cases', 1);
    }

    public function test_customer_can_follow_up_and_read_support_reply_but_cannot_change_status(): void
    {
        $scenario = (new CheckoutScenario($this))->withCart();
        $order = $scenario->pay();
        $id = $this->postJson('/api/v1/orders/'.$order->id.'/cases', $this->input(['status' => 'resolved', 'user_id' => 999]))->assertCreated()->json('data.id');
        $url = '/api/v1/me/cases/'.$id;
        $this->postJson($url.'/messages', ['message' => 'Any update?'])->assertOk()->assertJsonPath('data.status', 'submitted')->assertJsonCount(2, 'data.messages');
        $this->artisan('care:reply', ['id' => $id, 'message' => 'We have checked it.', '--status' => 'resolved'])->assertSuccessful();
        $this->getJson($url)->assertOk()->assertJsonPath('data.messages.2.author', 'support')->assertJsonPath('data.status', 'resolved');
        $this->postJson($url.'/messages', ['message' => 'Hello'])->assertStatus(409)->assertJsonPath('code', 'case_closed');
        $this->getJson('/api/v1/me/cases')->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_requests_and_messages_are_isolated_between_customers(): void
    {
        $scenario = (new CheckoutScenario($this))->withCart();
        $order = $scenario->pay();
        $id = $this->postJson('/api/v1/orders/'.$order->id.'/cases', $this->input())->assertCreated()->json('data.id');
        $this->actingAsCustomer();
        $this->getJson('/api/v1/me/cases')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/me/cases/'.$id)->assertNotFound();
        $this->postJson('/api/v1/me/cases/'.$id.'/messages', ['message' => 'Hello'])->assertNotFound();
        $this->getJson('/api/v1/orders/'.$order->id.'/care-items')->assertNotFound();
        $this->postJson('/api/v1/orders/'.$order->id.'/cases', $this->input())->assertNotFound();
    }

    public function test_invalid_fields_and_foreign_order_items_are_rejected(): void
    {
        $scenario = (new CheckoutScenario($this))->withCart();
        $order = $this->delivered($scenario);
        $url = '/api/v1/orders/'.$order->id.'/cases';
        $this->postJson($url, $this->input(['message' => '   ', 'kind' => 'invalid']))->assertUnprocessable();
        $this->postJson($url, $this->input(['kind' => 'return', 'quantity' => 0]))->assertUnprocessable();
        $this->postJson($url, $this->input(['kind' => 'return', 'order_item_id' => 999999, 'quantity' => 1]))->assertNotFound();
        $this->postJson($url, $this->input(['order_item_id' => 1]))->assertUnprocessable();
        $this->assertDatabaseCount('customer_cases', 0);
    }

    public function test_rejected_return_releases_requested_quantity_and_reply_is_not_available_in_production(): void
    {
        $scenario = (new CheckoutScenario($this))->withCart();
        $order = $this->delivered($scenario);
        $item = $order->items()->firstOrFail();
        $id = $this->postJson('/api/v1/orders/'.$order->id.'/cases', $this->input(['kind' => 'return', 'order_item_id' => $item->id, 'quantity' => 1]))->assertCreated()->json('data.id');
        $this->artisan('care:reply', ['id' => $id, 'message' => 'Reason explained.', '--status' => 'rejected'])->assertSuccessful();
        $this->getJson('/api/v1/orders/'.$order->id.'/care-items')->assertJsonPath('data.items.0.returnable_quantity', 1);
        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('care:reply', ['id' => $id, 'message' => 'Invalid'])->assertFailed();
        $this->assertSame('rejected', CustomerCase::findOrFail($id)->status);
    }
}
