<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class RoleApplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_apply_or_read_applications(): void
    {
        $this->getJson('/api/v1/me/role-applications')->assertUnauthorized();
        $this->postJson('/api/v1/me/role-applications', [
            'requested_role' => 'admin',
            'motivation' => 'I would like to manage orders.',
        ])->assertUnauthorized();
    }

    public function test_verified_customer_can_apply_without_receiving_admin_permissions(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        Sanctum::actingAs($user, ['customer']);

        $this->postJson('/api/v1/me/role-applications', [
            'requested_role' => 'admin',
            'motivation' => 'I have experience managing inventory and orders.',
        ])->assertCreated()->assertJsonPath('data.status', 'pending');

        $this->getJson('/api/v1/me/role-applications')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.requested_role', 'admin');

        $this->getJson('/api/v1/platform/admin/dashboard')->assertForbidden();
        $this->assertDatabaseCount('platform_admin_access', 0);
        $this->postJson('/api/v1/me/role-applications', [
            'requested_role' => 'admin',
            'motivation' => 'I have experience managing inventory and orders.',
        ])->assertStatus(409);
    }

    public function test_courier_application_requires_vehicle_and_does_not_register_courier(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        Sanctum::actingAs($user, ['customer']);

        $this->postJson('/api/v1/me/role-applications', [
            'requested_role' => 'courier',
            'motivation' => 'I can safely deliver local parcels.',
        ])->assertUnprocessable()->assertJsonValidationErrors('vehicle_type');

        $this->postJson('/api/v1/me/role-applications', [
            'requested_role' => 'courier',
            'vehicle_type' => 'car',
            'motivation' => 'I can safely deliver local parcels.',
        ])->assertCreated()->assertJsonPath('data.status', 'pending');

        $this->assertSame(0, DB::table('courier_profiles')->count());
        $this->getJson('/api/v1/courier/jobs')->assertForbidden();
    }

    public function test_customer_cannot_inspect_super_admin_review_queue(): void
    {
        Sanctum::actingAs(User::factory()->create(['email_verified_at' => now()]), ['customer']);

        $this->getJson('/api/v1/platform/super-admin/role-applications')->assertForbidden();
    }
}
