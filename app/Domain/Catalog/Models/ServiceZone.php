<?php

namespace App\Domain\Catalog\Models;

use App\Support\Money;
use Database\Factories\ServiceZoneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(ServiceZoneFactory::class)]
#[Fillable(['name_ar', 'name_en', 'center_lat', 'center_lng', 'radius_meters', 'delivery_fee_halalas', 'free_delivery_threshold_halalas', 'is_active'])]
class ServiceZone extends Model
{
    /** @use HasFactory<ServiceZoneFactory> */
    use HasFactory;

    private const EARTH_RADIUS_METERS = 6_371_000;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'center_lat' => 'float',
            'center_lng' => 'float',
            'is_active' => 'boolean',
        ];
    }

    /** @param  Builder<static>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** The nearest active zone whose circle contains the point, or null when we do not deliver there. */
    public static function resolve(float $lat, float $lng): ?self
    {
        return self::query()->active()->get()
            ->filter(fn (self $zone): bool => $zone->distanceTo($lat, $lng) <= $zone->radius_meters)
            ->sortBy(fn (self $zone): float => $zone->distanceTo($lat, $lng))
            ->first();
    }

    /** Great-circle (haversine) distance in metres. */
    public function distanceTo(float $lat, float $lng): float
    {
        $lat1 = deg2rad($this->center_lat);
        $lat2 = deg2rad($lat);
        $deltaLat = $lat2 - $lat1;
        $deltaLng = deg2rad($lng - $this->center_lng);

        $a = sin($deltaLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($deltaLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_METERS * asin(min(1, sqrt($a)));
    }

    public function deliveryFeeFor(Money $subtotal): Money
    {
        $threshold = $this->free_delivery_threshold_halalas;

        if ($threshold !== null && $subtotal->halalas >= $threshold) {
            return Money::zero();
        }

        return Money::halalas($this->delivery_fee_halalas);
    }
}
