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
