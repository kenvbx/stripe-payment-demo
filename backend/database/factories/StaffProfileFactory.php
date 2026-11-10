<?php

namespace Database\Factories;

use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StaffProfile> */
class StaffProfileFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory()->create(['role' => 'staff'])->id, 'display_name' => fake()->name(), 'phone' => fake()->phoneNumber(), 'color' => fake()->randomElement(['blue', 'purple', 'orange']), 'is_active' => true];
    }
}
