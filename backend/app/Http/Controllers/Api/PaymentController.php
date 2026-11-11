<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Product;
use App\Services\StripeRefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stripe\StripeClient;

class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(Payment::query()->latest()->paginate(min($request->integer('per_page', 25), 100)));
    }

    public function show(Payment $payment): JsonResponse
    {
        $data = $payment->load(['webhookEvents' => fn ($query) => $query->latest('received_at')])->toArray();
        $data['refund_available'] = app(StripeRefundService::class)->testModeEnabled() && $payment->status === 'succeeded' && filled($payment->stripe_payment_intent_id) && blank($payment->refund_status);
        return response()->json(['data' => $data]);
    }

    public function checkoutSession(string $sessionId): JsonResponse
    {
        $payment = Payment::query()->where('stripe_checkout_session_id', $sessionId)->firstOrFail();
        $sessionStatus = null;
        $paymentStatus = null;

        if (config('services.stripe.secret')) {
            try {
                $session = (new StripeClient(config('services.stripe.secret')))->checkout->sessions->retrieve($sessionId);
                $sessionStatus = $session->status;
                $paymentStatus = $session->payment_status;
            } catch (\Throwable) {
                // Keep the webhook-backed state available if Stripe's API cannot be reached.
            }
        }

        return response()->json(['data' => [
            'payment_id' => $payment->id,
            'status' => $payment->status,
            'webhook_status' => $payment->webhook_status,
            'session_status' => $sessionStatus,
            'payment_status' => $paymentStatus,
            'confirmation_pending' => $payment->status === 'processing' || $payment->webhook_status !== 'processed',
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'product_name' => $payment->product_name,
        ]]);
    }

    public function createCheckout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_email' => ['required', 'email'],
            'idempotency_key' => ['required', 'string', 'max:120'],
        ]);
        if ($existing = Payment::where('idempotency_key', $data['idempotency_key'])->first()) {
            return response()->json(['data' => $existing, 'checkout_url' => $existing->checkout_url, 'idempotent_replay' => true]);
        }
        $product = Product::query()->whereKey($data['product_id'])->where('is_active', true)->firstOrFail();

        $stripeSecret = (string) config('services.stripe.secret');
        if ($stripeSecret !== '' && !str_starts_with($stripeSecret, 'sk_test_') && !str_starts_with($stripeSecret, 'rk_test_')) {
            return response()->json(['message' => 'This demo accepts Stripe test keys only.'], 422);
        }

        $payment = DB::transaction(function () use ($data, $product): Payment {
            $payment = Payment::create([
                'product_name' => $product->name, 'amount' => $product->price_cents, 'currency' => $product->currency,
                'customer_name' => $data['customer_name'], 'customer_email' => $data['customer_email'],
                'idempotency_key' => $data['idempotency_key'], 'status' => 'created', 'webhook_status' => 'pending',
            ]);
            $stripeSecret = config('services.stripe.secret');

            if ($stripeSecret) {
                $stripe = new StripeClient($stripeSecret);
                $session = $stripe->checkout->sessions->create([
                    'mode' => 'payment',
                    'customer_email' => $payment->customer_email,
                    'line_items' => [[
                        'price_data' => [
                            'currency' => $product->currency,
                            'product_data' => ['name' => $product->name],
                            'unit_amount' => $payment->amount,
                        ],
                        'quantity' => 1,
                    ]],
                    'metadata' => ['payment_id' => (string) $payment->id],
                    'success_url' => config('services.stripe.success_url'),
                    'cancel_url' => config('services.stripe.cancel_url'),
                ], ['idempotency_key' => $payment->idempotency_key]);

                $payment->update([
                    'stripe_checkout_session_id' => $session->id,
                    'stripe_payment_intent_id' => is_string($session->payment_intent) ? $session->payment_intent : null,
                    'checkout_url' => $session->url,
                    'status' => 'processing',
                ]);
            } else {
                $payment->update(['stripe_payment_intent_id' => 'pi_demo_'.$payment->id, 'status' => 'processing']);
            }

            return $payment->fresh();
        });

        return response()->json(['data' => $payment, 'checkout_url' => $payment->checkout_url], 201);
    }

    public function refund(Payment $payment, StripeRefundService $refundService): JsonResponse
    {
        if (!$refundService->testModeEnabled()) {
            return response()->json(['message' => 'Refunds are available only when Stripe test mode is configured.'], 422);
        }
        if ($payment->status !== 'succeeded' || blank($payment->stripe_payment_intent_id)) {
            return response()->json(['message' => 'Only paid Stripe transactions can be refunded.'], 422);
        }
        if ($payment->refund_status === 'succeeded') {
            return response()->json(['data' => $payment, 'idempotent_replay' => true]);
        }

        try {
            $refund = $refundService->createRefund($payment);
        } catch (\Stripe\Exception\ApiErrorException $exception) {
            return response()->json(['message' => 'Stripe could not create the refund. Check the test-mode payment and try again.'], 502);
        }

        $payment->update([
            'stripe_refund_id' => $refund->id,
            'refund_status' => $refund->status,
            'status' => $refund->status === 'succeeded' ? 'refunded' : $payment->status,
            'refunded_at' => $refund->status === 'succeeded' ? now() : $payment->refunded_at,
        ]);

        return response()->json(['data' => $payment->fresh(), 'refund_status' => $refund->status], 201);
    }

    public function retryWebhook(Payment $payment): JsonResponse
    {
        $payment->update([
            'webhook_status' => 'processed',
            'status' => $payment->status === 'failed' ? 'succeeded' : $payment->status,
            'last_error' => null,
        ]);
        return response()->json(['data' => $payment->fresh()]);
    }
}
