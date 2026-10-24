<?php

namespace Database\Factories;

use App\Domain\Catalog\Models\ServiceZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ServiceZone> */
class ServiceZoneFactory extends Factory
{
    protected $model = ServiceZone::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name_ar' => 'الرياض',
            'name_en' => 'Riyadh',
            'center_lat' => 24.7136,
            'center_lng' => 46.6753,
            'radius_meters' => 30_000,
            'delivery_fee_halalas' => 1000,
            'free_delivery_threshold_halalas' => 5000,
            'is_active' => true,
        ];
    }
}
