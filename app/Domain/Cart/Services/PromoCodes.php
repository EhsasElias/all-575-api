<?php

declare(strict_types=1);

namespace App\Domain\Cart\Services;

use App\Domain\Cart\Enums\PromoType;
use App\Domain\Cart\Models\PromoCode;
use App\Domain\Identity\Models\User;
use App\Support\Exceptions\DomainRuleViolation;
use App\Support\Money;

final class PromoCodes
{
    /** @throws DomainRuleViolation when the code cannot be used on this order */
    public function find(string $code, User $user, Money $subtotal): PromoCode
    {
        $promo = PromoCode::query()->where('code', strtoupper(trim($code)))->first();

        if ($promo === null || ! $promo->is_active) {
            throw new DomainRuleViolation('promo_invalid', 'This promo code is not valid.');
        }

        if ($promo->starts_at?->isFuture()) {
            throw new DomainRuleViolation('promo_not_started', 'This promo code is not active yet.');
        }

        if ($promo->ends_at?->isPast()) {
            throw new DomainRuleViolation('promo_expired', 'This promo code has expired.');
        }

        if ($subtotal->halalas < $promo->min_subtotal_halalas) {
            throw new DomainRuleViolation('promo_min_subtotal', 'Your order is below the minimum for this promo code.', 422, [
                'min_subtotal' => $promo->min_subtotal_halalas,
            ]);
        }

        if ($promo->max_redemptions !== null && $promo->redemptions()->count() >= $promo->max_redemptions) {
            throw new DomainRuleViolation('promo_used_up', 'This promo code has been fully used.');
        }

        if ($promo->redemptions()->where('user_id', $user->id)->count() >= $promo->per_user_limit) {
            throw new DomainRuleViolation('promo_already_used', 'You have already used this promo code.');
        }

        return $promo;
    }

    /** The discount is taken from the goods only, never from delivery, and never exceeds the goods total. */
    public function discountFor(PromoCode $promo, Money $subtotal): Money
    {
        $discount = match ($promo->type) {
            PromoType::Percent => $subtotal->basisPoints($promo->value),
            PromoType::Fixed => Money::halalas($promo->value),
        };

        if ($promo->max_discount_halalas !== null) {
            $discount = $discount->min(Money::halalas($promo->max_discount_halalas));
        }

        return $discount->min($subtotal);
    }
}
