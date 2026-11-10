<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\AppointmentStatusHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AppointmentStatusHistory> */
class AppointmentStatusHistoryFactory extends Factory
{
    public function definition(): array
    {
        return ['appointment_id' => Appointment::factory(), 'from_status' => null, 'to_status' => 'pending', 'changed_by' => null, 'reason' => null];
    }
}
