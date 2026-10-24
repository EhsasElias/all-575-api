<?php

namespace App\Domain\Payments\Models;

use App\Domain\Orders\Models\Order;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'provider', 'method', 'status', 'amount_halalas', 'currency', 'provider_reference', 'failure_code', 'paid_at', 'expires_at'])]
class Payment extends Model
{
    use HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function amount(): Money
    {
        return Money::halalas($this->amount_halalas);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
