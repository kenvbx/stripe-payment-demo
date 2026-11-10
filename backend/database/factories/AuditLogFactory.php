<?php

namespace Database\Factories;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AuditLog> */
class AuditLogFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => null, 'action' => 'appointment.created', 'metadata' => []];
    }
}
