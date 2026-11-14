<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuperAdminMetricTest extends TestCase
{
    use RefreshDatabase;

    private function grantSuperAdmin(User $user): void
    {
        DB::table('platform_super_admin_grants')->insert([
            'user_id' => $user->id, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Sanctum::actingAs($user, ['customer']);
    }

    public function test_guest_cannot_inspect_or_export_metrics(): void
    {
        $this->getJson('/api/v1/platform/super-admin/metrics/customers')->assertUnauthorized();
        $this->get('/api/v1/platform/super-admin/metrics/customers/export/csv')->assertUnauthorized();
    }

    public function test_regular_user_cannot_inspect_metrics(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['customer']);
        $this->getJson('/api/v1/platform/super-admin/metrics/customers')->assertForbidden();
        $this->get('/api/v1/platform/super-admin/metrics/customers/export/xls')->assertForbidden();
    }

    public function test_super_admin_can_page_through_safe_account_details(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.test']);
        $this->grantSuperAdmin($user);
        $this->getJson('/api/v1/platform/super-admin/metrics/customers?per_page=1&page=1')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('page', 1)
            ->assertJsonPath('data.0.email', 'admin@example.test')
            ->assertJsonMissingPath('data.0.password');
    }

    public function test_rejects_unknown_metrics_and_invalid_pagination(): void
    {
        $user = User::factory()->create();
        $this->grantSuperAdmin($user);
        $this->getJson('/api/v1/platform/super-admin/metrics/secrets')->assertNotFound();
        $this->getJson('/api/v1/platform/super-admin/metrics/customers?per_page=999')->assertUnprocessable();
        $this->get('/api/v1/platform/super-admin/metrics/customers/export/pdf')->assertNotFound();
    }

    public function test_seller_selector_requires_super_admin_grant(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['customer']);
        $this->getJson('/api/v1/platform/super-admin/sellers')->assertForbidden();
    }

    public function test_super_admin_can_read_seller_selector(): void
    {
        $user = User::factory()->create();
        $this->grantSuperAdmin($user);
        $this->getJson('/api/v1/platform/super-admin/sellers')->assertOk()->assertJsonPath('data', []);
    }

    public function test_metric_filters_apply_to_server_side_rows_and_validate_date_range(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.test']);
        $this->grantSuperAdmin($user);
        $this->getJson('/api/v1/platform/super-admin/metrics/customers?q=admin%40example.test')
            ->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/v1/platform/super-admin/metrics/customers?q=missing%40example.test')
            ->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/platform/super-admin/metrics/customers?from=2099-01-01')
            ->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/platform/super-admin/metrics/customers?from=2026-12-31&to=2026-01-01')
            ->assertUnprocessable();
        $this->getJson('/api/v1/platform/super-admin/metrics/customers?seller_id=1')
            ->assertUnprocessable();
    }

    public function test_export_accepts_the_same_validated_filters_as_report(): void
    {
        $user = User::factory()->create();
        $this->grantSuperAdmin($user);
        $this->get('/api/v1/platform/super-admin/metrics/customers/export/csv?from=2099-01-01')
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->get('/api/v1/platform/super-admin/metrics/customers/export/csv?from=2026-12-31&to=2026-01-01')
            ->assertUnprocessable();
    }

    public function test_super_admin_can_download_csv_and_excel_compatible_worksheet(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.test']);
        $this->grantSuperAdmin($user);
        $this->get('/api/v1/platform/super-admin/metrics/customers/export/csv')
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->get('/api/v1/platform/super-admin/metrics/customers/export/xls')
            ->assertOk()->assertHeader('content-type', 'application/vnd.ms-excel; charset=UTF-8');
    }
}
