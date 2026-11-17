<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Catalog\Models\Branch;
use App\Domain\Catalog\Models\Seller;
use App\Domain\Catalog\Models\ServiceZone;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\DeliveryCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CourierEndToEndTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A paid, seller-ready order without checkout side-effects:
     * focus is dispatch, pickup, invalid codes, delivery and role boundaries.
     *
     * @return array{admin: User, courier: User, buyer: User, suborder: string, order: string}
     */
    private function fixture(): array
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $zone = ServiceZone::factory()->create();
        $branch = Branch::factory()->create([
            'seller_id' => $seller->id, 'service_zone_id' => $zone->id,
        ]);
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $courier = User::factory()->create(['email_verified_at' => now()]);
        $buyer = User::factory()->create(['email_verified_at' => now()]);

        DB::table('platform_admin_access')->insert([
            'user_id' => $admin->id, 'seller_id' => $seller->id,
            'is_active' => true, 'can_view_orders' => true, 'can_view_inventory' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $profile = DB::table('courier_profiles')->insertGetId([
            'user_id' => $courier->id, 'service_zone_id' => $zone->id,
            'vehicle_type' => 'motorcycle', 'approval_status' => 'approved',
            'is_online' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $order = (string) Str::ulid();
        $suborder = (string) Str::ulid();
        DB::table('orders')->insert([
            'id' => $order, 'number' => 'A575-TEST-'.Str::random(8),
            'user_id' => $buyer->id, 'status' => 'confirmed',
            'idempotency_key' => Str::random(40), 'quote_id' => (string) Str::ulid(),
            'recipient_name' => 'Example Buyer', 'recipient_phone' => '0500000000',
            'region' => 'Riyadh', 'city' => 'Riyadh', 'district' => 'Test',
            'street' => 'Test Street', 'building_number' => '1234',
            'additional_number' => '5678', 'postal_code' => '12345',
            'lat' => 24.7136, 'lng' => 46.6753, 'service_zone_id' => $zone->id,
            'currency' => 'SAR', 'subtotal_halalas' => 1000,
            'discount_halalas' => 0, 'delivery_fee_halalas' => 0,
            'total_halalas' => 1000, 'vat_halalas' => 130,
            'vat_breakdown' => json_encode([]),
            'placed_at' => now()->subHour(), 'paid_at' => now()->subMinutes(50),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('suborders')->insert([
            'id' => $suborder, 'order_id' => $order, 'seller_id' => $seller->id,
            'branch_id' => $branch->id, 'status' => 'ready',
            'subtotal_halalas' => 1000, 'discount_halalas' => 0,
            'preparing_at' => now()->subMinutes(40),
            'ready_at' => now()->subMinutes(10),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('payments')->insert([
            'id' => (string) Str::ulid(), 'order_id' => $order, 'provider' => 'test',
            'method' => 'card', 'status' => 'paid',
            'amount_halalas' => 1000, 'currency' => 'SAR',
            'paid_at' => now()->subMinutes(50), 'expires_at' => now()->addHour(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return compact('admin', 'courier', 'buyer', 'order', 'suborder') + ['profile' => $profile];
    }

    public function test_paid_ready_order_cannot_be_assigned_twice_and_delivery_requires_correct_code(): void
    {
        $f = $this->fixture();
        Sanctum::actingAs($f['admin'], ['customer']);
        $offer = $this->postJson('/api/v1/platform/admin/orders/'.$f['suborder'].'/assign-courier', [
            'courier_profile_id' => $f['profile'],
        ])->assertOk()->assertJsonPath('data.status', 'offered');
        $assignment = $offer->json('data.assignment_id');
        $this->postJson('/api/v1/platform/admin/orders/'.$f['suborder'].'/assign-courier', [
            'courier_profile_id' => $f['profile'],
        ])->assertStatus(409);

        Sanctum::actingAs($f['courier'], ['customer']);
        $this->postJson('/api/v1/courier/jobs/'.$assignment.'/respond', [
            'decision' => 'accept',
        ])->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->postJson('/api/v1/courier/jobs/'.$assignment.'/respond', [
            'decision' => 'accept',
        ])->assertStatus(409);

        Sanctum::actingAs($f['admin'], ['customer']);
        $pickup = $this->postJson('/api/v1/platform/admin/orders/'.$f['suborder'].'/pickup-code')
            ->assertOk()->json('data.pickup_code');
        Sanctum::actingAs($f['courier'], ['customer']);
        $this->postJson('/api/v1/courier/jobs/'.$assignment.'/pickup', [
            'pickup_code' => '000000', 'parcel_count' => 1,
        ])->assertStatus(422);
        $this->assertDatabaseHas('courier_assignments', [
            'id' => $assignment, 'status' => 'accepted', 'pickup_attempts' => 1,
        ]);
        $this->postJson('/api/v1/courier/jobs/'.$assignment.'/pickup', [
            'pickup_code' => $pickup, 'parcel_count' => 2,
        ])->assertOk()->assertJsonPath('data.status', 'picked_up');
        $this->assertDatabaseHas('suborders', [
            'id' => $f['suborder'], 'status' => 'on_the_way',
        ]);

        $code = DeliveryCode::query()->where('suborder_id', $f['suborder'])->firstOrFail();
        $customerCode = $code->code_encrypted;
        $invalid = $customerCode === '000000' ? '111111' : '000000';
        $this->postJson('/api/v1/courier/jobs/'.$assignment.'/deliver', [
            'delivery_code' => $invalid,
        ])->assertStatus(422);
        $this->assertDatabaseHas('delivery_codes', [
            'suborder_id' => $f['suborder'], 'attempts' => 1,
        ]);
        $this->assertDatabaseHas('suborders', [
            'id' => $f['suborder'], 'status' => 'on_the_way',
        ]);
        $this->postJson('/api/v1/courier/jobs/'.$assignment.'/deliver', [
            'delivery_code' => $customerCode,
        ])->assertOk()->assertJsonPath('data.status', 'delivered');
        $this->assertDatabaseHas('orders', ['id' => $f['order'], 'status' => 'completed']);
        $this->postJson('/api/v1/courier/jobs/'.$assignment.'/deliver', [
            'delivery_code' => $customerCode,
        ])->assertStatus(409);
        $this->assertDatabaseHas('courier_assignments', [
            'id' => $assignment, 'status' => 'delivered',
        ]);
    }

    public function test_courier_cannot_view_or_accept_another_couriers_assignment(): void
    {
        $f = $this->fixture();
        Sanctum::actingAs($f['admin'], ['customer']);
        $assignment = $this->postJson('/api/v1/platform/admin/orders/'.$f['suborder'].'/assign-courier', [
            'courier_profile_id' => $f['profile'],
        ])->assertOk()->json('data.assignment_id');

        $stranger = User::factory()->create(['email_verified_at' => now()]);
        DB::table('courier_profiles')->insert([
            'user_id' => $stranger->id,
            'service_zone_id' => DB::table('courier_profiles')->where('id', $f['profile'])->value('service_zone_id'),
            'vehicle_type' => 'car', 'approval_status' => 'approved', 'is_online' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Sanctum::actingAs($stranger, ['customer']);
        $this->getJson('/api/v1/courier/jobs')->assertOk()->assertJsonPath('data', []);
        $this->postJson('/api/v1/courier/jobs/'.$assignment.'/respond', [
            'decision' => 'accept',
        ])->assertNotFound();
        $this->postJson('/api/v1/courier/jobs/'.$assignment.'/pickup', [
            'pickup_code' => '123456', 'parcel_count' => 1,
        ])->assertNotFound();
    }

    public function test_failed_delivery_records_incident_without_falsely_delivering_order(): void
    {
        $f = $this->fixture();
        Sanctum::actingAs($f['admin'], ['customer']);
        $assignment = $this->postJson('/api/v1/platform/admin/orders/'.$f['suborder'].'/assign-courier', [
            'courier_profile_id' => $f['profile'],
        ])->assertOk()->json('data.assignment_id');
        Sanctum::actingAs($f['courier'], ['customer']);
        $this->postJson('/api/v1/courier/jobs/'.$assignment.'/respond', ['decision' => 'accept'])->assertOk();
        Sanctum::actingAs($f['admin'], ['customer']);
        $code = $this->postJson('/api/v1/platform/admin/orders/'.$f['suborder'].'/pickup-code')
            ->assertOk()->json('data.pickup_code');
        Sanctum::actingAs($f['courier'], ['customer']);
        $this->postJson('/api/v1/courier/jobs/'.$assignment.'/pickup', [
            'pickup_code' => $code, 'parcel_count' => 1,
        ])->assertOk();
        $this->postJson('/api/v1/courier/jobs/'.$assignment.'/failed-attempt', [
            'reason' => 'customer_absent',
        ])->assertCreated()->assertJsonPath('data.status', 'needs_admin_review');
        $this->assertDatabaseHas('suborders', ['id' => $f['suborder'], 'status' => 'on_the_way']);
        $this->assertDatabaseHas('delivery_incidents', [
            'suborder_id' => $f['suborder'], 'type' => 'delivery_issue', 'status' => 'open',
        ]);
    }
}
