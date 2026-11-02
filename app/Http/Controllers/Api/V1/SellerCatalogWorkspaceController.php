<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SellerCatalogWorkspaceController extends Controller
{
    public function branches(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $branches = DB::table('branches')
            ->join('sellers', 'sellers.id', '=', 'branches.seller_id')
            ->where('sellers.status', 'approved')
            ->whereExists(function ($query) use ($userId): void {
                $query->selectRaw('1')->from('seller_staff_access')
                    ->whereColumn('seller_staff_access.seller_id', 'branches.seller_id')
                    ->where('seller_staff_access.user_id', $userId)
                    ->where('seller_staff_access.is_active', true)
                    ->whereIn('seller_staff_access.role', ['owner', 'branch_manager', 'picker'])
                    ->where(function ($scope): void {
                        $scope->whereNull('seller_staff_access.branch_id')
                            ->orWhereColumn('seller_staff_access.branch_id', 'branches.id');
                    });
            })
            ->select('branches.id', 'branches.seller_id', 'branches.code',
                'branches.name_ar', 'branches.name_en', 'branches.is_active')
            ->orderBy('branches.id')->limit(100)->get();

        return response()->json(['data' => $branches]);
    }

    public function products(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $limit = min(max((int) $request->query('limit', 20), 1), 50);

        $products = DB::table('products')
            ->join('sellers', 'sellers.id', '=', 'products.seller_id')
            ->where('sellers.status', 'approved')
            ->whereExists(function ($query) use ($userId): void {
                $query->selectRaw('1')->from('seller_staff_access')
                    ->whereColumn('seller_staff_access.seller_id', 'products.seller_id')
                    ->where('seller_staff_access.user_id', $userId)
                    ->where('seller_staff_access.is_active', true)
                    ->whereIn('seller_staff_access.role', ['owner', 'branch_manager', 'picker']);
            })
            ->select('products.id', 'products.seller_id', 'products.category_id', 'products.slug',
                'products.name_ar', 'products.name_en', 'products.status', 'products.created_at')
            ->orderBy('products.id')->limit($limit)->get();

        return response()->json(['data' => $products]);
    }

    public function stock(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'branch_id' => ['sometimes', 'integer', 'min:1'],
            'low_stock_below' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
        ]);
        $limit = $validated['limit'] ?? 20;

        $stock = DB::table('branch_stock')
            ->join('branches', 'branches.id', '=', 'branch_stock.branch_id')
            ->join('sellers', 'sellers.id', '=', 'branches.seller_id')
            ->join('product_variants', 'product_variants.id', '=', 'branch_stock.variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereColumn('products.seller_id', 'branches.seller_id')
            ->where('sellers.status', 'approved')
            ->whereExists(function ($query) use ($userId): void {
                $query->selectRaw('1')->from('seller_staff_access')
                    ->whereColumn('seller_staff_access.seller_id', 'branches.seller_id')
                    ->where('seller_staff_access.user_id', $userId)
                    ->where('seller_staff_access.is_active', true)
                    ->whereIn('seller_staff_access.role', ['owner', 'branch_manager', 'picker'])
                    ->where(function ($scope): void {
                        $scope->whereNull('seller_staff_access.branch_id')
                            ->orWhereColumn('seller_staff_access.branch_id', 'branches.id');
                    });
            })
            ->when(isset($validated['branch_id']), fn ($query) => $query->where('branches.id', $validated['branch_id']))
            ->when(isset($validated['low_stock_below']), fn ($query) => $query->whereRaw(
                '(branch_stock.on_hand - branch_stock.reserved) <= ?',
                [$validated['low_stock_below']]
            ))
            ->select('branch_stock.id', 'branch_stock.branch_id', 'branch_stock.variant_id',
                'branch_stock.on_hand', 'branch_stock.reserved', 'product_variants.sku', 'products.name_ar',
                'products.name_en')
            ->orderBy('branch_stock.id')->limit($limit)->get();

        return response()->json(['data' => $stock]);
    }
}
