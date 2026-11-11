<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Product;
use App\Services\StripeCheckoutGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Stripe\Checkout\Session;
use Tests\TestCase;

class CheckoutExpiryTest extends TestCase
{
    use RefreshDatabase;

    private array $payload;
    private $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.secret' => 'sk_test_expiry_fixture']);
        $product = Product::create(['name' => 'Expiry product', 'slug' => 'expiry-product', 'description' => 'Expiry fixture', 'price_cents' => 2900, 'currency' => 'usd', 'is_active' => true]);
        $this->payload = ['product_id' => $product->id, 'customer_name' => 'Customer', 'customer_email' => 'expiry@example.test', 'idempotency_key' => 'checkout-expiry-fixture'];
        $this->gateway = $this->mock(StripeCheckoutGateway::class);
    }

    private function expiredPayment(): Payment
    {
        $this->gateway->shouldReceive('create')->once()->andReturn($this->stripeSession());
        $this->postJson('/api/payments/checkout', $this->payload)->assertCreated();
        $payment = Payment::sole();
        $payment->update(['checkout_expires_at' => now()->subMinute()]);
        return $payment;
    }

    private function stripeSession(array $overrides = []): Session
    {
        return Session::constructFrom(array_merge([
            'object' => 'checkout.session', 'id' => 'cs_expiry', 'url' => 'https://checkout.stripe.com/fixture',
            'status' => 'open', 'payment_status' => 'unpaid', 'expires_at' => time() + 3600,
        ], $overrides));
    }

    public function test_verified_expired_unpaid_checkout_allows_an_explicit_new_attempt(): void
    {
        $payment = $this->expiredPayment();
        $before = $payment->fresh()->getAttributes();
        $this->gateway->shouldReceive('retrieve')->once()->with('cs_expiry')->andReturnUsing(function () {
            $this->assertSame(1, DB::transactionLevel()); // Only the feature-test outer transaction.
            return $this->stripeSession(['status' => 'expired']);
        });
        $this->postJson('/api/payments/checkout', $this->payload)->assertConflict()
            ->assertJsonPath('error_code', 'CHECKOUT_EXPIRED')->assertJsonPath('restart_allowed', true)
            ->assertJsonPath('retryable', false)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($before, $payment->fresh()->getAttributes());
        $this->gateway->shouldReceive('create')->once()->andReturn($this->stripeSession(['id' => 'cs_restarted']));
        $new = $this->postJson('/api/payments/checkout', array_replace($this->payload, ['idempotency_key' => 'checkout-restarted-fixture']))
            ->assertCreated()->assertJsonPath('data.stripe_checkout_session_id', 'cs_restarted');
        $this->assertNotSame($payment->id, $new->json('data.id'));
        $this->assertDatabaseCount('payments', 2);
        $this->assertSame($before, $payment->fresh()->getAttributes());
    }

    public function test_recovering_an_already_expired_stripe_response_never_redirects_to_the_dead_session(): void
    {
        $expired = $this->stripeSession(['expires_at' => time() - 60, 'status' => 'expired']);
        $this->gateway->shouldReceive('create')->once()->andReturn($expired);
        $this->gateway->shouldReceive('retrieve')->once()->with('cs_expiry')->andReturn($expired);
        $this->postJson('/api/payments/checkout', $this->payload)->assertConflict()
            ->assertJsonPath('error_code', 'CHECKOUT_EXPIRED')->assertJsonPath('restart_allowed', true)
            ->assertJsonMissingPath('checkout_url');
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame('complete', Payment::sole()->checkout_state);
        $this->assertSame('cs_expiry', Payment::sole()->stripe_checkout_session_id);
    }

    public static function terminalPayments(): array
    {
        return [['succeeded'], ['refunded']];
    }

    #[DataProvider('terminalPayments')]
    public function test_paid_payments_replay_after_expiry_without_lookup_or_redirect(string $status): void
    {
        $payment = $this->expiredPayment();
        $payment->update(['status' => $status]);
        $this->gateway->shouldNotReceive('retrieve');
        $this->postJson('/api/payments/checkout', $this->payload)->assertOk()
            ->assertJsonPath('data.id', $payment->id)->assertJsonPath('data.status', $status)
            ->assertJsonPath('checkout_url', null)->assertJsonPath('idempotent_replay', true);
        $this->assertDatabaseCount('payments', 1);
    }

    public static function unconfirmedSessions(): array
    {
        return [
            'async payment completed' => ['complete', 'unpaid', 'CHECKOUT_AWAITING_CONFIRMATION', 409],
            'paid before webhook' => ['complete', 'paid', 'CHECKOUT_AWAITING_CONFIRMATION', 409],
            'no payment required' => ['complete', 'no_payment_required', 'CHECKOUT_AWAITING_CONFIRMATION', 409],
            'still open' => ['open', 'unpaid', 'CHECKOUT_STATUS_UNAVAILABLE', 503],
            'missing payment status' => ['expired', null, 'CHECKOUT_STATUS_UNAVAILABLE', 503],
            'unknown remote state' => [null, null, 'CHECKOUT_STATUS_UNAVAILABLE', 503],
        ];
    }

    #[DataProvider('unconfirmedSessions')]
    public function test_local_expiry_cannot_authorize_restart_without_remote_confirmation(?string $status, ?string $paymentStatus, string $code, int $httpStatus): void
    {
        $payment = $this->expiredPayment();
        $this->gateway->shouldReceive('retrieve')->once()->andReturn($this->stripeSession(['status' => $status, 'payment_status' => $paymentStatus]));
        $this->postJson('/api/payments/checkout', $this->payload)->assertStatus($httpStatus)
            ->assertJsonPath('error_code', $code)->assertJsonPath('restart_allowed', false)->assertJsonPath('retryable', true);
        $this->assertSame('processing', $payment->fresh()->status);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_lookup_timeout_retains_original_attempt_and_hides_remote_details(): void
    {
        $payment = $this->expiredPayment();
        $before = $payment->fresh()->getAttributes();
        $this->gateway->shouldReceive('retrieve')->once()->andThrow(new \RuntimeException('Sensitive remote details'));
        $response = $this->postJson('/api/payments/checkout', $this->payload)->assertStatus(503)
            ->assertJsonPath('error_code', 'CHECKOUT_STATUS_UNAVAILABLE')->assertJsonPath('restart_allowed', false);
        $this->assertStringNotContainsString('Sensitive', $response->getContent());
        $this->assertSame($before, $payment->fresh()->getAttributes());
    }

    public function test_webhook_success_during_lookup_prevents_restart(): void
    {
        $payment = $this->expiredPayment();
        $this->gateway->shouldReceive('retrieve')->once()->andReturnUsing(function () use ($payment) {
            $payment->update(['status' => 'succeeded', 'paid_at' => now()]);
            return $this->stripeSession(['status' => 'expired']);
        });
        $this->postJson('/api/payments/checkout', $this->payload)->assertOk()
            ->assertJsonPath('data.status', 'succeeded')->assertJsonPath('checkout_url', null);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_gateway_retrieves_the_exact_session_with_bounded_sdk_transport(): void
    {
        $http = \Mockery::mock(\Stripe\HttpClient\ClientInterface::class);
        $http->shouldReceive('request')->once()->withArgs(function ($method, $url, $headers, $params) {
            $this->assertSame('get', $method);
            $this->assertSame('https://api.stripe.com/v1/checkout/sessions/cs_expiry', $url);
            $this->assertEmpty($params);
            return true;
        })->andReturn([json_encode($this->stripeSession(['status' => 'expired'])->toArray()), 200, []]);
        $previous = \Stripe\ApiRequestor::httpClient();
        $session = (new StripeCheckoutGateway($http))->retrieve('cs_expiry');
        $this->assertSame('expired', $session->status);
        $this->assertSame($previous, \Stripe\ApiRequestor::httpClient());
    }
}
