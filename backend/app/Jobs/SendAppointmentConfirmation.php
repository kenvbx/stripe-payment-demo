<?php

namespace App\Jobs;

use App\Mail\AppointmentConfirmationMail;
use App\Models\Appointment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendAppointmentConfirmation implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public int $appointmentId) {}

    public function handle(): void
    {
        $appointment = Appointment::query()->with(['customer', 'service', 'staff'])->find($this->appointmentId);

        if (! $appointment || $appointment->status === 'cancelled' || blank($appointment->customer?->email)) {
            return;
        }

        Mail::to($appointment->customer->email)->send(new AppointmentConfirmationMail($appointment));
    }
}
