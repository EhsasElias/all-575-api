<?php

namespace Database\Factories;

use App\Domain\Catalog\Models\Branch;
use App\Domain\Catalog\Models\BranchStock;
use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BranchStock> */
class BranchStockFactory extends Factory
{
    protected $model = BranchStock::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'variant_id' => ProductVariant::factory(),
            'on_hand' => 100,
            'reserved' => 0,
        ];
    }
}
