<x-mail::message>
# Appointment reminder

This is a reminder for **{{ $appointment->title }}**.

- **When:** {{ $appointment->starts_at->timezone($appointment->timezone)->format('M j, Y · g:i A') }}
- **Duration:** {{ $appointment->starts_at->diffInMinutes($appointment->ends_at) }} minutes
- **Timezone:** {{ $appointment->timezone }}

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
