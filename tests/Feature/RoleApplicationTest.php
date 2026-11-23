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
    public function test_super_admin_receives_pending_admin_and_courier_applications(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        DB::table('platform_super_admin_grants')->insert([
            'user_id' => $admin->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $applicant = User::factory()->create(['email_verified_at' => now()]);
        Sanctum::actingAs($applicant, ['customer']);

        $this->postJson('/api/v1/me/role-applications', [
            'requested_role' => 'admin',
            'motivation' => 'I can manage the marketplace support team.',
        ])->assertCreated();

        $this->postJson('/api/v1/me/role-applications', [
            'requested_role' => 'courier',
            'vehicle_type' => 'car',
            'motivation' => 'I have experience with local deliveries.',
        ])->assertCreated();

        Sanctum::actingAs($admin, ['customer']);

        $response = $this->getJson('/api/v1/platform/super-admin/role-applications')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $roles = array_column($response->json('data'), 'requested_role');
        sort($roles);
        $this->assertSame(['admin', 'courier'], $roles);
        $response->assertJsonPath('data.0.status', 'pending');

        $this->assertDatabaseCount('platform_admin_access', 0);
        $this->assertDatabaseCount('courier_profiles', 0);
    }

    public function test_role_specific_fields_reach_the_super_admin_review_queue(): void
    {
        $superAdmin = User::factory()->create(['email_verified_at' => now()]);
        DB::table('platform_super_admin_grants')->insert([
            'user_id' => $superAdmin->id, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $applicant = User::factory()->create(['email_verified_at' => now()]);
        Sanctum::actingAs($applicant, ['customer']);

        $this->postJson('/api/v1/me/role-applications', [
            'requested_role' => 'admin',
            'motivation' => 'I would like to support marketplace operations.',
            'contact_phone' => '0501234567',
            'city' => 'Riyadh',
            'experience_years' => 4,
            'qualification' => 'Operations management',
        ])->assertCreated();

        $this->postJson('/api/v1/me/role-applications', [
            'requested_role' => 'courier',
            'motivation' => 'I know the local delivery routes.',
            'vehicle_type' => 'car',
            'contact_phone' => '0501234567',
            'city' => 'Riyadh',
            'experience_years' => 2,
            'service_area' => 'North Riyadh',
            'vehicle_plate_number' => 'ABC 1234',
        ])->assertCreated();

        Sanctum::actingAs($superAdmin, ['customer']);
        $rows = $this->getJson('/api/v1/platform/super-admin/role-applications')
            ->assertOk()->json('data');

        $admin = collect($rows)->firstWhere('requested_role', 'admin');
        $courier = collect($rows)->firstWhere('requested_role', 'courier');

        $this->assertSame('0501234567', $admin['contact_phone']);
        $this->assertSame('Operations management', $admin['qualification']);
        $this->assertSame('Riyadh', $admin['city']);
        $this->assertEquals(4, $admin['experience_years']);
        $this->assertNull($admin['service_area']);

        $this->assertSame('North Riyadh', $courier['service_area']);
        $this->assertSame('ABC 1234', $courier['vehicle_plate_number']);
        $this->assertNull($courier['qualification']);
        $this->assertDatabaseCount('platform_admin_access', 0);
        $this->assertDatabaseCount('courier_profiles', 0);
    }

    public function test_role_application_rejects_invalid_saudi_phone(): void
    {
        $applicant = User::factory()->create(['email_verified_at' => now()]);
        Sanctum::actingAs($applicant, ['customer']);

        $this->postJson('/api/v1/me/role-applications', [
            'requested_role' => 'courier',
            'motivation' => 'I would like to join the delivery service.',
            'vehicle_type' => 'car',
            'contact_phone' => '12345',
        ])->assertUnprocessable()->assertJsonValidationErrors('contact_phone');
    }

}
