<?php

namespace App\Http\Resources;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Http\Request;

/**
 * Adds description and variants. `variant_units` maps variant id => units available in the chosen zone.
 *
 * @mixin Product
 */
class ProductDetailResource extends ProductListResource
{
    /** @param  array<int, int>|null  $variantUnits */
    public function __construct(Product $resource, private readonly ?array $variantUnits)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'description' => ['ar' => $this->description_ar, 'en' => $this->description_en],
            'vat_class' => $this->vat_class->value,
            'variants' => $this->variants->map(fn (ProductVariant $variant): VariantResource => new VariantResource(
                $variant,
                $this->resource,
                $this->variantUnits === null ? null : ($this->variantUnits[$variant->id] ?? 0),
            ))->all(),
        ];
    }
}
