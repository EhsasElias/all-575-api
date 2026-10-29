<?php

namespace App\Domain\Orders\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Orders\Enums\OrderStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'billing_details', 'purchase_order_reference', 'number', 'user_id', 'status', 'idempotency_key', 'quote_id',
    'recipient_name', 'recipient_phone', 'region', 'city', 'district', 'street', 'building_number', 'additional_number',
    'postal_code', 'short_address', 'unit', 'floor', 'delivery_notes', 'lat', 'lng', 'service_zone_id',
    'delivery_slot_id', 'slot_starts_at', 'slot_ends_at',
    'currency', 'subtotal_halalas', 'discount_halalas', 'delivery_fee_halalas', 'total_halalas', 'vat_halalas', 'vat_breakdown',
    'promo_code', 'customer_notes', 'placed_at', 'paid_at', 'cancelled_at', 'cancel_reason',
])]
class Order extends Model
{
    use HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'billing_details' => 'array',
            'vat_breakdown' => 'array',
            'lat' => 'float',
            'lng' => 'float',
            'slot_starts_at' => 'datetime',
            'slot_ends_at' => 'datetime',
            'placed_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Suborder, $this> */
    public function suborders(): HasMany
    {
        return $this->hasMany(Suborder::class)->orderBy('created_at')->orderBy('id');
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    /** @return HasMany<OrderEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class)->orderBy('id');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('created_at');
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->orderBy('requested_at');
    }

    /** @return HasMany<StockReservation, $this> */
    public function reservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    /** @param  array<string, mixed>  $meta */
    public function record(string $type, string $actor, ?string $note = null, array $meta = [], ?Suborder $suborder = null): OrderEvent
    {
        return $this->events()->create([
            'suborder_id' => $suborder?->id,
            'type' => $type,
            'actor' => $actor,
            'note' => $note,
            'meta' => $meta === [] ? null : $meta,
        ]);
    }
}
