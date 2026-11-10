<x-mail::message>
# Appointment confirmed

Your appointment **{{ $appointment->title }}** is confirmed.

- **When:** {{ $appointment->starts_at->timezone($appointment->timezone)->format('M j, Y · g:i A') }}
- **Duration:** {{ $appointment->starts_at->diffInMinutes($appointment->ends_at) }} minutes
- **Timezone:** {{ $appointment->timezone }}
@if ($appointment->staff)
- **With:** {{ $appointment->staff->display_name }}
@endif

@if ($appointment->notes)
{{ $appointment->notes }}
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
