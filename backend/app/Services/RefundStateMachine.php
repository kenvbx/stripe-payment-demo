<?php

namespace App\Services;

use App\Models\Payment;

class RefundStateMachine
{
    private const STATES = ['pending', 'requires_action', 'succeeded', 'failed', 'canceled'];
    private const TERMINAL = ['succeeded', 'failed', 'canceled'];

    /** Apply to a freshly locked payment; the caller owns the database transaction. */
    public function apply(Payment $payment, ?string $refundId, string $status, bool $fullRefund, bool $aggregate = false): bool
    {
        if (!$fullRefund || !in_array($status, self::STATES, true)) return false;
        // A confirmed full refund is terminal, including when a delayed API response arrives.
        if ($payment->status === 'refunded' || $payment->refund_status === 'succeeded') return false;

        $differentRefund = $payment->stripe_refund_id && $refundId && $payment->stripe_refund_id !== $refundId;
        // Another refund's pending/failure notification cannot replace the tracked attempt.
        // Full success (or an aggregate charge confirmation) is authoritative for the payment.
        if ($differentRefund && $status !== 'succeeded') return false;
        if (!$aggregate && !$differentRefund && in_array($payment->refund_status, self::TERMINAL, true)) return false;

        $payment->fill([
            'stripe_refund_id' => $refundId ?: $payment->stripe_refund_id,
            'refund_status' => $status,
        ]);
        if ($status === 'succeeded') {
            $payment->fill(['status' => 'refunded', 'refunded_at' => $payment->refunded_at ?? now()]);
        }
        if (!$payment->isDirty()) return false;
        $payment->save();
        return true;
    }
}
