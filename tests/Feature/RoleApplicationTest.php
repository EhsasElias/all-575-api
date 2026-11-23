<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Catalog\Models\Seller;
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

    public function test_submission_notifies_active_super_admins_only_once_per_application(): void
    {
        $superAdmin = User::factory()->create(['email_verified_at' => now()]);
        $inactiveSuperAdmin = User::factory()->create(['email_verified_at' => now()]);
        DB::table('platform_super_admin_grants')->insert([
            [
                'user_id' => $superAdmin->id, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'user_id' => $inactiveSuperAdmin->id, 'is_active' => false,
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        $applicant = User::factory()->create(['email_verified_at' => now()]);
        Sanctum::actingAs($applicant, ['customer']);
        $response = $this->postJson('/api/v1/me/role-applications', [
            'requested_role' => 'courier',
            'vehicle_type' => 'car',
            'motivation' => 'I have courier driving experience.',
        ])->assertCreated();
        $applicationId = $response->json('data.id');

        $this->assertDatabaseHas('customer_notifications', [
            'user_id' => $superAdmin->id,
            'type' => 'role_application_submitted',
            'target_path' => '/account/super-admin#role-applications',
            'event_key' => 'role_application_submitted:'.$applicationId,
            'read_at' => null,
        ]);
        $this->assertDatabaseMissing('customer_notifications', [
            'user_id' => $inactiveSuperAdmin->id,
            'type' => 'role_application_submitted',
        ]);
        $this->assertDatabaseCount('customer_notifications', 1);
        $this->postJson('/api/v1/me/role-applications', [
            'requested_role' => 'courier',
            'vehicle_type' => 'car',
            'motivation' => 'I have courier driving experience.',
        ])->assertStatus(409);
        $this->assertDatabaseCount('customer_notifications', 1);
    }

    public function test_only_super_admin_can_review_and_review_does_not_grant_permissions(): void
    {
        $superAdmin = User::factory()->create(['email_verified_at' => now()]);
        DB::table('platform_super_admin_grants')->insert([
            'user_id' => $superAdmin->id, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $applicant = User::factory()->create(['email_verified_at' => now()]);
        Sanctum::actingAs($applicant, ['customer']);
        $id = $this->postJson('/api/v1/me/role-applications', [
            'requested_role' => 'admin',
            'motivation' => 'Experienced admin seeking to help.',
        ])->assertCreated()->json('data.id');

        $this->patchJson('/api/v1/platform/super-admin/role-applications/'.$id, [
            'decision' => 'approved',
        ])->assertForbidden();

        Sanctum::actingAs($superAdmin, ['customer']);
        $this->patchJson('/api/v1/platform/super-admin/role-applications/'.$id, [
            'decision' => 'approved',
            'note' => 'Reviewed qualifications',
        ])->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('role_applications', [
            'id' => $id, 'status' => 'approved',
            'reviewed_by' => $superAdmin->id, 'review_note' => 'Reviewed qualifications',
        ]);
        $this->assertDatabaseCount('platform_admin_access', 0);
        $this->assertDatabaseCount('courier_profiles', 0);
        $this->patchJson('/api/v1/platform/super-admin/role-applications/'.$id, [
            'decision' => 'rejected',
        ])->assertStatus(409);
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

    public function test_super_admin_can_activate_reviewed_admin_only_for_approved_seller(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        DB::table('platform_super_admin_grants')->insert([
            'user_id' => $owner->id, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $applicant = User::factory()->create(['email_verified_at' => now()]);
        $seller = Seller::factory()->create(['status' => 'approved']);
        Sanctum::actingAs($applicant, ['customer']);
        $id = $this->postJson('/api/v1/me/role-applications', [
            'requested_role' => 'admin',
            'motivation' => 'I can manage orders for this seller.',
        ])->assertCreated()->json('data.id');

        $endpoint = '/api/v1/platform/super-admin/role-applications/'.$id.'/activate';
        $this->postJson($endpoint, ['seller_id' => $seller->id])->assertForbidden();
        Sanctum::actingAs($owner, ['customer']);
        $this->postJson($endpoint, ['seller_id' => $seller->id])->assertStatus(409);
        $this->patchJson('/api/v1/platform/super-admin/role-applications/'.$id, [
            'decision' => 'approved',
        ])->assertOk();
        $this->postJson($endpoint, ['seller_id' => $seller->id,
            'can_view_orders' => true, 'can_view_inventory' => false])
            ->assertCreated()->assertJsonPath('data.role', 'admin');
        $this->assertDatabaseHas('platform_admin_access', [
            'user_id' => $applicant->id, 'seller_id' => $seller->id,
            'can_view_orders' => true, 'can_view_inventory' => false,
            'is_active' => true, 'granted_by' => $owner->id,
        ]);
        $this->postJson($endpoint, ['seller_id' => $seller->id])->assertStatus(409);
        $this->getJson('/api/v1/platform/super-admin/role-applications')
            ->assertOk()->assertJsonPath('data.0.activated', true);
    }

    public function test_super_admin_can_activate_reviewed_courier_for_an_active_zone(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        DB::table('platform_super_admin_grants')->insert([
            'user_id' => $owner->id, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $applicant = User::factory()->create(['email_verified_at' => now()]);
        $zone = DB::table('service_zones')->insertGetId([
            'name_ar' => 'اختبار', 'name_en' => 'Test Zone',
            'center_lat' => 24.7136, 'center_lng' => 46.6753,
            'radius_meters' => 5000, 'delivery_fee_halalas' => 0,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Sanctum::actingAs($applicant, ['customer']);
        $id = $this->postJson('/api/v1/me/role-applications', [
            'requested_role' => 'courier',
            'vehicle_type' => 'car',
            'motivation' => 'I can deliver parcels across my zone.',
        ])->assertCreated()->json('data.id');
        Sanctum::actingAs($owner, ['customer']);
        $this->patchJson('/api/v1/platform/super-admin/role-applications/'.$id, [
            'decision' => 'approved',
        ])->assertOk();

        $endpoint = '/api/v1/platform/super-admin/role-applications/'.$id.'/activate';
        $this->postJson($endpoint, [])->assertUnprocessable();
        $this->getJson('/api/v1/platform/super-admin/delivery-zones')
            ->assertOk()->assertJsonPath('data.0.id', $zone);
        $this->postJson($endpoint, ['service_zone_id' => $zone])
            ->assertCreated()->assertJsonPath('data.role', 'courier');
        $this->assertDatabaseHas('courier_profiles', [
            'user_id' => $applicant->id, 'service_zone_id' => $zone,
            'vehicle_type' => 'car', 'approval_status' => 'approved', 'is_online' => false,
        ]);
        $this->postJson($endpoint, ['service_zone_id' => $zone])->assertStatus(409);

        Sanctum::actingAs($applicant, ['customer']);
        $this->getJson('/api/v1/courier/profile')->assertOk()
            ->assertJsonPath('data.approval_status', 'approved');
    }

}
