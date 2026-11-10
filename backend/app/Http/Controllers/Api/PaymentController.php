<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
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
        return response()->json(['data' => $payment]);
    }

    public function createCheckout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_name' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'integer', 'min:50'],
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_email' => ['required', 'email'],
            'idempotency_key' => ['required', 'string', 'max:120'],
        ]);

        if ($existing = Payment::where('idempotency_key', $data['idempotency_key'])->first()) {
            return response()->json(['data' => $existing, 'checkout_url' => $existing->checkout_url, 'idempotent_replay' => true]);
        }

        $payment = DB::transaction(function () use ($data): Payment {
            $payment = Payment::create($data + ['currency' => 'usd', 'status' => 'created', 'webhook_status' => 'pending']);
            $stripeSecret = config('services.stripe.secret');

            if ($stripeSecret) {
                $stripe = new StripeClient($stripeSecret);
                $session = $stripe->checkout->sessions->create([
                    'mode' => 'payment',
                    'customer_email' => $payment->customer_email,
                    'line_items' => [[
                        'price_data' => [
                            'currency' => $payment->currency,
                            'product_data' => ['name' => $payment->product_name],
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
