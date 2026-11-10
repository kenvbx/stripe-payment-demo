<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Stripe\Event;

class StripeWebhookProcessor
{
    public function recordAndProcess(Event $event, string $rawPayload): WebhookEvent
    {
        $stripeObject = $event->data->object;
        $objectId = (string) ($stripeObject->id ?? '');
        $eventData = ['object' => [
            'id' => $objectId,
            'object' => (string) ($stripeObject->object ?? ''),
            'payment_intent' => is_string($stripeObject->payment_intent ?? null) ? $stripeObject->payment_intent : null,
        ]];

        $webhookEvent = DB::transaction(function () use ($event, $objectId, $eventData, $rawPayload): WebhookEvent {
            $record = WebhookEvent::query()->firstOrCreate(['event_id' => $event->id], [
                'type' => $event->type,
                'stripe_object_id' => $objectId,
                'status' => 'received',
                'attempts' => 0,
                'payload_hash' => hash('sha256', $rawPayload),
                'event_data' => $eventData,
                'received_at' => now(),
            ]);
            $record = WebhookEvent::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $record->increment('attempts');
            $record->update(['received_at' => now()]);
            return $record->fresh();
        });

        if (in_array($webhookEvent->status, ['processed', 'ignored'], true)) return $webhookEvent;

        try {
            return DB::transaction(function () use ($webhookEvent, $event): WebhookEvent {
                $record = WebhookEvent::query()->whereKey($webhookEvent->id)->lockForUpdate()->firstOrFail();
                if (in_array($record->status, ['processed', 'ignored'], true)) return $record;
                $object = $event->data->object;
                $paymentIntentId = is_string($object->payment_intent ?? null) ? $object->payment_intent : null;
                $payment = Payment::query()
                    ->where('stripe_checkout_session_id', $object->id)
                    ->orWhere('stripe_payment_intent_id', $object->id)
                    ->when($paymentIntentId, fn ($query) => $query->orWhere('stripe_payment_intent_id', $paymentIntentId))
                    ->lockForUpdate()->first();
                $status = match ($event->type) {
                    'checkout.session.completed', 'payment_intent.succeeded', 'checkout.session.async_payment_succeeded' => 'succeeded',
                    'checkout.session.async_payment_failed', 'payment_intent.payment_failed' => 'failed',
                    'payment_intent.processing' => 'processing',
                    default => null,
                };
                if (!$payment || !$status) {
                    $record->update(['payment_id' => $payment?->id, 'status' => 'ignored', 'processed_at' => now(), 'last_error' => null]);
                    return $record->fresh();
                }
                $payment->update([
                    'stripe_event_id' => $event->id,
                    'stripe_payment_intent_id' => $paymentIntentId ?: $payment->stripe_payment_intent_id,
                    'webhook_status' => 'processed',
                    'status' => $status,
                    'paid_at' => $status === 'succeeded' ? now() : $payment->paid_at,
                ]);
                $record->update(['payment_id' => $payment->id, 'status' => 'processed', 'processed_at' => now(), 'last_error' => null]);
                return $record->fresh();
            });
        } catch (\Throwable $exception) {
            WebhookEvent::query()->whereKey($webhookEvent->id)->update([
                'status' => 'failed', 'last_error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);
            throw new RuntimeException('Stripe webhook processing failed.', previous: $exception);
        }
    }
}
