<?php

namespace Tests\Feature\Orders;

use App\Domain\Identity\Models\User;
use App\Domain\Payments\Models\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CheckoutScenario;
use Tests\TestCase;

class SpendingReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_summary_adds_up_paid_orders_with_savings_vat_and_fees_separate(): void
    {
        $s = (new CheckoutScenario($this))->withCart(2);
        $s->pay();
        $s->withCart(1);
        $s->pay();

        // 2 x 5.75 + 10.00 delivery, and 1 x 5.75 + 10.00 delivery.
        $this->getJson('/api/v1/me/reports/spending')->assertOk()
            ->assertJsonPath('data.summary.orders', 2)
            ->assertJsonPath('data.summary.paid', 2150 + 1575 + 0 - 0)
            ->assertJsonPath('data.summary.delivery_fees', 2000)
            ->assertJsonPath('data.summary.refunded', 0)
            ->assertJsonPath('data.summary.net_spent', 3725)
            ->assertJsonPath('data.currency', 'SAR')
            ->assertJsonPath('data.timezone', 'Asia/Riyadh');
    }

    public function test_order_date_payment_date_and_refund_date_are_reported_separately(): void
    {
        $s = (new CheckoutScenario($this))->withCart();
        $order = $s->pay();
        $this->travel(2)->hours();
        $this->postJson("/api/v1/orders/{$order->id}/cancel")->assertOk();

        $this->travel(1)->days();
        $refund = Refund::query()->firstOrFail();
        $s->providerCallback(['id' => 'evt_rr', 'type' => 'refund.succeeded', 'reference' => $refund->provider_reference])->assertOk();

        $row = $this->getJson('/api/v1/me/reports/spending?from='.now()->subDays(2)->toDateString().'&to='.now()->toDateString())
            ->assertOk()->json('data.rows.0');

        $this->assertSame($order->total_halalas, $row['refunded']);
        $this->assertSame(0, $row['net_spent']);
        $this->assertNotNull($row['order_date']);
        $this->assertNotNull($row['payment_date']);
        $this->assertNotNull($row['refund_date']);
        $this->assertNotSame($row['payment_date'], $row['refund_date']);
    }

    public function test_the_date_range_filters_by_order_date_and_unpaid_orders_never_count(): void
    {
        $s = (new CheckoutScenario($this))->withCart();
        $s->pay();
        $s->withCart();
        $s->checkout(key: 'idem-unpaid-001')->assertCreated();

        $this->getJson('/api/v1/me/reports/spending')->assertJsonPath('data.summary.orders', 1);
        $this->getJson('/api/v1/me/reports/spending?from='.now()->addDays(2)->toDateString())->assertJsonPath('data.summary.orders', 0);
        $this->getJson('/api/v1/me/reports/spending?from=2026-13-40')->assertUnprocessable();
        $this->getJson('/api/v1/me/reports/spending?from=2026-10-10&to=2026-10-01')->assertUnprocessable();
    }

    public function test_a_customer_only_ever_sees_their_own_spending(): void
    {
        $s = (new CheckoutScenario($this))->withCart();
        $s->pay();

        $this->actingAsCustomer(User::factory()->create());
        $this->getJson('/api/v1/me/reports/spending')->assertOk()->assertJsonPath('data.summary.orders', 0)->assertJsonCount(0, 'data.rows');
        $this->getJson('/api/v1/me/reports/spending.csv')->assertOk()->assertDontSee('A575-');
    }

    public function test_the_csv_download_matches_the_screen_is_excel_safe_and_is_audited(): void
    {
        $s = (new CheckoutScenario($this))->withCart(2);
        $order = $s->pay();

        $response = $this->get('/api/v1/me/reports/spending.csv', ['Accept' => 'text/csv'])->assertOk();

        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="all575-spending-', $response->headers->get('Content-Disposition'));
        $csv = $response->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString($order->number, $csv);
        $this->assertStringContainsString(',21.50,', $csv);
        $this->assertStringContainsString('"Order date","Payment date","Refund date"', $csv);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.exported', 'user_id' => $s->user->id]);
    }

    public function test_guests_cannot_read_reports(): void
    {
        $this->getJson('/api/v1/me/reports/spending')->assertUnauthorized();
        $this->get('/api/v1/me/reports/spending.csv')->assertUnauthorized();
    }
}
