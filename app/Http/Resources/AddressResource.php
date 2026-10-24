<?php

namespace App\Http\Resources;

use App\Domain\Addressing\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Address */
class AddressResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'recipient_name' => $this->recipient_name,
            'recipient_phone' => $this->recipient_phone,
            'region' => $this->region,
            'city' => $this->city,
            'district' => $this->district,
            'street' => $this->street,
            'building_number' => $this->building_number,
            'additional_number' => $this->additional_number,
            'postal_code' => $this->postal_code,
            'short_address' => $this->short_address,
            'unit' => $this->unit,
            'floor' => $this->floor,
            'delivery_notes' => $this->delivery_notes,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'is_default' => $this->is_default,
            'serviceable' => $this->isServiceable(),
            'service_zone_id' => $this->service_zone_id,
        ];
    }
}
