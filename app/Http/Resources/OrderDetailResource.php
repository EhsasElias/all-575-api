<?php

namespace App\Http\Resources;

use App\Domain\Orders\Enums\OrderStatus;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderEvent;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Models\Suborder;
use App\Domain\Payments\Models\Refund;
use Illuminate\Http\Request;

/**
 * Full order for its owner. Expects suborders.seller, suborders.items, items, events, payments and refunds loaded.
 *
 * @mixin Order
 */
class OrderDetailResource extends OrderResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'address' => [
                'recipient_name' => $this->recipient_name, 'recipient_phone' => $this->recipient_phone,
                'region' => $this->region, 'city' => $this->city, 'district' => $this->district, 'street' => $this->street,
                'building_number' => $this->building_number, 'additional_number' => $this->additional_number,
                'postal_code' => $this->postal_code, 'short_address' => $this->short_address,
                'unit' => $this->unit, 'floor' => $this->floor, 'delivery_notes' => $this->delivery_notes,
                'lat' => $this->lat, 'lng' => $this->lng,
            ],
            'amounts' => [
                'subtotal' => $this->subtotal_halalas,
                'discount' => $this->discount_halalas,
                'delivery_fee' => $this->delivery_fee_halalas,
                'total' => $this->total_halalas,
                'vat_included' => $this->vat_halalas,
                'vat' => $this->vat_breakdown,
            ],
            'promo_code' => $this->promo_code,
            'customer_notes' => $this->customer_notes,
            'cancelled_at' => $this->cancelled_at,
            'cancel_reason' => $this->cancel_reason,
            'can_cancel' => $this->customerMayCancel(),
            'suborders' => $this->suborders->map(fn (Suborder $suborder): array => $this->suborder($suborder))->all(),
            'timeline' => $this->events->map(fn (OrderEvent $event): array => [
                'type' => $event->type, 'at' => $event->created_at, 'suborder_id' => $event->suborder_id, 'note' => $event->note,
            ])->all(),
            'payments' => $this->payments->map(fn ($payment): array => (new PaymentResource($payment))->resolve($request))->all(),
            // A refund is "succeeded" only once the provider says so, never when it is merely requested.
            'refunds' => $this->refunds->map(fn (Refund $refund): array => [
                'id' => $refund->id, 'status' => $refund->status->value, 'amount' => $refund->amount_halalas,
                'reason' => $refund->reason, 'credit_note_number' => $refund->credit_note_number,
                'requested_at' => $refund->requested_at, 'completed_at' => $refund->completed_at,
            ])->all(),
        ];
    }

    private function customerMayCancel(): bool
    {
        return match ($this->status) {
            OrderStatus::PendingPayment => true,
            OrderStatus::Confirmed => $this->suborders->every(fn (Suborder $s): bool => $s->status->customerMayCancel()),
            default => false,
        };
    }

    /** @return array<string, mixed> */
    private function suborder(Suborder $suborder): array
    {
        return [
            'id' => $suborder->id,
            'status' => $suborder->status->value,
            'seller' => new SellerResource($suborder->seller),
            'eta' => ['at' => $suborder->eta_at, 'reason' => $suborder->eta_reason],
            'items' => $suborder->items->map(fn (OrderItem $item): array => [
                'product_slug' => $item->product_slug,
                'name' => ['ar' => $item->name_ar, 'en' => $item->name_en],
                'variant_name' => $item->variant_name_ar === null && $item->variant_name_en === null ? null : ['ar' => $item->variant_name_ar, 'en' => $item->variant_name_en],
                'image_url' => $item->image_path === null ? null : asset($item->image_path),
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price_halalas,
                'line_total' => $item->line_total_halalas,
            ])->all(),
        ];
    }
}
