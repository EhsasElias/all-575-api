<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Pricing\VatCalculator;
use App\Domain\Pricing\VatClass;
use App\Domain\Pricing\VatSplit;
use App\Support\Money;

/** Server-owned product prices, VAT included. Existing products default to 575 halalas. */
final class ProductPricing
{
    public function __construct(private readonly VatCalculator $vat) {}

    /** The same price as a plain number of halalas, for building responses. */
    public function unitPriceHalalas(?Product $product = null): int
    {
        return (int) ($product?->price_halalas ?? config('store.price_halalas'));
    }

    public function unitGross(?Product $product = null): Money
    {
        return Money::halalas($this->unitPriceHalalas($product));
    }

    /** What the platform price is made of for a VAT class: 5.75 is 5.00 + 0.75 VAT, or 5.75 + 0.00 when zero-rated or exempt. */
    public function split(VatClass $class, ?Product $product = null): VatSplit
    {
        return $this->vat->splitGross($this->unitGross($product), $this->vat->rateFor($class));
    }
}
