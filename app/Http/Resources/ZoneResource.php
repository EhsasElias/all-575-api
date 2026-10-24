<?php

namespace App\Http\Resources;

use App\Domain\Catalog\Models\ServiceZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ServiceZone */
class ZoneResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => ['ar' => $this->name_ar, 'en' => $this->name_en],
            'delivery_fee' => $this->delivery_fee_halalas,
            'free_delivery_threshold' => $this->free_delivery_threshold_halalas,
            'currency' => config('store.currency'),
        ];
    }
}
