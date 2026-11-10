<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Service;
use App\Models\StaffProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Appointment> */
class AppointmentFactory extends Factory
{
    public function definition(): array
    {
        $startsAt = fake()->dateTimeBetween('now', '+14 days');

        return ['customer_id' => Customer::factory(), 'service_id' => Service::factory(), 'staff_profile_id' => StaffProfile::factory(), 'title' => fake()->sentence(3), 'starts_at' => $startsAt, 'ends_at' => (clone $startsAt)->modify('+45 minutes'), 'status' => 'confirmed', 'timezone' => 'Asia/Ho_Chi_Minh'];
    }
}
