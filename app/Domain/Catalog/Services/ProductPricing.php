<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Pricing\VatCalculator;
use App\Domain\Pricing\VatClass;
use App\Domain\Pricing\VatSplit;
use App\Support\Money;

/** Server-owned product prices are stored BEFORE VAT. */
final class ProductPricing
{
    public function __construct(private readonly VatCalculator $vat) {}

    /** The same price as a plain number of halalas, for building responses. */
    public function unitPriceHalalas(?Product $product = null): int
    {
        return (int) ($product?->price_halalas ?? config('store.price_halalas'));
    }

    /** Stored catalog amount, before tax. Kept for compatibility with existing callers. */
    public function unitGross(?Product $product = null): Money
    {
        return Money::halalas($this->unitPriceHalalas($product));
    }

    public function unitNet(?Product $product = null): Money
    {
        return $this->unitGross($product);
    }

    /** Add the class-specific VAT to the stored net price; 5.00 + 0.75 = 5.75 at 15%. */
    public function split(VatClass $class, ?Product $product = null): VatSplit
    {
        return $this->vat->addToNet($this->unitNet($product), $this->vat->rateFor($class));
    }
}
