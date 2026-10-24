<?php

declare(strict_types=1);

namespace App\Domain\Cart\Services;

use App\Support\Money;

/** A priced snapshot of the cart for one address. All amounts are VAT-inclusive halalas. */
final readonly class QuoteData
{
    /**
     * @param  list<QuoteLine>  $lines
     * @param  list<array{vat_class: string, rate_basis_points: int, taxable_net: int, vat: int, gross: int}>  $vat
     * @param  list<array{variant_id: int, code: string, available_units: int}>  $issues
     */
    public function __construct(
        public array $lines,
        public Money $subtotal,
        public Money $discount,
        public Money $deliveryFee,
        public Money $total,
        public array $vat,
        public ?string $promoCode,
        public int $addressId,
        public int $zoneId,
        public array $issues,
    ) {}

    public function canCheckout(): bool
    {
        return $this->issues === [] && $this->lines !== [];
    }

    /** Changes whenever anything the customer agreed to pays for changes: items, prices, discount, fee, total. */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            array_map(fn (QuoteLine $line): array => [$line->variantId, $line->quantity, $line->unitGross->halalas, $line->discount->halalas], $this->lines),
            $this->discount->halalas,
            $this->deliveryFee->halalas,
            $this->total->halalas,
            $this->promoCode,
        ], JSON_THROW_ON_ERROR));
    }

    public function totalVat(): Money
    {
        return Money::halalas((int) array_sum(array_column($this->vat, 'vat')));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'currency' => config('store.currency'),
            'lines' => array_map(fn (QuoteLine $line): array => $line->toArray(), $this->lines),
            'subtotal' => $this->subtotal->halalas,
            'discount' => $this->discount->halalas,
            'delivery_fee' => $this->deliveryFee->halalas,
            'total' => $this->total->halalas,
            'vat_included' => $this->totalVat()->halalas,
            'vat' => $this->vat,
            'promo_code' => $this->promoCode,
            'address_id' => $this->addressId,
            'zone_id' => $this->zoneId,
            'issues' => $this->issues,
            'can_checkout' => $this->canCheckout(),
        ];
    }
}
