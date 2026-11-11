<?php

namespace Tests\Feature;

use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentApiTest extends TestCase
{
    use RefreshDatabase;
    private string $webhookSecret = 'whsec_test_secret';

    protected function setUp(): void { parent::setUp(); config(['services.stripe.webhook_secret' => $this->webhookSecret]); }

    public function test_checkout_is_idempotent(): void
    {
        $payload = ['product_name' => 'Pro workspace', 'amount' => 8900, 'customer_name' => 'Demo Customer', 'customer_email' => 'demo@example.com', 'idempotency_key' => 'checkout-test-1'];
        $first = $this->postJson('/api/payments/checkout', $payload)->assertCreated();
        $second = $this->postJson('/api/payments/checkout', $payload)->assertOk()->assertJsonPath('idempotent_replay', true);
        $this->assertSame($first->json('data.id'), $second->json('data.id')); $this->assertDatabaseCount('payments', 1);
    }

    public function test_checkout_return_status_is_scoped_to_a_known_session(): void
    {
        config(['services.stripe.secret' => null]);
        $payment = Payment::create($this->paymentAttributes([
            'stripe_checkout_session_id' => 'cs_return_test',
            'status' => 'processing',
            'webhook_status' => 'pending',
        ]));

        $this->getJson('/api/checkout/sessions/cs_return_test')
            ->assertOk()
            ->assertJsonPath('data.payment_id', $payment->id)
            ->assertJsonPath('data.confirmation_pending', true)
            ->assertJsonPath('data.product_name', 'Pro workspace');
        $this->getJson('/api/checkout/sessions/cs_unknown')->assertNotFound();
    }

    public function test_checkout_completed_webhook_marks_payment_succeeded(): void
    {
        $payment = Payment::create($this->paymentAttributes(['stripe_checkout_session_id' => 'cs_test_123', 'status' => 'processing']));
        $this->signedWebhook('evt_checkout_completed', 'checkout.session.completed', ['id' => 'cs_test_123', 'payment_intent' => 'pi_test_123', 'object' => 'checkout.session'])->assertOk();
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'succeeded', 'webhook_status' => 'processed', 'stripe_event_id' => 'evt_checkout_completed', 'stripe_payment_intent_id' => 'pi_test_123']);
        $this->assertDatabaseHas('webhook_events', ['payment_id' => $payment->id, 'event_id' => 'evt_checkout_completed', 'type' => 'checkout.session.completed', 'status' => 'processed', 'attempts' => 1]);
    }

    public function test_payment_failed_webhook_marks_payment_failed(): void
    {
        $payment = Payment::create($this->paymentAttributes(['stripe_payment_intent_id' => 'pi_failed_123', 'status' => 'processing']));
        $this->signedWebhook('evt_payment_failed', 'payment_intent.payment_failed', ['id' => 'pi_failed_123', 'object' => 'payment_intent'])->assertOk();
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'failed', 'webhook_status' => 'processed']);
    }

    public function test_webhook_event_log_lists_verified_events(): void
    {
        Payment::create($this->paymentAttributes(['stripe_checkout_session_id' => 'cs_logged_123', 'status' => 'processing']));
        $this->signedWebhook('evt_logged', 'checkout.session.completed', ['id' => 'cs_logged_123', 'payment_intent' => 'pi_logged', 'object' => 'checkout.session'])->assertOk();

        $this->getJson('/api/webhook-events')
            ->assertOk()
            ->assertJsonPath('data.0.event_id', 'evt_logged')
            ->assertJsonPath('data.0.type', 'checkout.session.completed')
            ->assertJsonPath('data.0.attempts', 1)
            ->assertJsonPath('data.0.payment.customer_name', 'Demo customer');
    }

    public function test_invalid_webhook_signature_is_rejected(): void
    {
        $response = $this->call('POST', '/api/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't=123,v1=invalid'], json_encode(['id' => 'evt_invalid', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_invalid']]]));
        $response->assertStatus(400);
        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_duplicate_webhook_event_is_ignored(): void
    {
        $payment = Payment::create($this->paymentAttributes(['stripe_checkout_session_id' => 'cs_duplicate', 'status' => 'processing']));
        $event = ['id' => 'evt_duplicate', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_duplicate', 'payment_intent' => 'pi_duplicate', 'object' => 'checkout.session']]];
        $this->postSignedPayload($event)->assertOk(); $this->postSignedPayload($event)->assertOk();
        $this->assertDatabaseCount('payments', 1); $this->assertDatabaseHas('payments', ['id' => $payment->id, 'stripe_event_id' => 'evt_duplicate', 'status' => 'succeeded']);
        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseHas('webhook_events', ['event_id' => 'evt_duplicate', 'attempts' => 2, 'status' => 'processed']);
    }

    public function test_failed_webhook_can_be_retried(): void
    {
        $payment = Payment::create($this->paymentAttributes(['stripe_payment_intent_id' => 'pi_demo_test', 'status' => 'failed', 'webhook_status' => 'retrying', 'idempotency_key' => 'retry-test']));
        $this->postJson('/api/payments/'.$payment->id.'/retry-webhook')->assertOk()->assertJsonPath('data.webhook_status', 'processed')->assertJsonPath('data.status', 'succeeded');
    }

    private function signedWebhook(string $id, string $type, array $object) { return $this->postSignedPayload(['id' => $id, 'type' => $type, 'data' => ['object' => $object]]); }
    private function postSignedPayload(array $event)
    {
        $payload = json_encode($event, JSON_UNESCAPED_SLASHES); $timestamp = time();
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $this->webhookSecret);
        return $this->call('POST', '/api/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $payload);
    }
    private function paymentAttributes(array $overrides = []): array
    {
        return array_merge(['customer_name' => 'Demo customer', 'customer_email' => 'demo@example.com', 'product_name' => 'Pro workspace', 'amount' => 8900, 'currency' => 'usd', 'status' => 'created', 'webhook_status' => 'pending', 'idempotency_key' => 'key-'.uniqid()], $overrides);
    }
}
