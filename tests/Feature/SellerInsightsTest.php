<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Catalog\Models\Branch;
use App\Domain\Catalog\Models\Seller;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class SellerInsightsTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY = '?metric=orders&from=2026-11-01&to=2026-11-07';

    public function test_reports_require_seller_authentication(): void
    {
        $this->getJson('/api/v1/seller/insights'.self::QUERY)->assertUnauthorized();
        $this->get('/api/v1/seller/insights/export/csv'.self::QUERY)->assertUnauthorized();
    }

    public function test_authorized_seller_gets_empty_date_filtered_drilldowns(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $branch = Branch::factory()->for($seller)->create();
        $user = User::factory()->create();
        DB::table('seller_staff_access')->insert([
            'seller_id' => $seller->id,
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'role' => 'branch_manager',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Sanctum::actingAs($user, ['seller']);
        $this->getJson('/api/v1/seller/insights'.self::QUERY)
            ->assertOk()->assertJsonPath('data.metric', 'orders')
            ->assertJsonCount(0, 'data.rows');
        $this->getJson('/api/v1/seller/insights/trend?metric=sales&from=2026-11-01&to=2026-11-07')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/seller/insights?metric=products&from=2026-11-01&to=2026-11-07')
            ->assertOk()->assertJsonCount(0, 'data.rows');
        $this->getJson('/api/v1/seller/insights?metric=stock&from=2026-11-01&to=2026-11-07')
            ->assertOk()->assertJsonCount(0, 'data.rows');
        $this->get('/api/v1/seller/insights/export/csv'.self::QUERY)
            ->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function test_drilldown_validates_metric_and_date_range(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['seller']);
        $this->getJson('/api/v1/seller/insights?metric=profit&from=2026-11-01&to=2026-11-07')
            ->assertUnprocessable();
        $this->getJson('/api/v1/seller/insights?metric=orders&from=2026-11-07&to=2026-11-01')
            ->assertUnprocessable();
        $this->getJson('/api/v1/seller/insights?metric=orders&from=2026-11-01&to=2026-11-07&branch_id=-1')
            ->assertUnprocessable();
    }
}
