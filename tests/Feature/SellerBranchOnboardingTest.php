<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Catalog\Models\Seller;
use App\Domain\Catalog\Models\Branch;
use App\Domain\Catalog\Models\ServiceZone;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class SellerBranchOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_proposes_inactive_branch_and_reviewer_activates_it_once(): void
    {
        $seller = Seller::factory()->create();
        $zone = ServiceZone::factory()->create();
        $owner = User::factory()->create(['email_verified_at' => now()]);
        DB::table('seller_staff_access')->insert([
            'seller_id' => $seller->id, 'user_id' => $owner->id,
            'role' => 'owner', 'branch_id' => null, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Sanctum::actingAs($owner, ['seller']);

        $response = $this->postJson('/api/v1/seller/branches', [
            'seller_id' => $seller->id, 'service_zone_id' => $zone->id,
            'code' => 'rdh-001', 'name_ar' => 'فرع الرياض', 'name_en' => 'Riyadh Branch',
            'lat' => 24.7136, 'lng' => 46.6753,
        ])->assertCreated()
            ->assertJsonPath('data.is_active', false);

        $branchId = (int) $response->json('data.id');
        $this->assertDatabaseHas('branches', ['id' => $branchId, 'code' => 'RDH-001', 'is_active' => false]);

        $reviewer = User::factory()->create();
        DB::table('seller_review_grants')->insert([
            'user_id' => $reviewer->id, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Sanctum::actingAs($reviewer, ['customer']);

        $this->getJson('/api/v1/seller-review/branches')->assertOk();
        $this->postJson('/api/v1/seller-review/branches/'.$branchId.'/decision',
            ['decision' => 'activate'])->assertOk()->assertJsonPath('data.is_active', true);
        $this->postJson('/api/v1/seller-review/branches/'.$branchId.'/decision',
            ['decision' => 'activate'])->assertStatus(409);

        $this->assertDatabaseHas('seller_review_audits', [
            'subject_type' => 'branch', 'subject_id' => $branchId,
            'previous_status' => 'inactive', 'new_status' => 'active',
        ]);
    }

    public function test_other_seller_cannot_create_branch_and_outside_zone_is_rejected(): void
    {
        $seller = Seller::factory()->create();
        $zone = ServiceZone::factory()->create();
        $owner = User::factory()->create();
        Sanctum::actingAs($owner, ['seller']);

        $data = [
            'seller_id' => $seller->id, 'service_zone_id' => $zone->id,
            'code' => 'TEST-OUT', 'name_ar' => 'فرع', 'name_en' => 'Branch',
            'lat' => 30.0, 'lng' => 50.0,
        ];
        $this->postJson('/api/v1/seller/branches', $data)->assertNotFound();

        DB::table('seller_staff_access')->insert([
            'seller_id' => $seller->id, 'user_id' => $owner->id,
            'role' => 'owner', 'branch_id' => null, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->postJson('/api/v1/seller/branches', $data)->assertStatus(422);
        $this->assertDatabaseCount('branches', 0);
    }
    public function test_legacy_disabled_branch_is_not_a_pending_proposal(): void
    {
        $seller = Seller::factory()->create();
        $branch = Branch::factory()->for($seller)->create(['is_active' => false]);
        $reviewer = User::factory()->create();
        DB::table('seller_review_grants')->insert([
            'user_id' => $reviewer->id, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Sanctum::actingAs($reviewer, ['customer']);

        $this->getJson('/api/v1/seller-review/branches')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/seller-review/branches/'.$branch->id.'/decision',
            ['decision' => 'activate'])->assertNotFound();
        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'is_active' => false]);
    }

}
