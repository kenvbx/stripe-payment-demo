<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Payment extends Model {
    protected $fillable = ['stripe_payment_intent_id','stripe_event_id','customer_name','customer_email','product_name','amount','currency','status','webhook_status','idempotency_key','last_error','paid_at'];
    protected $casts = ['amount' => 'integer', 'paid_at' => 'datetime'];
}
