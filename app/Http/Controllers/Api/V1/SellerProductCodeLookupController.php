<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Find only products owned by the active seller. Never disclose another seller's
 * private drafts when a barcode is scanned.
 */
final class SellerProductCodeLookupController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $input = $request->validate([
            'seller_id' => ['required', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:100'],
        ]);
        $sellerId = (int) $input['seller_id'];
        $authorized = DB::table('sellers')->where('id', $sellerId)
            ->where('status', 'approved')
            ->whereExists(function ($query) use ($request): void {
                $query->selectRaw('1')->from('seller_staff_access')
                    ->whereColumn('seller_staff_access.seller_id', 'sellers.id')
                    ->where('seller_staff_access.user_id', $request->user()->id)
                    ->where('seller_staff_access.is_active', true)
                    ->whereIn('seller_staff_access.role', ['owner', 'branch_manager']);
            })->exists();
        abort_unless($authorized, 404);

        $code = trim($input['code']);
        abort_if($code === '', 422, 'Enter a barcode or SKU.');
        $result = DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('products.seller_id', $sellerId)
            ->where(function ($query) use ($code): void {
                $query->where('product_variants.barcode', $code)
                    ->orWhere('product_variants.sku', $code);
            })
            ->select(
                'products.id', 'products.category_id', 'products.name_ar', 'products.name_en',
                'products.description_ar', 'products.description_en',
                'products.price_halalas', 'products.vat_class', 'products.status',
                'product_variants.sku', 'product_variants.barcode'
            )
            ->first();

        return response()->json(['data' => $result]);
    }
}
