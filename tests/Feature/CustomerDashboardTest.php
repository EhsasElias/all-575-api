<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\User;
use App\Domain\Orders\Enums\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CheckoutScenario;
use Tests\TestCase;

class CustomerDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_auth_and_new_customers_have_honest_empty_totals(): void
    {
        $this->getJson('/api/v1/me/dashboard')->assertUnauthorized();
        $this->actingAsCustomer(User::factory()->create());
        $this->getJson('/api/v1/me/dashboard')->assertOk()->assertJsonPath('data.orders_total', 0)
            ->assertJsonPath('data.net_spent', 0)->assertJsonCount(0, 'data.recent_orders');
    }

    public function test_paid_totals_unpaid_cash_and_account_isolation(): void
    {
        $s = (new CheckoutScenario($this))->withCart();
        $paid = $s->pay();
        $paid->update(['status' => OrderStatus::Completed]);
        $s->addItem();
        $s->checkout(key: 'dashboard-cash', method: 'cash_on_delivery')->assertCreated();
        $this->getJson('/api/v1/me/dashboard')->assertOk()
            ->assertJsonPath('data.orders_total', 2)->assertJsonPath('data.orders_active', 1)
            ->assertJsonPath('data.orders_completed', 1)->assertJsonPath('data.orders_awaiting_payment', 0)
            ->assertJsonPath('data.paid_total', $paid->total_halalas)->assertJsonPath('data.net_spent', $paid->total_halalas)
            ->assertJsonPath('data.addresses', 1)->assertJsonCount(2, 'data.recent_orders');
        $this->actingAsCustomer(User::factory()->create());
        $this->getJson('/api/v1/me/dashboard')->assertOk()->assertJsonPath('data.orders_total', 0)
            ->assertJsonPath('data.addresses', 0)->assertJsonPath('data.paid_total', 0)->assertJsonCount(0, 'data.recent_orders');
    }

    public function test_only_successful_refunds_reduce_paid_spending(): void
    {
        $s = (new CheckoutScenario($this))->withCart();
        $paid = $s->pay();
        $this->postJson('/api/v1/orders/'.$paid->id.'/cancel')->assertOk();
        $refund = $paid->refunds()->firstOrFail();
        $this->getJson('/api/v1/me/dashboard')->assertOk()->assertJsonPath('data.refunded_total', 0);
        $refund->update(['status' => 'succeeded', 'completed_at' => now()]);
        $this->getJson('/api/v1/me/dashboard')->assertOk()->assertJsonPath('data.refunded_total', $paid->total_halalas)
            ->assertJsonPath('data.net_spent', 0);
    }

    public function test_recent_orders_are_limited_and_awaiting_payment_is_not_paid(): void
    {
        $s = (new CheckoutScenario($this))->withCart();
        foreach (range(1, 4) as $index) {
            $s->checkout(key: 'dashboard-pending-'.$index)->assertCreated();
            $this->travel(1)->seconds();
        }
        $this->getJson('/api/v1/me/dashboard')->assertOk()->assertJsonPath('data.orders_total', 4)
            ->assertJsonPath('data.orders_awaiting_payment', 4)->assertJsonPath('data.paid_total', 0)
            ->assertJsonCount(3, 'data.recent_orders');
    }
}
