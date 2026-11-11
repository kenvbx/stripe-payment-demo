<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Product;
use App\Services\StripeCheckoutGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\Checkout\Session;
use Tests\TestCase;

class CheckoutRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private array $payload;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.secret' => 'sk_test_fixture', 'services.stripe.success_url' => 'https://example.test/success', 'services.stripe.cancel_url' => 'https://example.test/cancel']);
        $this->product = Product::create(['name' => 'Original product', 'slug' => 'checkout-fixture', 'description' => 'Recovery fixture', 'price_cents' => 2900, 'currency' => 'usd', 'is_active' => true]);
        $this->payload = ['product_id' => $this->product->id, 'customer_name' => 'Customer', 'customer_email' => 'customer@example.test', 'idempotency_key' => 'checkout-recovery-fixture'];
    }

    private function stripeSession(): Session
    {
        return Session::constructFrom(['object' => 'checkout.session', 'id' => 'cs_recovery', 'url' => 'https://checkout.stripe.com/test', 'payment_intent' => null, 'expires_at' => time() + 3600]);
    }

    public function test_completed_checkout_replays_without_another_stripe_call_even_after_product_deletion(): void
    {
        $this->mock(StripeCheckoutGateway::class)->shouldReceive('create')->once()->andReturn($this->stripeSession());
        $first = $this->postJson('/api/payments/checkout', $this->payload)->assertCreated();
        foreach (['checkout_parameters', 'checkout_request_hash', 'checkout_claim_token', 'checkout_claim_expires_at'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $first->json('data'));
        }
        $this->product->delete();
        $this->postJson('/api/payments/checkout', $this->payload)->assertOk()->assertJsonPath('data.id', $first->json('data.id'))->assertJsonPath('idempotent_replay', true);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_timeout_preserves_payment_and_immutable_stripe_parameters_on_retry(): void
    {
        $snapshots = [];
        $this->mock(StripeCheckoutGateway::class)->shouldReceive('create')->twice()->andReturnUsing(function (Payment $payment) use (&$snapshots) {
            $snapshots[] = [$payment->id, $payment->idempotency_key, $payment->checkout_parameters];
            $this->assertSame(1, \Illuminate\Support\Facades\DB::transactionLevel());
            if (count($snapshots) === 1) throw new \RuntimeException('Remote timeout with sensitive details');
            return $this->stripeSession();
        });
        $response = $this->postJson('/api/payments/checkout', $this->payload)->assertStatus(503)->assertJsonPath('retryable', true)->assertHeader('Retry-After', '5');
        $this->assertStringNotContainsString('sensitive', $response->getContent());
        $this->assertDatabaseCount('payments', 1);
        $this->product->update(['name' => 'Changed', 'price_cents' => 9000, 'is_active' => false]);
        config(['services.stripe.success_url' => 'https://changed.test']);
        $this->postJson('/api/payments/checkout', $this->payload)->assertOk()->assertJsonPath('data.amount', 2900);
        $this->assertSame($snapshots[0], $snapshots[1]);
        $this->assertNull(Payment::first()->checkout_claim_token);
    }

    public function test_local_save_failure_after_stripe_success_can_recover_same_payment(): void
    {
        $failOnce = true;
        $dispatcher = clone Payment::getEventDispatcher();
        Payment::updating(function (Payment $payment) use (&$failOnce) {
            if ($payment->checkout_state === 'complete' && $failOnce) { $failOnce = false; throw new \RuntimeException('Simulated persistence failure'); }
        });
        $this->mock(StripeCheckoutGateway::class)->shouldReceive('create')->twice()->andReturn($this->stripeSession());
        try {
            $this->postJson('/api/payments/checkout', $this->payload)->assertStatus(503);
            $id = Payment::first()->id;
            $this->postJson('/api/payments/checkout', $this->payload)->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.stripe_checkout_session_id', 'cs_recovery');
            $this->assertDatabaseCount('payments', 1);
        } finally { Payment::setEventDispatcher($dispatcher); }
    }

    public function test_conflicting_customer_product_or_mode_cannot_reuse_key(): void
    {
        $this->mock(StripeCheckoutGateway::class)->shouldReceive('create')->once()->andReturn($this->stripeSession());
        $this->postJson('/api/payments/checkout', $this->payload)->assertCreated();
        foreach ([['customer_email' => 'other@example.test'], ['customer_name' => 'Other'], ['product_id' => 999]] as $change) {
            $this->postJson('/api/payments/checkout', array_replace($this->payload, $change))->assertConflict()->assertJsonPath('error_code', 'IDEMPOTENCY_CONFLICT');
        }
        config(['services.stripe.secret' => null]);
        $this->postJson('/api/payments/checkout', $this->payload)->assertConflict();
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_active_lease_blocks_duplicates_and_expired_lease_recovers(): void
    {
        $this->mock(StripeCheckoutGateway::class)->shouldReceive('create')->once()->andThrow(new \RuntimeException('Timeout'));
        $this->postJson('/api/payments/checkout', $this->payload)->assertStatus(503);
        $payment = Payment::first();
        $payment->update(['checkout_claim_token' => (string) \Illuminate\Support\Str::uuid(), 'checkout_claim_expires_at' => now()->addMinute()]);
        $this->postJson('/api/payments/checkout', $this->payload)->assertConflict()->assertJsonPath('error_code', 'CHECKOUT_IN_PROGRESS')->assertJsonPath('retryable', true);
        $payment->update(['checkout_claim_expires_at' => now()->subSecond()]);
        $this->mock(StripeCheckoutGateway::class)->shouldReceive('create')->once()->andReturn($this->stripeSession());
        $this->postJson('/api/payments/checkout', $this->payload)->assertOk();
    }

    public function test_retry_window_and_expired_sessions_never_create_another_session(): void
    {
        $this->mock(StripeCheckoutGateway::class)->shouldReceive('create')->once()->andThrow(new \RuntimeException('Timeout'));
        $this->postJson('/api/payments/checkout', $this->payload)->assertStatus(503);
        $payment = Payment::first();
        $payment->created_at = now()->subHours(23); $payment->save();
        $this->postJson('/api/payments/checkout', $this->payload)->assertConflict()->assertJsonPath('error_code', 'CHECKOUT_RETRY_EXPIRED');
        $payment->update(['checkout_state' => 'complete', 'checkout_expires_at' => now()->subSecond()]);
        $this->postJson('/api/payments/checkout', $this->payload)->assertConflict()->assertJsonPath('error_code', 'CHECKOUT_EXPIRED');
    }

    public function test_checkout_finalization_preserves_a_terminal_webhook_status(): void
    {
        $this->mock(StripeCheckoutGateway::class)->shouldReceive('create')->once()->andReturnUsing(function (Payment $payment) {
            $payment->update(['status' => 'succeeded', 'stripe_payment_intent_id' => 'pi_webhook']);
            return $this->stripeSession();
        });
        $this->postJson('/api/payments/checkout', $this->payload)->assertCreated()->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.stripe_payment_intent_id', 'pi_webhook')->assertJsonPath('checkout_url', null);
    }

    public function test_invalid_product_and_live_key_do_not_create_payments(): void
    {
        $this->mock(StripeCheckoutGateway::class)->shouldNotReceive('create');
        $this->postJson('/api/payments/checkout', array_replace($this->payload, ['product_id' => 999]))->assertUnprocessable();
        config(['services.stripe.secret' => 'sk_live_blocked']);
        $this->postJson('/api/payments/checkout', $this->payload)->assertUnprocessable()->assertJsonPath('error_code', 'STRIPE_TEST_MODE_REQUIRED');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_sdk_sends_persisted_parameters_and_stable_stripe_idempotency_header(): void
    {
        $http = \Mockery::mock(\Stripe\HttpClient\ClientInterface::class);
        $http->shouldReceive('request')->once()->withArgs(function ($method, $url, $headers, $params) {
            $this->assertSame('post', $method);
            $this->assertSame('https://api.stripe.com/v1/checkout/sessions', $url);
            $this->assertContains('Idempotency-Key: checkout-'.$this->payload['idempotency_key'], $headers);
            $this->assertSame(2900, $params['line_items'][0]['price_data']['unit_amount']);
            $this->assertSame((string) Payment::first()->id, $params['metadata']['payment_id']);
            return true;
        })->andReturn([json_encode($this->stripeSession()->toArray()), 200, []]);
        $previous = \Stripe\ApiRequestor::httpClient();
        config(['services.stripe.secret' => null]);
        $this->postJson('/api/payments/checkout', $this->payload)->assertCreated();
        config(['services.stripe.secret' => 'sk_test_fixture']);
        $session = (new StripeCheckoutGateway($http))->create(Payment::first());
        $this->assertSame('cs_recovery', $session->id);
        $this->assertSame($previous, \Stripe\ApiRequestor::httpClient());
    }
}
