<?php

namespace App\Domain\Addressing\Models;

use App\Domain\Catalog\Models\ServiceZone;
use App\Domain\Identity\Models\User;
use Database\Factories\AddressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UseFactory(AddressFactory::class)]
#[Fillable([
    'user_id', 'label', 'recipient_name', 'recipient_phone', 'region', 'city', 'district', 'street',
    'building_number', 'additional_number', 'postal_code', 'short_address', 'unit', 'floor', 'delivery_notes',
    'lat', 'lng', 'service_zone_id', 'is_default',
])]
class Address extends Model
{
    /** @use HasFactory<AddressFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['lat' => 'float', 'lng' => 'float', 'is_default' => 'boolean'];
    }

    public function isServiceable(): bool
    {
        return $this->service_zone_id !== null;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ServiceZone, $this> */
    public function serviceZone(): BelongsTo
    {
        return $this->belongsTo(ServiceZone::class);
    }
}
