<?php

namespace App\Http\Resources;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Services\Availability;
use App\Domain\Catalog\Services\ProductPricing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductVariant */
class VariantResource extends JsonResource
{
    public function __construct(ProductVariant $resource, private readonly Product $product, private readonly ?int $availableUnits)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $split = app(ProductPricing::class)->split($this->product, $this->resource);

        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name_en === null && $this->name_ar === null ? null : ['ar' => $this->name_ar, 'en' => $this->name_en],
            'price' => [
                'gross' => $split->gross->halalas,
                'net' => $split->net->halalas,
                'vat' => $split->vat->halalas,
                'currency' => config('store.currency'),
            ],
            'availability' => Availability::status($this->availableUnits),
        ];
    }
}
