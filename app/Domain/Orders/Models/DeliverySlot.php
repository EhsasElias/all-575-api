<?php

namespace App\Domain\Orders\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['service_zone_id', 'starts_at', 'ends_at', 'capacity', 'booked'])]
class DeliverySlot extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function remaining(): int
    {
        return max(0, $this->capacity - $this->booked);
    }
}
