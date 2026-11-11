<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    protected $fillable = [
        'stripe_checkout_session_id', 'stripe_payment_intent_id', 'stripe_event_id',
        'checkout_url', 'stripe_refund_id', 'refund_status', 'refunded_at', 'customer_name', 'customer_email', 'product_name', 'amount',
        'currency', 'status', 'webhook_status', 'idempotency_key', 'last_error', 'paid_at',
    ];

    protected $casts = ['amount' => 'integer', 'paid_at' => 'datetime', 'refunded_at' => 'datetime'];

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(WebhookEvent::class);
    }
}
