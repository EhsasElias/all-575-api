<?php

namespace App\Http\Resources;

use App\Domain\Orders\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Order summary for lists. Expects `items` and `payments` loaded.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * @return array{id: string, number: string, status: 'pending_payment'|'confirmed'|'cancelled'|'completed', placed_at: string, paid_at: ?string, total: int, currency: string, items_count: int, preview_images: list<string>, slot: array{starts_at: ?string, ends_at: ?string}, payment_status: ?string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'placed_at' => $this->placed_at,
            'paid_at' => $this->paid_at,
            'total' => $this->total_halalas,
            'currency' => $this->currency,
            'items_count' => (int) $this->items->sum('quantity'),
            'preview_images' => $this->items->pluck('image_path')->filter()->unique()->take(3)->map(fn (string $path): string => asset($path))->values()->all(),
            'slot' => ['starts_at' => $this->slot_starts_at, 'ends_at' => $this->slot_ends_at],
            'payment_status' => $this->payments->last()?->status,
        ];
    }
}
