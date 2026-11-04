<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Catalog\Models\Branch;
use App\Domain\Catalog\Models\Seller;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class SellerStoreProfileTest extends TestCase
{
    use RefreshDatabase;

    private function owner(User $user, Seller $seller, bool $active = true): void
    {
        DB::table('seller_staff_access')->insert([
            'seller_id' => $seller->id,
            'user_id' => $user->id,
            'branch_id' => null,
            'role' => 'owner',
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_owner_can_update_store_and_branch_display_names_only(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $branch = Branch::factory()->for($seller)->create();
        $user = User::factory()->create();
        $this->owner($user, $seller);
        Sanctum::actingAs($user, ['seller']);

        $this->getJson('/api/v1/seller/stores/'.$seller->id)
            ->assertOk()->assertJsonPath('data.id', $seller->id);

        $this->patchJson('/api/v1/seller/stores/'.$seller->id, [
            'name_ar' => 'متجر جديد',
            'name_en' => 'New Store',
            'status' => 'suspended',
        ])->assertOk()->assertJsonPath('data.name_en', 'New Store');
        $this->assertDatabaseHas('sellers', [
            'id' => $seller->id, 'name_en' => 'New Store', 'status' => 'approved',
        ]);

        $this->patchJson('/api/v1/seller/branches/'.$branch->id, [
            'name_ar' => 'فرع جديد',
            'name_en' => 'New Branch',
            'is_active' => false,
            'lat' => 0,
        ])->assertOk()->assertJsonPath('data.name_en', 'New Branch');
        $this->assertDatabaseHas('branches', [
            'id' => $branch->id, 'name_en' => 'New Branch', 'is_active' => true,
        ]);
    }

    public function test_member_of_another_store_cannot_read_or_change_profile(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $other = Seller::factory()->create(['status' => 'approved']);
        $branch = Branch::factory()->for($seller)->create();
        $user = User::factory()->create();
        $this->owner($user, $other);
        Sanctum::actingAs($user, ['seller']);

        $this->getJson('/api/v1/seller/stores/'.$seller->id)->assertNotFound();
        $this->patchJson('/api/v1/seller/stores/'.$seller->id, [
            'name_ar' => 'غير مسموح', 'name_en' => 'Not allowed',
        ])->assertNotFound();
        $this->patchJson('/api/v1/seller/branches/'.$branch->id, [
            'name_ar' => 'غير مسموح', 'name_en' => 'Not allowed',
        ])->assertNotFound();
    }

    public function test_owner_of_unapproved_store_cannot_edit_names(): void
    {
        $seller = Seller::factory()->create(['status' => 'pending']);
        $user = User::factory()->create();
        $this->owner($user, $seller);
        Sanctum::actingAs($user, ['seller']);

        $this->getJson('/api/v1/seller/stores/'.$seller->id)->assertNotFound();
        $this->patchJson('/api/v1/seller/stores/'.$seller->id, [
            'name_ar' => 'محاولة', 'name_en' => 'Attempt',
        ])->assertNotFound();
    }

    public function test_whitespace_only_store_and_branch_names_are_rejected(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $branch = Branch::factory()->for($seller)->create();
        $user = User::factory()->create();
        $this->owner($user, $seller);
        Sanctum::actingAs($user, ['seller']);

        $this->patchJson('/api/v1/seller/stores/'.$seller->id, [
            'name_ar' => '   ', 'name_en' => '   ',
        ])->assertUnprocessable();
        $this->patchJson('/api/v1/seller/branches/'.$branch->id, [
            'name_ar' => '   ', 'name_en' => '   ',
        ])->assertUnprocessable();
    }
}
