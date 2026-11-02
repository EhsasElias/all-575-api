<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Updates physical stock only. Reserved inventory is owned by the checkout workflow.
 */
final class SellerStockAdjustmentController extends Controller
{
    public function __invoke(Request $request, int $stock): JsonResponse
    {
        $input = $request->validate([
            'on_hand' => ['required', 'integer', 'min:0', 'max:100000000'],
        ]);

        $result = DB::transaction(function () use ($request, $stock, $input): array {
            $row = DB::table('branch_stock')
                ->join('branches', 'branches.id', '=', 'branch_stock.branch_id')
                ->join('sellers', 'sellers.id', '=', 'branches.seller_id')
                ->join('product_variants', 'product_variants.id', '=', 'branch_stock.variant_id')
                ->join('products', 'products.id', '=', 'product_variants.product_id')
                ->whereColumn('products.seller_id', 'branches.seller_id')
                ->where('sellers.status', 'approved')
                ->where('branch_stock.id', $stock)
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
                ->select('branch_stock.id', 'branch_stock.on_hand', 'branch_stock.reserved')
                ->lockForUpdate()
                ->first();

            abort_if($row === null, 404);

            if ($input['on_hand'] < $row->reserved) {
                throw ValidationException::withMessages([
                    'on_hand' => ['Stock cannot be lower than currently reserved quantity.'],
                ]);
            }

            DB::table('branch_stock')->where('id', $row->id)->update([
                'on_hand' => $input['on_hand'],
                'updated_at' => now(),
            ]);

            return [
                'id' => $row->id,
                'on_hand' => $input['on_hand'],
                'reserved' => $row->reserved,
                'available' => $input['on_hand'] - $row->reserved,
            ];
        }, 3);

        return response()->json(['data' => $result]);
    }
}
