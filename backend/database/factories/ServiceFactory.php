<?php

namespace Database\Factories;

use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Service> */
class ServiceFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->randomElement(['Brand strategy session', 'Website consultation', 'Product discovery call']), 'description' => fake()->sentence(), 'duration_minutes' => 45, 'price' => fake()->randomFloat(2, 50, 250), 'is_active' => true];
    }
}
