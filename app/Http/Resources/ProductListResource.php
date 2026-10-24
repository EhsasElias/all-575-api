<?php

namespace App\Http\Resources;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\Availability;
use App\Domain\Catalog\Services\ProductPricing;
use App\Http\Resources\Concerns\PublicAssets;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Expects `seller` and `category` eager loaded and an `available_units` attribute
 * (null when the shopper has not chosen a delivery zone yet).
 *
 * @mixin Product
 */
class ProductListResource extends JsonResource
{
    use PublicAssets;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => ['ar' => $this->name_ar, 'en' => $this->name_en],
            'image_url' => $this->assetUrl($this->image_path),
            'seller' => new SellerResource($this->seller),
            'category' => ['slug' => $this->category->slug, 'name' => ['ar' => $this->category->name_ar, 'en' => $this->category->name_en]],
            'price' => $this->price(),
            'rating' => ['average' => $this->rating_average, 'count' => $this->rating_count],
            'availability' => Availability::status($this->available_units),
        ];
    }

    /** @return array{gross: int, net: int, vat: int, currency: string} */
    protected function price(): array
    {
        $split = app(ProductPricing::class)->split($this->resource);

        return [
            'gross' => $split->gross->halalas,
            'net' => $split->net->halalas,
            'vat' => $split->vat->halalas,
            'currency' => config('store.currency'),
        ];
    }
}
