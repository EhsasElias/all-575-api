<?php

namespace App\Http\Resources;

use App\Domain\Orders\Models\DeliverySlot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DeliverySlot */
class SlotResource extends JsonResource
{
    /**
     * @return array{id: int, starts_at: string, ends_at: string, remaining: int, available: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'remaining' => $this->remaining(),
            'available' => $this->remaining() > 0,
        ];
    }
}
