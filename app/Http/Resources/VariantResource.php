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

    /**
     * @return array{id: int, sku: string, name: ?array{ar: ?string, en: ?string}, price: array{gross: int, net: int, vat: int, currency: string}, availability: 'unknown'|'in_stock'|'low_stock'|'out_of_stock'}
     */
    public function toArray(Request $request): array
    {
        $split = app(ProductPricing::class)->split($this->product->vatClass());

        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name_en === null && $this->name_ar === null ? null : ['ar' => $this->name_ar, 'en' => $this->name_en],
            'price' => [
                'gross' => (int) $split->gross->toInt(),
                'net' => (int) $split->net->toInt(),
                'vat' => (int) $split->vat->toInt(),
                'currency' => config('store.currency'),
            ],
            'availability' => Availability::status($this->availableUnits),
        ];
    }
}
