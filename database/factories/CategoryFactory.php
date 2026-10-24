<?php

namespace Database\Factories;

use App\Domain\Catalog\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Category> */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $slug = fake()->unique()->slug(2);

        return [
            'parent_id' => null,
            'slug' => $slug,
            'name_ar' => 'قسم '.$slug,
            'name_en' => 'Category '.$slug,
            'image_path' => null,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
