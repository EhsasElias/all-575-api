<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Product;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class SellerProductVariantController extends Controller
{
    public function store(Request $request, int $product): JsonResponse
    {
        $input = $request->validate([
            'sku' => ['required', 'string', 'max:100', Rule::unique('product_variants', 'sku')],
            'barcode' => ['nullable', 'string', 'max:100', Rule::unique('product_variants', 'barcode')],
            'name_ar' => ['nullable', 'string', 'max:180'],
            'name_en' => ['nullable', 'string', 'max:180'],
        ]);

        $variant = DB::transaction(function () use ($request, $product, $input) {
            $item = Product::query()->lockForUpdate()->findOrFail($product);
            abort_unless(in_array($item->status, [ProductStatus::Draft, ProductStatus::Rejected], true),
                409, 'Product must be editable.');

            $authorized = DB::table('seller_staff_access')
                ->where('seller_id', $item->seller_id)
                ->where('user_id', $request->user()->id)
                ->where('is_active', true)
                ->whereIn('role', ['owner', 'branch_manager'])
                ->exists();
            $approved = DB::table('sellers')->where('id', $item->seller_id)
                ->where('status', 'approved')->exists();
            abort_unless($authorized && $approved, 404);

            $item->status = ProductStatus::Draft;
            $item->save();

            return $item->variants()->create([
                ...$input,
                'is_active' => true,
                'sort_order' => 0,
            ]);
        }, 3);

        return response()->json(['data' => [
            'id' => $variant->id,
            'product_id' => $variant->product_id,
            'sku' => $variant->sku,
            'barcode' => $variant->barcode,
        ]], 201);
    }
}
