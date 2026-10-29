<?php

namespace App\Http\Resources;

use App\Domain\Cart\Models\Cart;
use App\Domain\Cart\Models\CartItem;
use App\Domain\Catalog\Services\Availability;
use App\Domain\Catalog\Services\ProductPricing;
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

    /**
     * @return array{
     *         lines: list<array{variant_id: int, product_slug: string, name: array{ar: string, en: string}, variant_name: ?array{ar: ?string, en: ?string}, image_url: ?string, quantity: int, unit_price: int, line_price: int, availability: 'unknown'|'in_stock'|'low_stock'|'out_of_stock'}>,
     *         items_count: int, subtotal: int, currency: string}
     */
    public function toArray(Request $request): array
    {
        $pricing = app(ProductPricing::class);
        $quantity = (int) $this->items->sum('quantity');

        return [
            'lines' => $this->items->map(fn (CartItem $item): array => [
                'variant_id' => $item->variant->id,
                'product_slug' => $item->variant->product->slug,
                'name' => ['ar' => $item->variant->product->name_ar, 'en' => $item->variant->product->name_en],
                'variant_name' => $item->variant->name_en === null && $item->variant->name_ar === null ? null : ['ar' => $item->variant->name_ar, 'en' => $item->variant->name_en],
                'image_url' => $item->variant->product->image_path === null ? null : asset($item->variant->product->image_path),
                'quantity' => $item->quantity,
                'unit_price' => (int) $pricing->unitPriceHalalas($item->variant->product),
                'line_price' => $pricing->unitPriceHalalas($item->variant->product) * $item->quantity,
                'availability' => Availability::status($this->units === null ? null : ($this->units[$item->variant->id] ?? 0)),
            ])->all(),
            'items_count' => $quantity,
            'subtotal' => (int) $this->items->sum(fn (CartItem $item): int => $pricing->unitPriceHalalas($item->variant->product) * $item->quantity),
            'currency' => config('store.currency'),
        ];
    }

    /** The cart row is created lazily on first use; that must not turn a normal read or update into a 201. */
    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->setStatusCode(200);
    }
}
