<?php

namespace App\Domain\Cart\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['promo_code_id', 'user_id', 'order_id', 'discount_halalas'])]
class PromoRedemption extends Model
{
    public const UPDATED_AT = null;
}
