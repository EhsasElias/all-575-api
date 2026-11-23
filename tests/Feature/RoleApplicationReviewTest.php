<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class RoleApplicationReviewTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        DB::table('platform_super_admin_grants')->insert([
            'user_id' => $user->id, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    private function zone(): int
    {
        return DB::table('service_zones')->insertGetId([
            'name_ar' => 'اختبار', 'name_en' => 'Test Zone',
            'center_lat' => 24.7136, 'center_lng' => 46.6753,
            'radius_meters' => 5000, 'delivery_fee_halalas' => 0,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function application(User $user, string $role = 'courier'): int
    {
        return DB::table('role_applications')->insertGetId([
            'user_id' => $user->id, 'requested_role' => $role, 'status' => 'pending',
            'vehicle_type' => $role === 'courier' ? 'car' : null,
            'motivation' => 'I have relevant experience and would like to help.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_only_super_admin_can_review_applications(): void
    {
        $applicant = User::factory()->create(['email_verified_at' => now()]);
        $id = $this->application($applicant);
        Sanctum::actingAs(User::factory()->create(['email_verified_at' => now()]), ['customer']);
        $this->getJson('/api/v1/platform/super-admin/service-zones')->assertForbidden();
        $this->patchJson('/api/v1/platform/super-admin/role-applications/'.$id, [
            'decision' => 'approved', 'service_zone_id' => $this->zone(),
        ])->assertForbidden();
        $this->assertDatabaseHas('role_applications', ['id' => $id, 'status' => 'pending']);
        $this->assertDatabaseCount('courier_profiles', 0);
    }

    public function test_super_admin_approval_creates_pending_inactive_courier_profile(): void
    {
        $applicant = User::factory()->create(['email_verified_at' => now()]);
        $id = $this->application($applicant);
        $zone = $this->zone();
        Sanctum::actingAs($this->owner(), ['customer']);

        $this->getJson('/api/v1/platform/super-admin/service-zones')
            ->assertOk()->assertJsonPath('data.0.id', $zone);
        $this->patchJson('/api/v1/platform/super-admin/role-applications/'.$id, [
            'decision' => 'approved', 'service_zone_id' => $zone,
        ])->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('role_applications', [
            'id' => $id, 'status' => 'approved',
        ]);
        $this->assertDatabaseHas('courier_profiles', [
            'user_id' => $applicant->id, 'service_zone_id' => $zone,
            'approval_status' => 'pending', 'is_online' => false,
        ]);
        $this->patchJson('/api/v1/platform/super-admin/role-applications/'.$id, [
            'decision' => 'approved', 'service_zone_id' => $zone,
        ])->assertStatus(409);
        $this->assertDatabaseCount('courier_profiles', 1);

        Sanctum::actingAs($applicant, ['customer']);
        $this->patchJson('/api/v1/courier/availability', ['is_online' => true])->assertForbidden();
    }

    public function test_zone_is_required_for_courier_but_not_admin_approval(): void
    {
        $applicant = User::factory()->create(['email_verified_at' => now()]);
        $courierId = $this->application($applicant);
        $adminId = $this->application($applicant, 'admin');
        Sanctum::actingAs($this->owner(), ['customer']);

        $this->patchJson('/api/v1/platform/super-admin/role-applications/'.$courierId, [
            'decision' => 'approved',
        ])->assertUnprocessable();
        $this->patchJson('/api/v1/platform/super-admin/role-applications/'.$adminId, [
            'decision' => 'approved',
        ])->assertOk();

        $this->assertDatabaseCount('courier_profiles', 0);
        $this->assertDatabaseCount('platform_admin_access', 0);
    }

    public function test_rejection_needs_reason_and_never_registers_courier(): void
    {
        $applicant = User::factory()->create(['email_verified_at' => now()]);
        $id = $this->application($applicant);
        Sanctum::actingAs($this->owner(), ['customer']);

        $this->patchJson('/api/v1/platform/super-admin/role-applications/'.$id, [
            'decision' => 'rejected',
        ])->assertUnprocessable();

        $this->patchJson('/api/v1/platform/super-admin/role-applications/'.$id, [
            'decision' => 'rejected', 'note' => 'Insufficient documentation.',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertDatabaseHas('role_applications', [
            'id' => $id, 'status' => 'rejected',
            'review_note' => 'Insufficient documentation.',
        ]);
        $this->assertDatabaseCount('courier_profiles', 0);
    }

    public function test_duplicate_existing_courier_prevents_approval(): void
    {
        $applicant = User::factory()->create(['email_verified_at' => now()]);
        $id = $this->application($applicant);
        $zone = $this->zone();
        DB::table('courier_profiles')->insert([
            'user_id' => $applicant->id, 'service_zone_id' => $zone, 'vehicle_type' => 'car',
            'approval_status' => 'pending', 'is_online' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Sanctum::actingAs($this->owner(), ['customer']);

        $this->patchJson('/api/v1/platform/super-admin/role-applications/'.$id, [
            'decision' => 'approved', 'service_zone_id' => $zone,
        ])->assertStatus(409);
        $this->assertDatabaseHas('role_applications', ['id' => $id, 'status' => 'pending']);
        $this->assertDatabaseCount('courier_profiles', 1);
    }
}
