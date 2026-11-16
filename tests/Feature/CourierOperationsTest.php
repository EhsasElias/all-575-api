<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Catalog\Models\Seller;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CourierOperationsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $seller = Seller::factory()->create();
        $user = User::factory()->create(['email_verified_at' => now()]);
        DB::table('platform_admin_access')->insert([
            'user_id' => $user->id, 'seller_id' => $seller->id,
            'is_active' => true, 'can_view_orders' => true, 'can_view_inventory' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $user;
    }

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

    public function test_ordinary_customer_cannot_access_admin_dispatch_or_courier_jobs(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['customer']);
        $this->getJson('/api/v1/platform/admin/couriers')->assertForbidden();
        $this->getJson('/api/v1/platform/admin/incidents')->assertForbidden();
        $this->getJson('/api/v1/courier/jobs')->assertForbidden();
        $this->postJson('/api/v1/platform/admin/orders/01ARZ3NDEKTSV4RRFFQ69G5FAV/assign-courier', [
            'courier_profile_id' => 123,
        ])->assertUnprocessable();
    }

    public function test_admin_can_propose_courier_but_cannot_approve(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $zone = $this->zone();
        Sanctum::actingAs($admin, ['customer']);
        $response = $this->postJson('/api/v1/platform/admin/couriers', [
            'user_id' => $user->id, 'service_zone_id' => $zone, 'vehicle_type' => 'motorcycle',
        ])->assertCreated()->assertJsonPath('data.approval_status', 'pending');
        $id = $response->json('data.id');
        $this->patchJson('/api/v1/platform/super-admin/couriers/'.$id.'/approval', [
            'approved' => true,
        ])->assertForbidden();
        $this->postJson('/api/v1/platform/admin/couriers', [
            'user_id' => $user->id, 'service_zone_id' => $zone, 'vehicle_type' => 'motorcycle',
        ])->assertStatus(409);
    }

    public function test_only_approved_courier_can_go_online(): void
    {
        $admin = $this->admin();
        $courier = User::factory()->create(['email_verified_at' => now()]);
        $zone = $this->zone();
        Sanctum::actingAs($admin, ['customer']);
        $id = $this->postJson('/api/v1/platform/admin/couriers', [
            'user_id' => $courier->id, 'service_zone_id' => $zone, 'vehicle_type' => 'car',
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($courier, ['customer']);
        $this->patchJson('/api/v1/courier/availability', ['is_online' => true])->assertForbidden();
        $this->getJson('/api/v1/courier/jobs')->assertForbidden();

        Sanctum::actingAs($this->owner(), ['customer']);
        $this->patchJson('/api/v1/platform/super-admin/couriers/'.$id.'/approval', [
            'approved' => true,
        ])->assertOk();

        Sanctum::actingAs($courier, ['customer']);
        $this->patchJson('/api/v1/courier/availability', ['is_online' => true])
            ->assertOk()->assertJsonPath('data.is_online', true);
        $this->getJson('/api/v1/courier/jobs')->assertOk()->assertJsonPath('data', []);
    }

    public function test_admin_incidents_are_restricted_to_authorized_suborders(): void
    {
        Sanctum::actingAs($this->admin(), ['customer']);
        $this->getJson('/api/v1/platform/admin/daily-status')
            ->assertOk()->assertJsonPath('data.open_incidents', 0);
        $this->postJson('/api/v1/platform/admin/incidents', [
            'suborder_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'type' => 'delivery_issue', 'description' => 'Delivery address could not be found.',
        ])->assertNotFound();
    }
}
