<?php

namespace App\Domain\Cart\Models;

use App\Domain\Cart\Enums\PromoType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'type', 'value', 'min_subtotal_halalas', 'max_discount_halalas', 'starts_at', 'ends_at', 'max_redemptions', 'per_user_limit', 'is_active'])]
class PromoCode extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => PromoType::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<PromoRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(PromoRedemption::class);
    }
}
