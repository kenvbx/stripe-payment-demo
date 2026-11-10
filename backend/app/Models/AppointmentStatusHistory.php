<?php

namespace App\Models;

use Database\Factories\AppointmentStatusHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsesFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UsesFactory(AppointmentStatusHistoryFactory::class)]
#[Fillable(['appointment_id', 'from_status', 'to_status', 'changed_by', 'reason'])]
class AppointmentStatusHistory extends Model
{
    use HasFactory;

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
