<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Stripe\Webhook;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    public function handle(Request $request): Response
    {
        try {
            $event = Webhook::constructEvent($request->getContent(), (string) $request->header('Stripe-Signature'), (string) config('services.stripe.webhook_secret'));
        } catch (UnexpectedValueException|\Stripe\Exception\SignatureVerificationException) {
            return response('Invalid webhook signature.', 400);
        }

        DB::transaction(function () use ($event): void {
            $object = $event->data->object;
            $payment = Payment::query()
                ->where('stripe_checkout_session_id', $object->id)
                ->orWhere('stripe_payment_intent_id', $object->id)
                ->lockForUpdate()
                ->first();

            if (!$payment || $payment->stripe_event_id === $event->id) return;

            $status = match ($event->type) {
                'checkout.session.completed', 'payment_intent.succeeded' => 'succeeded',
                'checkout.session.async_payment_succeeded' => 'succeeded',
                'checkout.session.async_payment_failed', 'payment_intent.payment_failed' => 'failed',
                'payment_intent.processing' => 'processing',
                default => $payment->status,
            };

            $payment->update([
                'stripe_event_id' => $event->id,
                'stripe_payment_intent_id' => is_string($object->payment_intent ?? null) ? $object->payment_intent : $payment->stripe_payment_intent_id,
                'webhook_status' => 'processed',
                'status' => $status,
                'paid_at' => $status === 'succeeded' ? now() : $payment->paid_at,
            ]);
        });

        return response('', 200);
    }
}
