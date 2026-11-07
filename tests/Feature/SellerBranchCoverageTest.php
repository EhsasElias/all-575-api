<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Catalog\Models\BranchStock;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\Seller;
use App\Domain\Catalog\Models\ServiceZone;
use App\Domain\Catalog\Services\Availability;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class SellerBranchCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_and_update_multiple_coverage_zones_and_stock_is_available_in_both(): void
    {
        $seller = Seller::factory()->create();
        $primary = ServiceZone::factory()->create();
        $additional = ServiceZone::factory()->create([
            'name_ar' => 'جدة', 'name_en' => 'Jeddah',
            'center_lat' => 21.5433, 'center_lng' => 39.1728,
        ]);
        $owner = User::factory()->create();
        DB::table('seller_staff_access')->insert([
            'seller_id' => $seller->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'branch_id' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Sanctum::actingAs($owner, ['seller']);

        $response = $this->postJson('/api/v1/seller/branches', [
            'seller_id' => $seller->id,
            'service_zone_id' => $primary->id,
            'service_zone_ids' => [$primary->id, $additional->id],
            'code' => 'ZONES-001',
            'name_ar' => 'فرع الرياض',
            'name_en' => 'Riyadh',
            'lat' => 24.7136,
            'lng' => 46.6753,
        ])->assertCreated();
        $branchId = $response->json('data.id');

        $this->assertDatabaseHas('branch_service_zones', [
            'branch_id' => $branchId, 'service_zone_id' => $primary->id,
        ]);
        $this->assertDatabaseHas('branch_service_zones', [
            'branch_id' => $branchId, 'service_zone_id' => $additional->id,
        ]);

        $this->getJson('/api/v1/seller/branches')
            ->assertOk()
            ->assertJsonPath('data.0.service_zone_ids.0', $primary->id)
            ->assertJsonPath('data.0.service_zone_ids.1', $additional->id);

        // Seller proposals are inactive until reviewed; activate for availability assertions.
        DB::table('branches')->where('id', $branchId)->update(['is_active' => true]);
        $variant = ProductVariant::factory()->create();
        BranchStock::factory()->create([
            'branch_id' => $branchId, 'variant_id' => $variant->id,
            'on_hand' => 12, 'reserved' => 0,
        ]);
        $availability = app(Availability::class);
        $this->assertSame(12, $availability->forVariants([$variant->id], $primary->id)[$variant->id]);
        $this->assertSame(12, $availability->forVariants([$variant->id], $additional->id)[$variant->id]);

        $this->patchJson('/api/v1/seller/branches/'.$branchId, [
            'name_ar' => 'الفرع الرئيسي', 'name_en' => 'Main branch',
            'service_zone_ids' => [$primary->id],
        ])->assertOk()->assertJsonPath('data.service_zone_ids', [$primary->id]);

        $this->assertDatabaseMissing('branch_service_zones', [
            'branch_id' => $branchId, 'service_zone_id' => $additional->id,
        ]);
        $this->assertArrayNotHasKey($variant->id,
            $availability->forVariants([$variant->id], $additional->id));
    }

    public function test_additional_zone_must_be_active_and_primary_must_be_included(): void
    {
        $seller = Seller::factory()->create();
        $primary = ServiceZone::factory()->create();
        $other = ServiceZone::factory()->create(['is_active' => false]);
        $owner = User::factory()->create();
        DB::table('seller_staff_access')->insert([
            'seller_id' => $seller->id, 'user_id' => $owner->id,
            'role' => 'owner', 'branch_id' => null, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Sanctum::actingAs($owner, ['seller']);
        $base = [
            'seller_id' => $seller->id, 'service_zone_id' => $primary->id,
            'code' => 'ZONES-002', 'name_ar' => 'فرع', 'name_en' => 'Branch',
            'lat' => 24.7136, 'lng' => 46.6753,
        ];
        $this->postJson('/api/v1/seller/branches', $base + [
            'service_zone_ids' => [$other->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('service_zone_ids.0');

        $activeOther = ServiceZone::factory()->create();
        $this->postJson('/api/v1/seller/branches', $base + [
            'service_zone_ids' => [$activeOther->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('service_zone_ids');
    }
}
