<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Catalog\Models\Branch;
use App\Domain\Catalog\Models\Seller;
use App\Domain\Identity\Models\User;
use Database\Seeders\SellerEmployeeNumberSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class SellerEmployeeNumberTest extends TestCase
{
    use RefreshDatabase;

    private function ownerAndBranch(): array
    {
        $seller = Seller::factory()->create();
        $branch = Branch::factory()->for($seller)->create();
        $owner = User::factory()->create();
        DB::table('seller_staff_access')->insert([
            'seller_id' => $seller->id,
            'user_id' => $owner->id,
            'employee_number' => 'EMP-000001',
            'branch_id' => null,
            'role' => 'owner',
            'is_active' => true,
        ]);
        Sanctum::actingAs($owner, ['seller']);

        return [$seller, $branch, $owner];
    }

    public function test_full_employee_lifecycle_preserves_the_number_and_displays_name(): void
    {
        [$seller, $branch] = $this->ownerAndBranch();
        $worker = User::factory()->create(['name' => 'Sample Team Member']);

        $create = $this->postJson('/api/v1/seller/staff', [
            'seller_id' => $seller->id,
            'user_id' => $worker->id,
            'role' => 'picker',
            'branch_id' => $branch->id,
        ])->assertCreated()->assertJsonPath('data.employee_number', 'EMP-000002');

        $membership = (int) $create->json('data.id');
        $this->getJson('/api/v1/seller/staff?seller_id='.$seller->id)
            ->assertOk()
            ->assertJsonFragment([
                'employee_number' => 'EMP-000002',
                'name' => 'Sample Team Member',
            ]);

        $this->patchJson('/api/v1/seller/staff/'.$membership, [
            'role' => 'branch_manager',
            'branch_id' => $branch->id,
            'is_active' => true,
        ])->assertOk()->assertJsonPath('data.employee_number', 'EMP-000002');

        $this->patchJson('/api/v1/seller/staff/'.$membership.'/deactivate')
            ->assertOk()->assertJsonPath('data.employee_number', 'EMP-000002');

        $this->patchJson('/api/v1/seller/staff/'.$membership, [
            'role' => 'picker',
            'branch_id' => $branch->id,
            'is_active' => true,
        ])->assertOk()->assertJsonPath('data.employee_number', 'EMP-000002');

        $this->assertDatabaseHas('seller_staff_access', [
            'id' => $membership,
            'employee_number' => 'EMP-000002',
            'is_active' => true,
            'role' => 'picker',
        ]);

        $second = User::factory()->create();
        $this->postJson('/api/v1/seller/staff', [
            'seller_id' => $seller->id,
            'user_id' => $second->id,
            'role' => 'picker',
            'branch_id' => $branch->id,
        ])->assertCreated()->assertJsonPath('data.employee_number', 'EMP-000003');
    }

    public function test_seeder_backfills_only_missing_numbers_and_remains_idempotent(): void
    {
        [$seller, $branch] = $this->ownerAndBranch();
        $first = User::factory()->create();
        $second = User::factory()->create();

        foreach ([$first, $second] as $user) {
            DB::table('seller_staff_access')->insert([
                'seller_id' => $seller->id,
                'user_id' => $user->id,
                'branch_id' => $branch->id,
                'role' => 'picker',
                'is_active' => true,
            ]);
        }

        $this->seed(SellerEmployeeNumberSeeder::class);
        $original = DB::table('seller_staff_access')->where('seller_id', $seller->id)
            ->orderBy('id')->pluck('employee_number')->all();
        $this->assertSame(['EMP-000001', 'EMP-000002', 'EMP-000003'], $original);

        $this->seed(SellerEmployeeNumberSeeder::class);
        $this->assertSame($original, DB::table('seller_staff_access')
            ->where('seller_id', $seller->id)->orderBy('id')->pluck('employee_number')->all());
    }

    public function test_non_owner_cannot_add_staff_or_view_employee_directory(): void
    {
        [$seller, $branch] = $this->ownerAndBranch();
        $worker = User::factory()->create();
        Sanctum::actingAs($worker, ['seller']);

        $this->getJson('/api/v1/seller/staff?seller_id='.$seller->id)->assertNotFound();
        $this->postJson('/api/v1/seller/staff', [
            'seller_id' => $seller->id,
            'user_id' => $worker->id,
            'role' => 'picker',
            'branch_id' => $branch->id,
        ])->assertNotFound();
    }
}
