<?php

namespace App;

enum AppointmentStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case Rescheduled = 'rescheduled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => in_array($next, [self::Confirmed, self::Cancelled], true),
            self::Confirmed => in_array($next, [self::Completed, self::Cancelled, self::Rescheduled], true),
            self::Rescheduled => in_array($next, [self::Pending, self::Confirmed, self::Cancelled], true),
            self::Completed, self::Cancelled => false,
        };
    }
}
