<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\StaffProfile;
use App\Models\User;

class AppointmentPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'staff'], true);
    }

    public function view(User $user, Appointment $appointment): bool
    {
        return $this->canAccessAppointment($user, $appointment);
    }

    public function create(User $user, Appointment $appointment): bool
    {
        return $user->role === 'admin' || $this->belongsToStaffProfile($user, $appointment);
    }

    public function update(User $user, Appointment $appointment): bool
    {
        return $this->canAccessAppointment($user, $appointment);
    }

    public function reassign(User $user, Appointment $appointment, StaffProfile $staff): bool
    {
        return $user->role === 'admin' || $user->staffProfile?->id === $staff->id;
    }

    public function delete(User $user, Appointment $appointment): bool
    {
        return $this->canAccessAppointment($user, $appointment);
    }

    private function canAccessAppointment(User $user, Appointment $appointment): bool
    {
        return $user->role === 'admin' || $this->belongsToStaffProfile($user, $appointment);
    }

    private function belongsToStaffProfile(User $user, Appointment $appointment): bool
    {
        return $user->role === 'staff' && $user->staffProfile?->id === $appointment->staff_profile_id;
    }
}
