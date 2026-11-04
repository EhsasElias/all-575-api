<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Catalog\Models\Seller;
use App\Domain\Identity\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\DemoSellerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemoSellerAccountSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_demo_seller_is_created_with_owner_membership_but_no_review_grant(): void
    {
        config(['demo.seller_email' => 'seller-demo@example.test']);
        $this->seed(DemoCatalogSeeder::class);
        $this->seed(DemoSellerAccountSeeder::class);

        $seller = Seller::query()->where('slug', 'home-store')->firstOrFail();
        $user = User::query()->where('email', 'seller-demo@example.test')->firstOrFail();

        $this->assertTrue(DB::table('seller_staff_access')->where([
            'user_id' => $user->id,
            'seller_id' => $seller->id,
            'role' => 'owner',
            'is_active' => true,
        ])->whereNull('branch_id')->exists());
        $this->assertFalse(DB::table('seller_review_grants')
            ->where('user_id', $user->id)->exists());
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->password);
    }

    public function test_reseeding_does_not_duplicate_the_demo_identity_or_ownership(): void
    {
        config(['demo.seller_email' => 'seller-demo@example.test']);
        $this->seed(DemoCatalogSeeder::class);
        $this->seed(DemoSellerAccountSeeder::class);
        $this->seed(DemoSellerAccountSeeder::class);

        $user = User::query()->where('email', 'seller-demo@example.test')->firstOrFail();
        $this->assertSame(1, User::query()->where('email', $user->email)->count());
        $this->assertSame(1, DB::table('seller_staff_access')
            ->where('user_id', $user->id)->where('role', 'owner')->count());
    }

    public function test_demo_seller_never_upgrades_an_existing_customer(): void
    {
        config(['demo.seller_email' => 'existing@example.test']);
        $this->seed(DemoCatalogSeeder::class);
        $existing = User::factory()->create(['email' => 'existing@example.test']);

        $this->expectException(\LogicException::class);
        $this->seed(DemoSellerAccountSeeder::class);

        $this->assertFalse(DB::table('seller_staff_access')
            ->where('user_id', $existing->id)->exists());
    }
}
