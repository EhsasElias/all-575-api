<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use Illuminate\Support\Facades\DB;

/** How many units can be bought right now across active branches: on hand minus held for unpaid orders. */
final class Availability
{
    public const LOW_STOCK_AT_OR_BELOW = 5;

    /**
     * @param  list<int>  $variantIds
     * @return array<int, int> variant id => units available at the best active branch
     */
    // The optional zoneId is kept only for backwards-compatible callers.
    public function forVariants(array $variantIds, ?int $zoneId = null): array
    {
        if ($variantIds === []) {
            return [];
        }

        // The reservation service fulfils a line from ONE branch. Summing
        // several branches here would advertise quantities that checkout
        // cannot actually reserve.
        return DB::table('branch_stock')
            ->join('branches', 'branches.id', '=', 'branch_stock.branch_id')
            ->where('branches.is_active', true)
            ->whereIn('branch_stock.variant_id', $variantIds)
            ->groupBy('branch_stock.variant_id')
            ->selectRaw('branch_stock.variant_id as variant_id,
                MAX(CASE WHEN branch_stock.on_hand > branch_stock.reserved
                    THEN branch_stock.on_hand - branch_stock.reserved ELSE 0 END) as available')
            ->pluck('available', 'variant_id')
            ->map(fn (mixed $units): int => max(0, (int) $units))
            ->all();
    }

    /**
     * @param list<int> $productIds
     * @return array<int, int> product id => total independently purchasable units
     *         across active variants, using the best active branch per variant.
     */
    // The optional zoneId is kept only for backwards-compatible callers.
    public function forProducts(array $productIds, ?int $zoneId = null): array
    {
        if ($productIds === []) {
            return [];
        }

        $perVariant = DB::table('branch_stock')
            ->join('branches', 'branches.id', '=', 'branch_stock.branch_id')
            ->join('product_variants', 'product_variants.id', '=', 'branch_stock.variant_id')
            ->where('branches.is_active', true)
            ->where('product_variants.is_active', true)
            ->whereIn('product_variants.product_id', $productIds)
            ->groupBy('product_variants.product_id', 'branch_stock.variant_id')
            ->selectRaw('product_variants.product_id as product_id, branch_stock.variant_id as variant_id,
                MAX(CASE WHEN branch_stock.on_hand > branch_stock.reserved
                    THEN branch_stock.on_hand - branch_stock.reserved ELSE 0 END) as available');

        return DB::query()->fromSub($perVariant, 'variant_stock')
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(available) as available')
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
