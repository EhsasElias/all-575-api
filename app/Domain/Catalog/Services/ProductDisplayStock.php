<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use Illuminate\Support\Facades\DB;

/**
 * Public-facing storefront branch labels.
 *
 * A product may be stocked in multiple branches. Display the active branch
 * with the most unreserved units; this is informative, not a delivery
 * promise or checkout allocation (reservation may choose another branch).
 */
final class ProductDisplayStock
{
    /**
     * @param list<int> $productIds
     * @return array<int, array{branch: array{ar: string, en: string}, variant_id: int}>
     */
    public function branchesForProducts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $stock = DB::table('branch_stock')
            ->join('branches', 'branches.id', '=', 'branch_stock.branch_id')
            ->join('product_variants', 'product_variants.id', '=', 'branch_stock.variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereIn('products.id', $productIds)
            ->where('branches.is_active', true)
            ->where('product_variants.is_active', true)
            ->whereColumn('branches.seller_id', 'products.seller_id')
            ->whereColumn('branch_stock.on_hand', '>', 'branch_stock.reserved')
            ->select(
                'products.id as product_id',
                'product_variants.id as variant_id',
                'branches.name_ar as name_ar',
                'branches.name_en as name_en'
            )
            ->selectRaw('(branch_stock.on_hand - branch_stock.reserved) as free_units')
            ->orderByDesc('free_units')
            ->orderBy('branches.id')
            ->get();

        $names = [];
        foreach ($stock as $row) {
            $id = (int) $row->product_id;
            if (! isset($names[$id])) {
                $names[$id] = [
                    'branch' => ['ar' => (string) $row->name_ar, 'en' => (string) $row->name_en],
                    'variant_id' => (int) $row->variant_id,
                ];
            }
        }

        return $names;
    }
}
