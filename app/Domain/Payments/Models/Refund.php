<?php

namespace App\Domain\Payments\Models;

use App\Domain\Orders\Models\Order;
use App\Domain\Payments\Enums\RefundStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'payment_id', 'status', 'amount_halalas', 'reason', 'provider_reference', 'credit_note_number', 'requested_at', 'completed_at'])]
class Refund extends Model
{
    use HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => RefundStatus::class, 'requested_at' => 'datetime', 'completed_at' => 'datetime'];
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

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
