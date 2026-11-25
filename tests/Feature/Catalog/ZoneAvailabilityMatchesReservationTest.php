<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Branch;
use App\Domain\Catalog\Models\BranchStock;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\ServiceZone;
use App\Domain\Catalog\Services\Availability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ZoneAvailabilityMatchesReservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_does_not_add_stock_from_separate_branches_for_a_single_variant(): void
    {
        $zone = ServiceZone::factory()->create();
        $product = Product::factory()->create(['price_halalas' => 300]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $first = Branch::factory()->create(['seller_id' => $product->seller_id, 'service_zone_id' => $zone->id]);
        $second = Branch::factory()->create(['seller_id' => $product->seller_id, 'service_zone_id' => $zone->id]);

        BranchStock::factory()->create(['branch_id' => $first->id, 'variant_id' => $variant->id, 'on_hand' => 6, 'reserved' => 4]);
        BranchStock::factory()->create(['branch_id' => $second->id, 'variant_id' => $variant->id, 'on_hand' => 5, 'reserved' => 2]);

        $availability = app(Availability::class);
        $this->assertSame(3, $availability->forVariants([$variant->id], $zone->id)[$variant->id]);
        $this->assertSame(3, $availability->forProducts([$product->id], $zone->id)[$product->id]);
    }

    public function test_it_ignores_stock_outside_zone_and_inactive_branches(): void
    {
        $zone = ServiceZone::factory()->create();
        $otherZone = ServiceZone::factory()->create();
        $product = Product::factory()->create(['price_halalas' => 300]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $local = Branch::factory()->create(['seller_id' => $product->seller_id, 'service_zone_id' => $zone->id]);
        $closed = Branch::factory()->create(['seller_id' => $product->seller_id, 'service_zone_id' => $zone->id, 'is_active' => false]);
        $distant = Branch::factory()->create(['seller_id' => $product->seller_id, 'service_zone_id' => $otherZone->id]);

        BranchStock::factory()->create(['branch_id' => $local->id, 'variant_id' => $variant->id, 'on_hand' => 4, 'reserved' => 1]);
        BranchStock::factory()->create(['branch_id' => $closed->id, 'variant_id' => $variant->id, 'on_hand' => 100, 'reserved' => 0]);
        BranchStock::factory()->create(['branch_id' => $distant->id, 'variant_id' => $variant->id, 'on_hand' => 100, 'reserved' => 0]);

        $availability = app(Availability::class);
        $this->assertSame(3, $availability->forVariants([$variant->id], $zone->id)[$variant->id]);
        $this->assertSame(3, $availability->forProducts([$product->id], $zone->id)[$product->id]);
    }

    public function test_product_availability_sums_the_best_branch_of_each_active_variant(): void
    {
        $zone = ServiceZone::factory()->create();
        $product = Product::factory()->create(['price_halalas' => 300]);
        $firstVariant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $secondVariant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $inactiveVariant = ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => false]);
        $branch = Branch::factory()->create(['seller_id' => $product->seller_id, 'service_zone_id' => $zone->id]);

        BranchStock::factory()->create(['branch_id' => $branch->id, 'variant_id' => $firstVariant->id, 'on_hand' => 3, 'reserved' => 0]);
        BranchStock::factory()->create(['branch_id' => $branch->id, 'variant_id' => $secondVariant->id, 'on_hand' => 6, 'reserved' => 1]);
        BranchStock::factory()->create(['branch_id' => $branch->id, 'variant_id' => $inactiveVariant->id, 'on_hand' => 100, 'reserved' => 0]);

        $this->assertSame(8, app(Availability::class)->forProducts([$product->id], $zone->id)[$product->id]);
    }
}
