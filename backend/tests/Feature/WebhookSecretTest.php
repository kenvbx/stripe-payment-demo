<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Services\StripeWebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WebhookSecretTest extends TestCase
{
    use RefreshDatabase;

    public static function missingSecrets(): array
    {
        return [
            'unset' => [null, ''],
            'empty' => ['', ''],
            'spaces' => ['   ', '   '],
            'whitespace' => ["\t\n", "\t\n"],
            'boolean' => [false, ''],
            'invalid configuration type' => [[], ''],
        ];
    }

    #[DataProvider('missingSecrets')]
    public function test_missing_secret_rejects_forgeable_signature_without_processing(mixed $secret, string $signingKey): void
    {
        config(['services.stripe.webhook_secret' => $secret]);
        $payment = $this->payment();
        $before = $payment->fresh()->getAttributes();
        $this->mock(StripeWebhookProcessor::class)->shouldNotReceive('recordAndProcess');

        $response = $this->deliver($signingKey)->assertStatus(503);
        $response->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringNotContainsString('whsec_', $response->getContent());
        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertSame($before, $payment->fresh()->getAttributes());
    }

    public function test_empty_key_signature_cannot_bypass_a_configured_secret(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_secret_fixture']);
        $payment = $this->payment();
        $this->deliver('')->assertStatus(400);
        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertSame('processing', $payment->fresh()->status);
    }

    public function test_valid_configured_signature_still_processes_the_event(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_secret_fixture']);
        $payment = $this->payment();
        $this->deliver('whsec_secret_fixture')->assertOk();
        $this->assertSame('succeeded', $payment->fresh()->status);
        $this->assertDatabaseHas('webhook_events', ['event_id' => 'evt_secret_guard_fixture', 'status' => 'processed', 'payment_id' => $payment->id]);
    }

    private function payment(): Payment
    {
        return Payment::create([
            'customer_name' => 'Webhook fixture', 'customer_email' => 'fixture@example.test',
            'product_name' => 'Test product', 'amount' => 2900, 'currency' => 'usd',
            'status' => 'processing', 'webhook_status' => 'pending',
            'stripe_payment_intent_id' => 'pi_secret_guard_fixture', 'idempotency_key' => 'secret-guard-fixture',
        ]);
    }

    private function deliver(string $secret)
    {
        $payload = json_encode([
            'id' => 'evt_secret_guard_fixture', 'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_secret_guard_fixture', 'object' => 'payment_intent']],
        ], JSON_THROW_ON_ERROR);
        $timestamp = time();
        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret),
        ], $payload);
    }
}
