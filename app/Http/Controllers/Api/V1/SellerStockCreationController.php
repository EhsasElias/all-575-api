<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Attach an already-owned product variant to an authorized branch stock ledger. */
final class SellerStockCreationController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'min:1'],
            'sku' => ['required', 'string', 'max:100'],
            'on_hand' => ['required', 'integer', 'between:0,100000000'],
        ]);
        $sku = trim($data['sku']);
        if ($sku === '') {
            throw ValidationException::withMessages(['sku' => ['Enter a valid SKU.']]);
        }

        $branch = DB::table('branches')
            ->join('sellers', 'sellers.id', '=', 'branches.seller_id')
            ->where('branches.id', $data['branch_id'])
            ->where('sellers.status', 'approved')
            ->whereExists(function ($query) use ($request): void {
                $query->selectRaw('1')->from('seller_staff_access')
                    ->whereColumn('seller_staff_access.seller_id', 'branches.seller_id')
                    ->where('seller_staff_access.user_id', $request->user()->id)
                    ->where('seller_staff_access.is_active', true)
                    ->whereIn('seller_staff_access.role', ['owner', 'branch_manager'])
                    ->where(function ($scope): void {
                        $scope->whereNull('seller_staff_access.branch_id')
                            ->orWhereColumn('seller_staff_access.branch_id', 'branches.id');
                    });
            })
            ->select('branches.id', 'branches.seller_id')
            ->first();
        abort_if($branch === null, 404);

        $variant = DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('products.seller_id', $branch->seller_id)
            ->where('product_variants.sku', $sku)
            ->select('product_variants.id')
            ->first();

        if ($variant === null) {
            throw ValidationException::withMessages([
                'sku' => ['SKU not found among products owned by this store.'],
            ]);
        }

        try {
            $id = DB::table('branch_stock')->insertGetId([
                'branch_id' => $branch->id,
                'variant_id' => $variant->id,
                'on_hand' => (int) $data['on_hand'],
                'reserved' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Stock already exists for this SKU in the branch; use adjust stock instead.');
        }

        return response()->json(['data' => [
            'id' => $id,
            'branch_id' => $branch->id,
            'variant_id' => $variant->id,
            'on_hand' => (int) $data['on_hand'],
            'reserved' => 0,
        ]], 201);
    }
}
