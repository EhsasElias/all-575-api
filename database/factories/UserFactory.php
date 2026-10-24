<?php

namespace Database\Factories;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => '+9665'.fake()->unique()->numerify('########'),
            'email' => null,
            'phone_verified_at' => now(),
            'email_verified_at' => null,
            'last_login_at' => null,
            'locale' => 'ar',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
