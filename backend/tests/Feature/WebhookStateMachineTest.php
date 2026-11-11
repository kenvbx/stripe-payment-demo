<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\WebhookEvent;
use App\Services\StripeWebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookStateMachineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.secret' => null, 'services.stripe.webhook_secret' => 'whsec_state_machine']);
    }

    public function test_unpaid_checkout_waits_for_async_success(): void
    {
        $payment = $this->payment(['stripe_checkout_session_id' => 'cs_async']);
        $this->deliver('evt_unpaid', 'checkout.session.completed', [
            'id' => 'cs_async', 'payment_intent' => 'pi_async', 'payment_status' => 'unpaid',
        ])->assertOk();
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'processing', 'paid_at' => null, 'stripe_payment_intent_id' => 'pi_async']);

        $this->deliver('evt_async_paid', 'checkout.session.async_payment_succeeded', [
            'id' => 'cs_async', 'payment_intent' => 'pi_async',
        ])->assertOk();
        $this->assertSame('succeeded', $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->paid_at);
    }

    public function test_checkout_without_payment_status_is_not_assumed_paid(): void
    {
        $payment = $this->payment(['stripe_checkout_session_id' => 'cs_unknown_status']);
        $this->deliver('evt_unknown_status', 'checkout.session.completed', ['id' => 'cs_unknown_status'])->assertOk();
        $this->assertSame('processing', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->paid_at);
    }

    public function test_late_processing_and_failure_events_cannot_regress_a_paid_payment(): void
    {
        $payment = $this->payment(['stripe_checkout_session_id' => 'cs_paid', 'stripe_payment_intent_id' => 'pi_paid', 'status' => 'succeeded', 'paid_at' => now()->subDay()]);
        $paidAt = $payment->fresh()->paid_at;
        foreach (['payment_intent.processing', 'payment_intent.payment_failed', 'checkout.session.async_payment_failed'] as $index => $type) {
            $this->deliver('evt_stale_'.$index, $type, ['id' => str_starts_with($type, 'checkout.') ? 'cs_paid' : 'pi_paid'])->assertOk();
            $this->assertSame('succeeded', $payment->fresh()->status);
            $this->assertDatabaseHas('webhook_events', ['event_id' => 'evt_stale_'.$index, 'status' => 'ignored']);
        }
        $this->assertTrue($paidAt->equalTo($payment->fresh()->paid_at));
    }

    public function test_refunded_payment_is_terminal_and_preserves_timestamps(): void
    {
        $payment = $this->payment(['stripe_payment_intent_id' => 'pi_refunded', 'status' => 'refunded', 'refund_status' => 'succeeded', 'paid_at' => now()->subDays(2), 'refunded_at' => now()->subDay()]);
        $paidAt = $payment->fresh()->paid_at;
        $refundedAt = $payment->fresh()->refunded_at;
        foreach (['payment_intent.processing', 'payment_intent.payment_failed', 'payment_intent.succeeded'] as $index => $type) {
            $this->deliver('evt_after_refund_'.$index, $type, ['id' => 'pi_refunded'])->assertOk();
            $this->assertSame('refunded', $payment->fresh()->status);
        }
        $this->assertTrue($paidAt->equalTo($payment->fresh()->paid_at));
        $this->assertTrue($refundedAt->equalTo($payment->fresh()->refunded_at));
    }

    public function test_failed_payment_recovers_only_on_confirmed_success(): void
    {
        $payment = $this->payment(['stripe_payment_intent_id' => 'pi_failed', 'status' => 'failed']);
        $this->deliver('evt_stale_processing', 'payment_intent.processing', ['id' => 'pi_failed'])->assertOk();
        $this->assertSame('failed', $payment->fresh()->status);
        $this->deliver('evt_recovered', 'payment_intent.succeeded', ['id' => 'pi_failed'])->assertOk();
        $this->assertSame('succeeded', $payment->fresh()->status);
    }

    public function test_success_from_different_events_does_not_reset_paid_at(): void
    {
        $payment = $this->payment(['stripe_payment_intent_id' => 'pi_success']);
        $this->deliver('evt_success_first', 'payment_intent.succeeded', ['id' => 'pi_success'])->assertOk();
        $paidAt = $payment->fresh()->paid_at;
        $this->travel(1)->hours();
        $this->deliver('evt_success_second', 'payment_intent.succeeded', ['id' => 'pi_success'])->assertOk();
        $this->assertTrue($paidAt->equalTo($payment->fresh()->paid_at));
    }

    public function test_unmatched_verified_event_is_retryable_after_payment_is_persisted(): void
    {
        $this->deliver('evt_before_commit', 'checkout.session.completed', [
            'id' => 'cs_before_commit', 'payment_intent' => 'pi_before_commit', 'payment_status' => 'paid',
        ])->assertStatus(500);
        $this->assertDatabaseHas('webhook_events', ['event_id' => 'evt_before_commit', 'status' => 'failed', 'attempts' => 1]);
        $payment = $this->payment(['stripe_checkout_session_id' => 'cs_before_commit', 'status' => 'failed']);

        $this->postJson('/api/payments/'.$payment->id.'/retry-webhook')
            ->assertOk()->assertJsonPath('data.status', 'succeeded')->assertJsonPath('webhook_event.attempts', 2);
        $this->assertDatabaseHas('webhook_events', ['event_id' => 'evt_before_commit', 'status' => 'processed', 'payment_id' => $payment->id, 'last_error' => null]);
        $this->postJson('/api/payments/'.$payment->id.'/retry-webhook')->assertUnprocessable();
        $this->assertDatabaseHas('webhook_events', ['event_id' => 'evt_before_commit', 'attempts' => 2]);
    }

    public function test_stripe_redelivery_reprocesses_a_failed_event(): void
    {
        $object = ['id' => 'pi_redeliver'];
        $this->deliver('evt_redeliver', 'payment_intent.succeeded', $object)->assertStatus(500);
        $payment = $this->payment(['stripe_payment_intent_id' => 'pi_redeliver']);
        $this->deliver('evt_redeliver', 'payment_intent.succeeded', $object)->assertOk();
        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseHas('webhook_events', ['event_id' => 'evt_redeliver', 'attempts' => 2, 'status' => 'processed']);
        $this->assertSame('succeeded', $payment->fresh()->status);
    }

    public function test_processing_failure_rolls_back_payment_but_retains_retry_audit(): void
    {
        $payment = $this->payment(['stripe_payment_intent_id' => 'pi_rollback']);
        $failOnce = true;
        WebhookEvent::updating(function (WebhookEvent $event) use (&$failOnce): void {
            if ($failOnce && $event->status === 'processed') {
                $failOnce = false;
                throw new \RuntimeException('Simulated event persistence failure');
            }
        });
        try {
            $this->deliver('evt_rollback', 'payment_intent.succeeded', ['id' => 'pi_rollback'])->assertStatus(500);
            $this->assertSame('processing', $payment->fresh()->status);
            $this->assertNull($payment->fresh()->paid_at);
            $this->assertDatabaseHas('webhook_events', ['event_id' => 'evt_rollback', 'status' => 'failed', 'attempts' => 1]);
            $this->postJson('/api/payments/'.$payment->id.'/retry-webhook')
                ->assertOk()->assertJsonPath('data.status', 'succeeded')->assertJsonPath('webhook_event.attempts', 2);
        } finally {
            WebhookEvent::flushEventListeners();
        }
    }

    public function test_manual_retry_also_preserves_refunded_state(): void
    {
        $this->deliver('evt_stale_retry', 'payment_intent.succeeded', ['id' => 'pi_stale_retry'])->assertStatus(500);
        $payment = $this->payment(['stripe_payment_intent_id' => 'pi_stale_retry', 'status' => 'refunded', 'refund_status' => 'succeeded']);
        $this->postJson('/api/payments/'.$payment->id.'/retry-webhook')
            ->assertOk()->assertJsonPath('data.status', 'refunded')->assertJsonPath('webhook_event.status', 'ignored');
    }

    public function test_refund_retry_uses_stored_payment_intent_and_refund_id(): void
    {
        $this->deliver('evt_refund_replay', 'charge.refunded', [
            'id' => 'ch_replay', 'payment_intent' => 'pi_refund_replay', 'refunded' => true,
            'refunds' => ['data' => [['id' => 're_stored']]],
        ])->assertStatus(500);
        $payment = $this->payment(['stripe_payment_intent_id' => 'pi_refund_replay', 'status' => 'succeeded']);
        $this->postJson('/api/payments/'.$payment->id.'/retry-webhook')
            ->assertOk()->assertJsonPath('data.status', 'refunded')->assertJsonPath('data.stripe_refund_id', 're_stored');
    }

    public function test_retry_never_processes_another_payments_event(): void
    {
        $this->deliver('evt_other', 'payment_intent.succeeded', ['id' => 'pi_other'])->assertStatus(500);
        $payment = $this->payment(['stripe_payment_intent_id' => 'pi_this', 'status' => 'failed']);
        $this->postJson('/api/payments/'.$payment->id.'/retry-webhook')->assertUnprocessable();
        $this->assertSame('failed', $payment->fresh()->status);
        $this->assertDatabaseHas('webhook_events', ['event_id' => 'evt_other', 'attempts' => 1, 'status' => 'failed']);
    }

    public function test_unpaid_event_replay_cannot_manufacture_success(): void
    {
        $this->deliver('evt_replay_unpaid', 'checkout.session.completed', [
            'id' => 'cs_replay_unpaid', 'payment_status' => 'unpaid',
        ])->assertStatus(500);
        $payment = $this->payment(['stripe_checkout_session_id' => 'cs_replay_unpaid']);
        $this->postJson('/api/payments/'.$payment->id.'/retry-webhook')
            ->assertOk()->assertJsonPath('data.status', 'processing')->assertJsonPath('data.paid_at', null);
    }

    public function test_partial_refund_does_not_mark_the_full_payment_refunded(): void
    {
        $payment = $this->payment(['stripe_payment_intent_id' => 'pi_partial', 'status' => 'succeeded']);
        $this->deliver('evt_partial', 'refund.updated', [
            'id' => 're_partial', 'payment_intent' => 'pi_partial', 'status' => 'succeeded', 'amount' => 100,
        ])->assertOk();
        $this->assertSame('succeeded', $payment->fresh()->status);
        $this->deliver('evt_full', 'refund.updated', [
            'id' => 're_full', 'payment_intent' => 'pi_partial', 'status' => 'succeeded', 'amount' => 8900,
        ])->assertOk();
        $this->assertSame('refunded', $payment->fresh()->status);
    }

    public function test_retry_processing_failure_is_reported_without_changing_payment(): void
    {
        $payment = $this->payment(['status' => 'failed']);
        $this->mock(StripeWebhookProcessor::class, function ($mock): void {
            $mock->shouldReceive('retryForPayment')->once()->andThrow(new \RuntimeException('Database unavailable'));
        });
        $this->postJson('/api/payments/'.$payment->id.'/retry-webhook')->assertStatus(503);
        $this->assertSame('failed', $payment->fresh()->status);
    }

    public function test_unsupported_event_is_acknowledged_and_ignored(): void
    {
        $this->deliver('evt_unsupported', 'customer.created', ['id' => 'cus_unknown'])->assertOk();
        $this->assertDatabaseHas('webhook_events', ['event_id' => 'evt_unsupported', 'status' => 'ignored']);
    }

    private function payment(array $overrides = []): Payment
    {
        return Payment::create(array_merge([
            'customer_name' => 'Webhook demo', 'customer_email' => 'webhook@example.com',
            'product_name' => 'Pro workspace', 'amount' => 8900, 'currency' => 'usd',
            'status' => 'processing', 'webhook_status' => 'pending', 'idempotency_key' => uniqid('webhook-'),
        ], $overrides));
    }

    private function deliver(string $id, string $type, array $object)
    {
        $payload = json_encode(['id' => $id, 'type' => $type, 'data' => ['object' => $object]], JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_state_machine');
        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature,
        ], $payload);
    }
}
