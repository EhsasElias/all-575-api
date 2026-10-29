<?php

declare(strict_types=1);

namespace App\Domain\Cart\Services;

use App\Domain\Addressing\Models\Address;
use App\Domain\Cart\Models\Cart;
use App\Domain\Cart\Models\CartItem;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Enums\SellerStatus;
use App\Domain\Catalog\Models\ServiceZone;
use App\Domain\Catalog\Services\Availability;
use App\Domain\Catalog\Services\ProductPricing;
use App\Domain\Identity\Models\User;
use App\Domain\Pricing\VatCalculator;
use App\Domain\Pricing\VatClass;
use App\Support\Exceptions\DomainRuleViolation;
use App\Support\Money;

/**
 * Prices the signed-in user's cart for an address. The server is the only source of prices:
 * the app never sends an amount. VAT is already inside every price and is only reported, never added.
 */
final class QuoteBuilder
{
    public function __construct(
        private readonly ProductPricing $pricing,
        private readonly VatCalculator $vat,
        private readonly Availability $availability,
        private readonly PromoCodes $promos,
    ) {}

    public function build(User $user, Address $address, ?string $promoCode): QuoteData
    {
        if (! $address->isServiceable()) {
            throw new DomainRuleViolation('address_not_serviceable', 'We do not deliver to this address yet.');
        }

        $items = Cart::forUser($user)->items()->with(['variant.product.seller', 'variant.product.category'])->get();

        if ($items->isEmpty()) {
            throw new DomainRuleViolation('cart_empty', 'Your cart is empty.');
        }

        $zone = ServiceZone::query()->findOrFail($address->service_zone_id);
        $units = $this->availability->forVariants($items->pluck('variant_id')->all(), $zone->id);

        $lines = $items->map(fn (CartItem $item): QuoteLine => $this->line($item, $units[$item->variant_id] ?? 0))->values()->all();
        $issues = $this->issues($lines);

        $subtotal = $this->sum(array_filter($lines, fn (QuoteLine $line): bool => $line->available), fn (QuoteLine $line): Money => $line->lineGross);

        [$lines, $discount, $appliedCode] = $this->applyPromo($user, $lines, $subtotal, $promoCode);

        $payable = $subtotal->minus($discount);
        $deliveryFee = $issues === [] ? $zone->deliveryFeeFor($payable) : Money::zero();
        $total = $payable->plus($deliveryFee);

        return new QuoteData(
            $lines, $subtotal, $discount, $deliveryFee, $total,
            $this->vatBreakdown($lines, $deliveryFee), $appliedCode, $address->id, $zone->id, $issues,
        );
    }

    private function line(CartItem $item, int $availableUnits): QuoteLine
    {
        $variant = $item->variant;
        $product = $variant->product;
        $unit = $this->pricing->unitGross($product);

        $sellable = $variant->is_active
            && $product->status === ProductStatus::Published
            && $product->category->is_active
            && $product->seller->status === SellerStatus::Approved;

        return new QuoteLine(
            variantId: $variant->id,
            productId: $product->id,
            sellerId: $product->seller_id,
            productSlug: $product->slug,
            name: ['ar' => $product->name_ar, 'en' => $product->name_en],
            variantName: $variant->name_ar === null && $variant->name_en === null ? null : ['ar' => $variant->name_ar, 'en' => $variant->name_en],
            imageUrl: $product->image_path === null ? null : asset($product->image_path),
            quantity: $item->quantity,
            unitGross: $unit,
            lineGross: $unit->times($item->quantity),
            discount: Money::zero(),
            vatClass: $product->vat_class,
            vatRateBasisPoints: $this->vat->rateFor($product->vat_class),
            available: $sellable && $availableUnits >= $item->quantity,
            availableUnits: $sellable ? $availableUnits : 0,
        );
    }

    /**
     * @param  list<QuoteLine>  $lines
     * @return list<array{variant_id: int, code: string, available_units: int}>
     */
    private function issues(array $lines): array
    {
        $issues = [];

        foreach ($lines as $line) {
            if ($line->available) {
                continue;
            }

            $issues[] = [
                'variant_id' => $line->variantId,
                'code' => $line->availableUnits === 0 ? 'unavailable' : 'insufficient_stock',
                'available_units' => $line->availableUnits,
            ];
        }

        return $issues;
    }

    /**
     * @param  list<QuoteLine>  $lines
     * @return array{0: list<QuoteLine>, 1: Money, 2: ?string}
     */
    private function applyPromo(User $user, array $lines, Money $subtotal, ?string $promoCode): array
    {
        if ($promoCode === null || trim($promoCode) === '' || $subtotal->isZero()) {
            return [$lines, Money::zero(), null];
        }

        $promo = $this->promos->find($promoCode, $user, $subtotal);
        $discount = $this->promos->discountFor($promo, $subtotal);

        $payableIndexes = array_keys(array_filter($lines, fn (QuoteLine $line): bool => $line->available));
        $shares = $discount->allocate(array_map(fn (int $index): int => $lines[$index]->lineGross->halalas, $payableIndexes));

        foreach ($payableIndexes as $position => $index) {
            $lines[$index] = $lines[$index]->withDiscount($shares[$position]);
        }

        return [$lines, $discount, $promo->code];
    }

    /**
     * VAT reported once per invoice and rate, so rounding never adds up line by line. Delivery carries standard VAT.
     *
     * @param  list<QuoteLine>  $lines
     * @return list<array{vat_class: string, rate_basis_points: int, taxable_net: int, vat: int, gross: int}>
     */
    private function vatBreakdown(array $lines, Money $deliveryFee): array
    {
        $grossByClass = [];

        foreach ($lines as $line) {
            if ($line->available) {
                $grossByClass[$line->vatClass->value] = ($grossByClass[$line->vatClass->value] ?? Money::zero())->plus($line->lineTotal());
            }
        }

        $standard = VatClass::Standard->value;
        $grossByClass[$standard] = ($grossByClass[$standard] ?? Money::zero())->plus($deliveryFee);

        $breakdown = [];

        foreach ([VatClass::Standard, VatClass::Zero, VatClass::Exempt] as $class) {
            $gross = $grossByClass[$class->value] ?? null;

            if ($gross === null || $gross->isZero()) {
                continue;
            }

            $rate = $this->vat->rateFor($class);
            $split = $this->vat->splitGross($gross, $rate);

            $breakdown[] = [
                'vat_class' => $class->value,
                'rate_basis_points' => $rate,
                'taxable_net' => $split->net->halalas,
                'vat' => $split->vat->halalas,
                'gross' => $gross->halalas,
            ];
        }

        return $breakdown;
    }

    /**
     * @param  iterable<QuoteLine>  $lines
     * @param  callable(QuoteLine): Money  $amount
     */
    private function sum(iterable $lines, callable $amount): Money
    {
        $total = Money::zero();

        foreach ($lines as $line) {
            $total = $total->plus($amount($line));
        }

        return $total;
    }
}
