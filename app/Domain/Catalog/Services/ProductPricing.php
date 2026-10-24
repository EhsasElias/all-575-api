<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Pricing\VatCalculator;
use App\Domain\Pricing\VatSplit;
use App\Support\Money;

/** The one place that turns a seller's typed price into what the customer pays. */
final class ProductPricing
{
    public function __construct(private readonly VatCalculator $vat) {}

    public function split(Product $product, ProductVariant $variant): VatSplit
    {
        $entered = Money::halalas($variant->price_halalas);
        $rate = $this->vat->rateFor($product->vat_class);

        return $product->price_includes_vat
            ? $this->vat->splitGross($entered, $rate)
            : $this->vat->addToNet($entered, $rate);
    }

    public function unitGross(Product $product, ProductVariant $variant): Money
    {
        return $this->split($product, $variant)->gross;
    }

    /** Keeps the denormalised min/max gross price columns in step with the active variants. */
    public function refreshPriceRange(Product $product): void
    {
        $grossPrices = $product->variants()->get()
            ->map(fn (ProductVariant $variant): int => $this->unitGross($product, $variant)->halalas);

        $product->forceFill([
            'min_gross_price_halalas' => (int) $grossPrices->min(),
            'max_gross_price_halalas' => (int) $grossPrices->max(),
        ])->save();
    }
}
