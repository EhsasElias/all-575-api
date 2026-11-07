<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Product;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;

/**
 * Stores the product cover image after checking seller access and draft status.
 * Previous images are removed only after the replacement is committed.
 */
final class SellerProductImageController extends Controller
{
    public function __invoke(Request $request, int $product): JsonResponse
    {
        $data = $request->validate([
            'image' => ['required', File::image()->max('5mb')],
        ]);

        $item = Product::query()->findOrFail($product);
        $authorized = DB::table('sellers')
            ->where('id', $item->seller_id)->where('status', 'approved')
            ->whereExists(function ($query) use ($request): void {
                $query->selectRaw('1')->from('seller_staff_access')
                    ->whereColumn('seller_staff_access.seller_id', 'sellers.id')
                    ->where('seller_staff_access.user_id', $request->user()->id)
                    ->where('seller_staff_access.is_active', true)
                    ->whereIn('seller_staff_access.role', ['owner', 'branch_manager']);
            })->exists();
        abort_unless($authorized, 404);

        abort_unless(in_array($item->status, [ProductStatus::Draft, ProductStatus::Rejected], true),
            409, 'Only draft or rejected products can change images.');

        $path = $data['image']->store("products/{$item->seller_id}", 'public');
        if ($path === false) {
            abort(500, 'Unable to store product image.');
        }

        try {
            $oldPath = DB::transaction(function () use ($item, $path): ?string {
                $locked = Product::query()->lockForUpdate()->findOrFail($item->id);
                abort_unless(in_array($locked->status, [ProductStatus::Draft, ProductStatus::Rejected], true),
                    409, 'Product status changed. Please refresh.');
                $previous = $locked->image_path;
                $locked->forceFill(['image_path' => $path, 'status' => ProductStatus::Draft])->save();

                return $previous;
            }, 3);
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($path);
            throw $exception;
        }

        if ($oldPath && $oldPath !== $path) {
            Storage::disk('public')->delete($oldPath);
        }

        return response()->json(['data' => ['product_id' => $item->id, 'image_path' => $path]]);
    }
}
