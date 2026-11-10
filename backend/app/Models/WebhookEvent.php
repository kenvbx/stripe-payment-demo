<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookEvent extends Model
{
    protected $fillable = [
        'payment_id', 'event_id', 'type', 'stripe_object_id', 'status', 'attempts',
        'payload_hash', 'event_data', 'last_error', 'received_at', 'processed_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'event_data' => 'array',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
