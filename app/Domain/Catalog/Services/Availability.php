<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use Illuminate\Support\Facades\DB;

/** How many units can be bought right now in a delivery zone: on hand minus held for unpaid orders. */
final class Availability
{
    public const LOW_STOCK_AT_OR_BELOW = 5;

    /**
     * @param  list<int>  $variantIds
     * @return array<int, int> variant id => units available in the zone
     */
    public function forVariants(array $variantIds, int $zoneId): array
    {
        if ($variantIds === []) {
            return [];
        }

        return DB::table('branch_stock')
            ->join('branches', 'branches.id', '=', 'branch_stock.branch_id')
            ->where('branches.service_zone_id', $zoneId)
            ->where('branches.is_active', true)
            ->whereIn('branch_stock.variant_id', $variantIds)
            ->groupBy('branch_stock.variant_id')
            ->selectRaw('branch_stock.variant_id as variant_id, SUM(branch_stock.on_hand - branch_stock.reserved) as available')
            ->pluck('available', 'variant_id')
            ->map(fn (mixed $units): int => max(0, (int) $units))
            ->all();
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, int> product id => units available across all its variants
     */
    public function forProducts(array $productIds, int $zoneId): array
    {
        if ($productIds === []) {
            return [];
        }

        return DB::table('branch_stock')
            ->join('branches', 'branches.id', '=', 'branch_stock.branch_id')
            ->join('product_variants', 'product_variants.id', '=', 'branch_stock.variant_id')
            ->where('branches.service_zone_id', $zoneId)
            ->where('branches.is_active', true)
            ->where('product_variants.is_active', true)
            ->whereIn('product_variants.product_id', $productIds)
            ->groupBy('product_variants.product_id')
            ->selectRaw('product_variants.product_id as product_id, SUM(branch_stock.on_hand - branch_stock.reserved) as available')
            ->pluck('available', 'product_id')
            ->map(fn (mixed $units): int => max(0, (int) $units))
            ->all();
    }

    public static function status(?int $available): string
    {
        return match (true) {
            $available === null => 'unknown',
            $available <= 0 => 'out_of_stock',
            $available <= self::LOW_STOCK_AT_OR_BELOW => 'low_stock',
            default => 'in_stock',
        };
    }
}
