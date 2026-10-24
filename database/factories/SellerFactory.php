<?php

namespace Database\Factories;

use App\Domain\Catalog\Enums\SellerStatus;
use App\Domain\Catalog\Models\Seller;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Seller> */
class SellerFactory extends Factory
{
    protected $model = Seller::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $slug = fake()->unique()->slug(2);

        return [
            'slug' => $slug,
            'name_ar' => 'متجر '.$slug,
            'name_en' => 'Store '.$slug,
            'logo_path' => null,
            'status' => SellerStatus::Approved,
        ];
    }

    public function suspended(): static
    {
        return $this->state(['status' => SellerStatus::Suspended]);
    }
}
