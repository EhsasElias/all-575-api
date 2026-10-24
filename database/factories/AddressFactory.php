<?php

namespace Database\Factories;

use App\Domain\Addressing\Models\Address;
use App\Domain\Catalog\Models\ServiceZone;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Address> */
class AddressFactory extends Factory
{
    protected $model = Address::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => 'Home',
            'recipient_name' => fake()->name(),
            'recipient_phone' => '+966512345678',
            'region' => 'Riyadh Region',
            'city' => 'Riyadh',
            'district' => 'Al Olaya',
            'street' => 'King Fahd Road',
            'building_number' => '8228',
            'additional_number' => '2121',
            'postal_code' => '12611',
            'short_address' => 'RRRD2929',
            'unit' => null,
            'floor' => null,
            'delivery_notes' => null,
            'lat' => 24.7136,
            'lng' => 46.6753,
            'service_zone_id' => ServiceZone::factory(),
            'is_default' => false,
        ];
    }

    public function outsideService(): static
    {
        return $this->state(['lat' => 21.5433, 'lng' => 39.1728, 'service_zone_id' => null]);
    }
}
