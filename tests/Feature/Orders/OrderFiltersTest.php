<?php

namespace Tests\Feature\Orders;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CheckoutScenario;
use Tests\TestCase;

class OrderFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_number_date_and_customer_scope_combine(): void
    {
        $s = (new CheckoutScenario($this))->withCart();
        $order = $s->pay();
        $order->update(['placed_at' => '2026-10-29 21:30:00']);
        $this->getJson('/api/v1/orders?status=confirmed&from=2026-10-30&to=2026-10-30&q='.$order->number)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $order->id);
        $this->getJson('/api/v1/orders?from=2026-10-29&to=2026-10-29')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/orders?status=pending_payment')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/orders?q=%25')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/orders?status=invalid')->assertUnprocessable();
        $this->getJson('/api/v1/orders?from=2026-10-30&to=2026-10-29')->assertUnprocessable();
        $this->actingAsCustomer(User::factory()->create());
        $this->getJson('/api/v1/orders?q='.$order->number)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_filtered_cursor_pages_preserve_filters_and_do_not_repeat_orders(): void
    {
        $s = (new CheckoutScenario($this))->withCart();
        foreach (range(1, 3) as $index) {
            $s->checkout(key: 'filtered-order-'.$index)->assertCreated();
        }
        $first = $this->getJson('/api/v1/orders?status=pending_payment&per_page=1')->assertOk();
        $second = $this->getJson($first->json('links.next'))->assertOk();
        $this->assertNotSame($first->json('data.0.id'), $second->json('data.0.id'));
        $this->assertStringContainsString('status=pending_payment', $second->json('links.next'));
    }
}
