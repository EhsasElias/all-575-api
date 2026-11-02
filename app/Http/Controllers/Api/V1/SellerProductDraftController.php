<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Pricing\VatClass;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Sellers may compose drafts, but publication remains a back-office decision.
 */
final class SellerProductDraftController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);
        $this->ensureSellerAccess($request, (int) $data['seller_id']);

        $product = DB::transaction(static function () use ($data): Product {
            return Product::query()->create([
                ...$data,
                'slug' => (string) Str::uuid(),
                'status' => ProductStatus::Draft,
                'is_featured' => false,
                'rating_average' => 0,
                'rating_count' => 0,
            ]);
        });

        return response()->json(['data' => $this->representation($product)], 201);
    }

    public function update(Request $request, int $product): JsonResponse
    {
        $data = $this->validated($request, false);

        $updated = DB::transaction(function () use ($request, $product, $data): Product {
            $item = Product::query()->lockForUpdate()->findOrFail($product);
            $this->ensureSellerAccess($request, (int) $item->seller_id);
            abort_unless(in_array($item->status, [ProductStatus::Draft, ProductStatus::Rejected], true),
                409, 'Only draft or rejected products can be edited.');

            $item->fill($data);
            $item->status = ProductStatus::Draft;
            $item->save();

            return $item;
        }, 3);

        return response()->json(['data' => $this->representation($updated)]);
    }

    public function submit(Request $request, int $product): JsonResponse
    {
        $updated = DB::transaction(function () use ($request, $product): Product {
            $item = Product::query()->lockForUpdate()->findOrFail($product);
            $this->ensureSellerAccess($request, (int) $item->seller_id);
            abort_unless($item->status === ProductStatus::Draft, 409, 'Only drafts may be submitted.');
            abort_unless($item->variants()->exists(), 422, 'Add a product variant before submitting.');

            $item->status = ProductStatus::PendingReview;
            $item->save();

            return $item;
        }, 3);

        return response()->json(['data' => $this->representation($updated)]);
    }

    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'seller_id' => [$creating ? 'required' : 'prohibited', 'integer', 'min:1'],
            'category_id' => [$creating ? 'required' : 'sometimes', 'integer',
                Rule::exists('categories', 'id')->where('is_active', true)],
            'name_ar' => [$creating ? 'required' : 'sometimes', 'string', 'max:180'],
            'name_en' => [$creating ? 'required' : 'sometimes', 'string', 'max:180'],
            'description_ar' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'description_en' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'price_halalas' => [$creating ? 'required' : 'sometimes', 'integer', 'between:100,575'],
            'vat_class' => [$creating ? 'required' : 'sometimes', Rule::enum(VatClass::class)],
        ]);
    }

    private function ensureSellerAccess(Request $request, int $sellerId): void
    {
        $authorized = DB::table('sellers')->where('id', $sellerId)->where('status', 'approved')
            ->whereExists(function ($query) use ($request): void {
                $query->selectRaw('1')->from('seller_staff_access')
                    ->whereColumn('seller_staff_access.seller_id', 'sellers.id')
                    ->where('seller_staff_access.user_id', $request->user()->id)
                    ->where('seller_staff_access.is_active', true)
                    ->whereIn('seller_staff_access.role', ['owner', 'branch_manager']);
            })->exists();

        abort_unless($authorized, 404);
    }

    private function representation(Product $product): array
    {
        return [
            'id' => $product->id,
            'seller_id' => $product->seller_id,
            'category_id' => $product->category_id,
            'name_ar' => $product->name_ar,
            'name_en' => $product->name_en,
            'price_halalas' => $product->price_halalas,
            'vat_class' => $product->vat_class->value,
            'status' => $product->status->value,
        ];
    }
}
