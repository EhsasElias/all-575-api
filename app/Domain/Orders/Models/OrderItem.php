<?php

namespace App\Domain\Orders\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'order_id', 'suborder_id', 'variant_id', 'product_id', 'product_slug', 'name_ar', 'name_en', 'variant_name_ar', 'variant_name_en',
    'image_path', 'quantity', 'unit_price_halalas', 'line_price_halalas', 'discount_halalas', 'line_total_halalas', 'vat_class', 'vat_rate_basis_points',
])]
class OrderItem extends Model
{
    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
