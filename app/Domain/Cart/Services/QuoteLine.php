<?php

declare(strict_types=1);

namespace App\Domain\Cart\Services;

use App\Domain\Pricing\VatClass;
use App\Support\Money;

final readonly class QuoteLine
{
    /**
     * @param  array{ar: ?string, en: ?string}  $name
     * @param  array{ar: ?string, en: ?string}|null  $variantName
     */
    public function __construct(
        public int $variantId,
        public int $productId,
        public int $sellerId,
        public string $productSlug,
        public array $name,
        public ?array $variantName,
        public ?string $imageUrl,
        public int $quantity,
        public Money $unitGross,
        public Money $lineGross,
        public Money $discount,
        public VatClass $vatClass,
        public int $vatRateBasisPoints,
        public bool $available,
        public int $availableUnits,
    ) {}

    public function lineTotal(): Money
    {
        return $this->lineGross->minus($this->discount);
    }

    public function withDiscount(Money $discount): self
    {
        return new self(
            $this->variantId, $this->productId, $this->sellerId, $this->productSlug, $this->name, $this->variantName,
            $this->imageUrl, $this->quantity, $this->unitGross, $this->lineGross, $discount, $this->vatClass,
            $this->vatRateBasisPoints, $this->available, $this->availableUnits,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'variant_id' => $this->variantId,
            'product_id' => $this->productId,
            'seller_id' => $this->sellerId,
            'product_slug' => $this->productSlug,
            'name' => $this->name,
            'variant_name' => $this->variantName,
            'image_url' => $this->imageUrl,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitGross->halalas,
            'line_price' => $this->lineGross->halalas,
            'discount' => $this->discount->halalas,
            'line_total' => $this->lineTotal()->halalas,
            'vat_class' => $this->vatClass->value,
            'vat_rate_basis_points' => $this->vatRateBasisPoints,
            'available' => $this->available,
            'available_units' => $this->availableUnits,
        ];
    }
}
