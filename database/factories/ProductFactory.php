<?php

namespace Database\Factories;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Seller;
use App\Domain\Pricing\VatClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $slug = fake()->unique()->slug(3);

        return [
            'seller_id' => Seller::factory(),
            'category_id' => Category::factory(),
            'slug' => $slug,
            'name_ar' => 'منتج '.$slug,
            'name_en' => 'Product '.$slug,
            'description_ar' => null,
            'description_en' => null,
            'image_path' => null,
            'vat_class' => VatClass::Standard,
            'status' => ProductStatus::Published,
            'is_featured' => false,
            'rating_average' => 0,
            'rating_count' => 0,
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => ProductStatus::Draft]);
    }
}
