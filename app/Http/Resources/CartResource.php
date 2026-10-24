<?php

namespace App\Http\Resources;

use App\Domain\Cart\Models\Cart;
use App\Domain\Cart\Models\CartItem;
use App\Domain\Catalog\Services\Availability;
use App\Domain\Catalog\Services\ProductPricing;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Expects items.variant.product loaded. `$units` maps variant id => units available in the chosen zone
 * (null until the shopper has an address).
 *
 * @mixin Cart
 */
class CartResource extends JsonResource
{
    /** @param  array<int, int>|null  $units */
    public function __construct(Cart $resource, private readonly ?array $units)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $pricing = app(ProductPricing::class);
        $subtotal = Money::zero();

        $lines = $this->items->map(function (CartItem $item) use ($pricing, &$subtotal): array {
            $variant = $item->variant;
            $unit = $pricing->unitGross();
            $subtotal = $subtotal->plus($unit->times($item->quantity));

            return [
                'variant_id' => $variant->id,
                'product_slug' => $variant->product->slug,
                'name' => ['ar' => $variant->product->name_ar, 'en' => $variant->product->name_en],
                'variant_name' => $variant->name_en === null && $variant->name_ar === null ? null : ['ar' => $variant->name_ar, 'en' => $variant->name_en],
                'image_url' => $variant->product->image_path === null ? null : asset($variant->product->image_path),
                'quantity' => $item->quantity,
                'unit_price' => $unit->halalas,
                'line_price' => $unit->times($item->quantity)->halalas,
                'availability' => Availability::status($this->units === null ? null : ($this->units[$variant->id] ?? 0)),
            ];
        })->all();

        return [
            'lines' => $lines,
            'items_count' => (int) $this->items->sum('quantity'),
            'subtotal' => $subtotal->halalas,
            'currency' => config('store.currency'),
        ];
    }

    /** The cart row is created lazily on first use; that must not turn a normal read or update into a 201. */
    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->setStatusCode(200);
    }
}
