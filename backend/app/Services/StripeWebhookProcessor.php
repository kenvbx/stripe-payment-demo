<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Stripe\Event;

class StripeWebhookProcessor
{
    private const SUPPORTED_EVENTS = [
        'checkout.session.completed', 'checkout.session.async_payment_succeeded',
        'checkout.session.async_payment_failed', 'payment_intent.succeeded',
        'payment_intent.payment_failed', 'payment_intent.processing',
        'charge.refunded', 'refund.updated',
    ];

    public function recordAndProcess(Event $event, string $rawPayload): WebhookEvent
    {
        $object = $event->data->object;
        // Persist only the verified fields needed to replay a state transition.
        $eventData = ['object' => [
            'id' => (string) ($object->id ?? ''),
            'object' => (string) ($object->object ?? ''),
            'payment_intent' => is_string($object->payment_intent ?? null) ? $object->payment_intent : null,
            'status' => (string) ($object->status ?? ''),
            'payment_status' => (string) ($object->payment_status ?? ''),
            'amount' => $object->amount ?? null,
            'refunded' => (bool) ($object->refunded ?? false),
            'refund_id' => $object->refunds->data[0]->id ?? null,
        ]];

        $record = WebhookEvent::query()->firstOrCreate(['event_id' => $event->id], [
            'type' => $event->type,
            'stripe_object_id' => $eventData['object']['id'],
            'status' => 'received',
            'attempts' => 0,
            'payload_hash' => hash('sha256', $rawPayload),
            'event_data' => $eventData,
            'received_at' => now(),
        ]);

        return $this->process($record);
    }

    public function retryForPayment(Payment $payment): ?WebhookEvent
    {
        $identifiers = array_values(array_filter([
            $payment->stripe_checkout_session_id, $payment->stripe_payment_intent_id,
        ]));
        $record = WebhookEvent::query()
            ->whereIn('status', ['received', 'failed'])
            ->where(function (Builder $query) use ($payment, $identifiers): void {
                $query->where('payment_id', $payment->id);
                $query->orWhere(function (Builder $unmatched) use ($payment, $identifiers): void {
                    $unmatched->whereNull('payment_id')->where(function (Builder $identity) use ($payment, $identifiers): void {
                        $identity->whereIn('stripe_object_id', $identifiers);
                        if ($payment->stripe_payment_intent_id) {
                            $identity->orWhere('event_data->object->payment_intent', $payment->stripe_payment_intent_id);
                        }
                    });
                });
            })
            ->oldest('id')->first();

        return $record ? $this->process($record) : null;
    }

    private function process(WebhookEvent $event): WebhookEvent
    {
        // Commit the attempt separately so a processing rollback retains the audit trail.
        DB::transaction(function () use ($event): void {
            $record = WebhookEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $record->increment('attempts');
        });

        try {
            return DB::transaction(function () use ($event): WebhookEvent {
                $record = WebhookEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
                if (in_array($record->status, ['processed', 'ignored'], true)) return $record;
                if (!in_array($record->type, self::SUPPORTED_EVENTS, true)) {
                    $record->update(['status' => 'ignored', 'processed_at' => now(), 'last_error' => null]);
                    return $record->fresh();
                }

                $object = $record->event_data['object'];
                $objectId = $object['id'] ?? '';
                $paymentIntentId = $object['payment_intent'] ?? null;
                if (!$objectId) throw new RuntimeException('The stored event has no Stripe object ID.');
                $payment = Payment::query()
                    ->where('stripe_checkout_session_id', $objectId)
                    ->orWhere('stripe_payment_intent_id', $objectId)
                    ->when($paymentIntentId, fn (Builder $query) => $query->orWhere('stripe_payment_intent_id', $paymentIntentId))
                    ->lockForUpdate()->first();
                // Stripe may deliver an event before checkout persistence has committed.
                if (!$payment) throw new RuntimeException('No matching payment exists yet. Retry after checkout has been persisted.');

                $target = match ($record->type) {
                    'checkout.session.completed' => in_array($object['payment_status'] ?? '', ['paid', 'no_payment_required'], true) ? 'succeeded' : 'processing',
                    'payment_intent.succeeded', 'checkout.session.async_payment_succeeded' => 'succeeded',
                    'checkout.session.async_payment_failed', 'payment_intent.payment_failed' => 'failed',
                    'payment_intent.processing' => 'processing',
                    'charge.refunded' => ($object['refunded'] ?? false) ? 'refunded' : null,
                    'refund.updated' => ($object['status'] ?? '') === 'succeeded' && ($object['amount'] ?? 0) >= $payment->amount ? 'refunded' : null,
                };
                if (!$target || !$this->canTransition($payment->status, $target)) {
                    $record->update(['payment_id' => $payment->id, 'status' => 'ignored', 'processed_at' => now(), 'last_error' => null]);
                    return $record->fresh();
                }

                $payment->update([
                    'stripe_event_id' => $record->event_id,
                    'stripe_payment_intent_id' => $paymentIntentId ?: $payment->stripe_payment_intent_id,
                    'webhook_status' => 'processed',
                    'status' => $target,
                    'paid_at' => $target === 'succeeded' ? ($payment->paid_at ?? now()) : $payment->paid_at,
                    'stripe_refund_id' => $target === 'refunded' ? ($record->type === 'refund.updated' ? $objectId : ($object['refund_id'] ?? $payment->stripe_refund_id)) : $payment->stripe_refund_id,
                    'refund_status' => $target === 'refunded' ? 'succeeded' : $payment->refund_status,
                    'refunded_at' => $target === 'refunded' ? ($payment->refunded_at ?? now()) : $payment->refunded_at,
                    'last_error' => null,
                ]);
                $record->update(['payment_id' => $payment->id, 'status' => 'processed', 'processed_at' => now(), 'last_error' => null]);
                return $record->fresh();
            });
        } catch (\Throwable $exception) {
            // A concurrent successful attempt must not be overwritten by a failure.
            WebhookEvent::query()->whereKey($event->id)->whereNotIn('status', ['processed', 'ignored'])->update([
                'status' => 'failed', 'last_error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);
            throw new RuntimeException('Stripe webhook processing failed.', previous: $exception);
        }
    }

    private function canTransition(string $current, string $target): bool
    {
        return in_array($target, match ($current) {
            'created', 'processing' => ['processing', 'failed', 'succeeded', 'refunded'],
            'failed' => ['failed', 'succeeded', 'refunded'],
            'succeeded' => ['succeeded', 'refunded'],
            'refunded' => ['refunded'],
            default => [],
        }, true);
    }
}
