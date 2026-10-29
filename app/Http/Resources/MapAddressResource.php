<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MapAddressResource extends JsonResource
{
    /**
     * Suggested map details, not a verified Saudi National Address.
     *
     * @return array{country_code: ?string, region: ?string, city: ?string, district: ?string, street: ?string, building_number: ?string, postal_code: ?string}
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
