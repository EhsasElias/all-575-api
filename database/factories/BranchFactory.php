<?php

namespace Database\Factories;

use App\Domain\Catalog\Models\Branch;
use App\Domain\Catalog\Models\Seller;
use App\Domain\Catalog\Models\ServiceZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Branch> */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'seller_id' => Seller::factory(),
            'service_zone_id' => ServiceZone::factory(),
            'code' => strtoupper(fake()->unique()->bothify('BR-####')),
            'name_ar' => 'فرع',
            'name_en' => 'Branch',
            'lat' => 24.7136,
            'lng' => 46.6753,
            'is_active' => true,
        ];
    }
}
