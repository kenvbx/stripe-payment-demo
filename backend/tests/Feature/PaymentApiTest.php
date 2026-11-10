<?php
namespace Tests\Feature;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class PaymentApiTest extends TestCase {
    use RefreshDatabase;
    public function test_checkout_is_idempotent(): void {
        $payload=['product_name'=>'Pro workspace','amount'=>8900,'customer_name'=>'Demo Customer','customer_email'=>'demo@example.com','idempotency_key'=>'checkout-test-1'];
        $first=$this->postJson('/api/payments/checkout',$payload)->assertCreated();
        $second=$this->postJson('/api/payments/checkout',$payload)->assertOk()->assertJsonPath('idempotent_replay',true);
        $this->assertSame($first->json('data.id'),$second->json('data.id'));
        $this->assertDatabaseCount('payments',1);
    }
    public function test_failed_webhook_can_be_retried(): void {
        $payment=Payment::create(['stripe_payment_intent_id'=>'pi_demo_test','customer_name'=>'Demo','customer_email'=>'demo@example.com','product_name'=>'Starter plan','amount'=>2900,'currency'=>'usd','status'=>'failed','webhook_status'=>'retrying','idempotency_key'=>'retry-test']);
        $this->postJson('/api/payments/'.$payment->id.'/retry-webhook')->assertOk()->assertJsonPath('data.webhook_status','processed')->assertJsonPath('data.status','succeeded');
    }
}
