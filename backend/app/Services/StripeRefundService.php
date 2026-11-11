<?php

namespace App\Services;

use App\Models\Payment;
use Stripe\Refund;
use Stripe\StripeClient;

class StripeRefundService
{
    public function testModeEnabled(): bool
    {
        $key = (string) config('services.stripe.secret');
        return str_starts_with($key, 'sk_test_') || str_starts_with($key, 'rk_test_');
    }

    public function createRefund(Payment $payment): Refund
    {
        return (new StripeClient((string) config('services.stripe.secret')))->refunds->create([
            'payment_intent' => $payment->stripe_payment_intent_id,
            'reason' => 'requested_by_customer',
        ], ['idempotency_key' => 'refund-payment-'.$payment->id]);
    }
}
