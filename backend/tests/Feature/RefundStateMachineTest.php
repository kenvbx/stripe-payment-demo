<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use App\Services\StripeRefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Stripe\Refund;
use Tests\TestCase;

class RefundStateMachineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::forceCreate(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'test-password-long', 'role' => 'admin']), ['admin']);
        config(['services.stripe.secret' => 'sk_test_refund_fixture', 'services.stripe.webhook_secret' => 'whsec_refund_fixture']);
    }

    public function test_webhook_success_wins_over_delayed_pending_api_response(): void
    {
        $payment = $this->payment();
        $paidAt = $payment->fresh()->paid_at;
        $confirmedAt = null;
        $this->mock(StripeRefundService::class, function ($mock) use ($payment, &$confirmedAt): void {
            $mock->shouldReceive('testModeEnabled')->once()->andReturn(true);
            $mock->shouldReceive('createRefund')->once()->andReturnUsing(function () use ($payment, &$confirmedAt) {
                // RefreshDatabase owns the outer transaction; the controller must not add one around Stripe.
                $this->assertSame(1, DB::transactionLevel());
                $this->deliver('evt_success', 'succeeded')->assertOk();
                $confirmedAt = $payment->fresh()->refunded_at;
                $this->travel(1)->minutes();
                return Refund::constructFrom(['id' => 're_state', 'status' => 'pending']);
            });
        });
        $this->postJson('/api/payments/'.$payment->id.'/refund')->assertCreated()
            ->assertJsonPath('data.status', 'refunded')->assertJsonPath('data.refund_status', 'succeeded')
            ->assertJsonPath('refund_status', 'succeeded');
        $this->assertTrue($paidAt->equalTo($payment->fresh()->paid_at));
        $this->assertTrue($confirmedAt->equalTo($payment->fresh()->refunded_at));
    }

    public function test_pending_requires_action_and_failed_leave_payment_paid(): void
    {
        $payment = $this->payment();
        foreach (['pending', 'requires_action', 'failed'] as $status) {
            $this->deliver('evt_'.$status, $status)->assertOk();
            $this->assertSame($status, $payment->fresh()->refund_status);
            $this->assertSame('succeeded', $payment->fresh()->status);
            $this->assertNull($payment->fresh()->refunded_at);
        }
    }

    public static function terminalTransitions(): array
    {
        $cases = [];
        foreach (['succeeded', 'failed', 'canceled'] as $terminal) {
            foreach (['pending', 'requires_action', 'succeeded', 'failed', 'canceled'] as $incoming) {
                $cases[$terminal.' then '.$incoming] = [$terminal, $incoming];
            }
        }
        return $cases;
    }

    #[DataProvider('terminalTransitions')]
    public function test_same_refund_terminal_state_does_not_regress(string $terminal, string $incoming): void
    {
        $payment = $this->payment();
        $this->deliver('evt_terminal', $terminal)->assertOk();
        $this->assertSame($terminal, $payment->fresh()->refund_status);
        $this->assertSame($terminal === 'succeeded' ? 'refunded' : 'succeeded', $payment->fresh()->status);
        $before = $payment->fresh()->getAttributes();
        $this->travel(1)->minutes();
        $this->deliver('evt_late', $incoming)->assertOk();
        $this->assertSame($before, $payment->fresh()->getAttributes());
        $this->assertDatabaseHas('webhook_events', ['event_id' => 'evt_late', 'status' => 'ignored']);
    }

    public static function replayStates(): array
    {
        return array_map(fn ($state) => [$state], ['pending', 'requires_action', 'failed', 'canceled', 'succeeded']);
    }

    #[DataProvider('replayStates')]
    public function test_stored_attempt_replays_without_calling_stripe(string $status): void
    {
        $payment = $this->payment([
            'status' => $status === 'succeeded' ? 'refunded' : 'succeeded',
            'stripe_refund_id' => 're_state', 'refund_status' => $status,
            'refunded_at' => $status === 'succeeded' ? now()->subHour() : null,
        ]);
        $before = $payment->fresh()->getAttributes();
        $this->mock(StripeRefundService::class, function ($mock): void {
            $mock->shouldReceive('testModeEnabled')->twice()->andReturn(true);
            $mock->shouldNotReceive('createRefund');
        });
        $this->postJson('/api/payments/'.$payment->id.'/refund')->assertOk()
            ->assertJsonPath('idempotent_replay', true)->assertJsonPath('refund_status', $status);
        $this->getJson('/api/payments/'.$payment->id)->assertOk()->assertJsonPath('data.refund_available', false);
        $this->assertSame($before, $payment->fresh()->getAttributes());
    }

    public function test_unrelated_or_partial_events_cannot_replace_tracked_refund(): void
    {
        $payment = $this->payment(['stripe_refund_id' => 're_state', 'refund_status' => 'pending']);
        $this->deliver('evt_unrelated', 'failed', ['id' => 're_other'])->assertOk();
        $this->deliver('evt_partial', 'succeeded', ['id' => 're_other', 'amount' => 100])->assertOk();
        $this->assertSame('pending', $payment->fresh()->refund_status);
        $this->assertSame('re_state', $payment->fresh()->stripe_refund_id);
        $this->assertSame('succeeded', $payment->fresh()->status);
    }

    public function test_another_full_success_can_resolve_an_earlier_failed_attempt(): void
    {
        $payment = $this->payment(['stripe_refund_id' => 're_old', 'refund_status' => 'failed']);
        $this->deliver('evt_new_success', 'succeeded')->assertOk();
        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertSame('re_state', $payment->fresh()->stripe_refund_id);
    }

    public function test_charge_full_refund_confirmation_is_authoritative(): void
    {
        $payment = $this->payment(['stripe_refund_id' => 're_state', 'refund_status' => 'failed']);
        $this->signed('evt_charge', 'charge.refunded', [
            'id' => 'ch_state', 'payment_intent' => 'pi_refund_state', 'refunded' => true,
            'refunds' => ['data' => [['id' => 're_state']]],
        ])->assertOk();
        $this->deliver('evt_late_failure', 'failed')->assertOk();
        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertSame('succeeded', $payment->fresh()->refund_status);
    }

    public function test_failed_refund_event_can_be_retried_after_payment_is_persisted(): void
    {
        $this->deliver('evt_early', 'failed')->assertStatus(500);
        $payment = $this->payment(['stripe_refund_id' => 're_state', 'refund_status' => 'pending']);
        $this->postJson('/api/payments/'.$payment->id.'/retry-webhook')->assertOk()
            ->assertJsonPath('data.refund_status', 'failed')->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('webhook_event.attempts', 2);
    }

    private function payment(array $overrides = []): Payment
    {
        return Payment::create(array_merge([
            'customer_name' => 'Refund fixture', 'customer_email' => 'refund@example.test',
            'product_name' => 'Pro workspace', 'amount' => 8900, 'currency' => 'usd',
            'status' => 'succeeded', 'webhook_status' => 'processed', 'paid_at' => now()->subDay(),
            'stripe_payment_intent_id' => 'pi_refund_state', 'idempotency_key' => 'refund-state-fixture',
        ], $overrides));
    }

    private function deliver(string $id, string $status, array $overrides = [])
    {
        return $this->signed($id, 'refund.updated', array_merge([
            'id' => 're_state', 'payment_intent' => 'pi_refund_state', 'status' => $status, 'amount' => 8900,
        ], $overrides));
    }

    private function signed(string $id, string $type, array $object)
    {
        $payload = json_encode(['id' => $id, 'type' => $type, 'data' => ['object' => $object]], JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_refund_fixture'),
        ], $payload);
    }
}
