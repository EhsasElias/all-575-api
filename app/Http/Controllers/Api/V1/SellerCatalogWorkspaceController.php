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
                'branches.name_ar', 'branches.name_en', 'branches.is_active',
                'branches.lat', 'branches.lng', 'branches.service_zone_id')
            ->orderBy('branches.id')->limit(100)->get();

        $coverage = DB::table('branch_service_zones')
            ->whereIn('branch_id', $branches->pluck('id')->all())
            ->orderBy('service_zone_id')
            ->get(['branch_id', 'service_zone_id'])
            ->groupBy('branch_id');
        $branches->transform(function ($branch) use ($coverage) {
            $zoneIds = ($coverage->get($branch->id) ?? collect())
                ->pluck('service_zone_id')->map(fn ($id): int => (int) $id)->all();
            $branch->service_zone_ids = $zoneIds === [] ? [(int) $branch->service_zone_id] : $zoneIds;
            return $branch;
        });

        return response()->json(['data' => $branches]);
    }

    public function products(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $filters = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'seller_id' => ['sometimes', 'integer', 'min:1'],
            'category_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', 'max:32'],
            'before_id' => ['sometimes', 'integer', 'min:1'],
        ]);
        $limit = $filters['limit'] ?? 20;

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
            ->when(isset($filters['seller_id']), fn ($query) => $query->where('products.seller_id', $filters['seller_id']))
            ->when(isset($filters['category_id']), fn ($query) => $query->where('products.category_id', $filters['category_id']))
            ->when(isset($filters['status']), fn ($query) => $query->where('products.status', $filters['status']))
            ->when(isset($filters['before_id']), fn ($query) => $query->where('products.id', '<', $filters['before_id']))
            ->select('products.id', 'products.seller_id', 'products.category_id', 'products.slug',
                'products.name_ar', 'products.name_en', 'products.description_ar', 'products.description_en',
                'products.image_path', 'products.price_halalas', 'products.vat_class', 'products.status', 'products.created_at')
            ->selectSub(function ($query): void {
                $query->from('product_variants')
                    ->select('sku')
                    ->whereColumn('product_variants.product_id', 'products.id')
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->limit(1);
            }, 'sku')
            ->selectSub(function ($query): void {
                $query->from('seller_review_audits')
                    ->select('reason')
                    ->where('subject_type', 'product')
                    ->where('new_status', 'rejected')
                    ->whereColumn('subject_id', 'products.id')
                    ->orderByDesc('id')
                    ->limit(1);
            }, 'rejection_reason')
            ->orderByDesc('products.id')->limit($limit)->get();

        return response()->json(['data' => $products]);
    }

    public function stock(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'branch_id' => ['sometimes', 'integer', 'min:1'],
            'low_stock_below' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'before_id' => ['sometimes', 'integer', 'min:1'],
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
            ->when(isset($validated['before_id']), fn ($query) => $query->where('branch_stock.id', '<', $validated['before_id']))
            ->select('branch_stock.id', 'branch_stock.branch_id', 'branch_stock.variant_id',
                'branch_stock.on_hand', 'branch_stock.reserved', 'product_variants.sku', 'products.name_ar',
                'products.name_en')
            ->orderByDesc('branch_stock.id')->limit($limit)->get();

        return response()->json(['data' => $stock]);
    }
}
