<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Pricing\VatCalculator;
use App\Domain\Pricing\VatSplit;
use App\Support\Money;

/** Every product costs the same: the platform price, VAT included. The one place that says so. */
final class ProductPricing
{
    public function __construct(private readonly VatCalculator $vat) {}

    public function unitGross(): Money
    {
        return Money::halalas((int) config('store.price_halalas'));
    }

    /** What the platform price is made of for this product: 5.75 is 5.00 + 0.75 VAT, or 5.75 + 0.00 when zero-rated or exempt. */
    public function split(Product $product): VatSplit
    {
        return $this->vat->splitGross($this->unitGross(), $this->vat->rateFor($product->vat_class));
    }
}
