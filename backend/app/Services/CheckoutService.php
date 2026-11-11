<?php

namespace App\Services;

use App\Exceptions\CheckoutException;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;

class CheckoutService
{
    public function __construct(private StripeCheckoutGateway $stripe) {}

    public function create(array $data): array
    {
        $secret = (string) config('services.stripe.secret');
        if ($secret !== '' && !str_starts_with($secret, 'sk_test_') && !str_starts_with($secret, 'rk_test_')) {
            throw new CheckoutException('This demo accepts Stripe test keys only.', 'STRIPE_TEST_MODE_REQUIRED', 422);
        }
        $mode = $secret === '' ? 'demo' : 'stripe';
        $fingerprint = hash('sha256', json_encode([
            'product_id' => (int) $data['product_id'], 'customer_name' => $data['customer_name'],
            'customer_email' => $data['customer_email'], 'mode' => $mode,
        ], JSON_THROW_ON_ERROR));

        try {
            [$payment, $replay] = DB::transaction(function () use ($data, $fingerprint): array {
                $payment = Payment::where('idempotency_key', $data['idempotency_key'])->first();
                $replay = true;
                if (!$payment) {
                    $product = Product::whereKey($data['product_id'])->where('is_active', true)->first();
                    if (!$product) throw ValidationException::withMessages(['product_id' => 'The selected product is unavailable.']);
                    // firstOrCreate resolves a concurrent insert using the unique key.
                    $payment = Payment::firstOrCreate(['idempotency_key' => $data['idempotency_key']], [
                        'customer_name' => $data['customer_name'], 'customer_email' => $data['customer_email'],
                        'product_name' => $product->name, 'amount' => $product->price_cents, 'currency' => $product->currency,
                        'status' => 'created', 'webhook_status' => 'pending', 'checkout_state' => 'pending',
                        'checkout_request_hash' => $fingerprint,
                    ]);
                    $replay = !$payment->wasRecentlyCreated;
                }
                $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
                if (!$payment->checkout_request_hash || !hash_equals($payment->checkout_request_hash, $fingerprint)) {
                    throw new CheckoutException('This idempotency key belongs to a different checkout. Start a new checkout.', 'IDEMPOTENCY_CONFLICT', 409);
                }
                if (!$payment->checkout_parameters) {
                    $payment->update(['checkout_parameters' => [
                        'mode' => 'payment', 'customer_email' => $payment->customer_email,
                        'line_items' => [['price_data' => [
                            'currency' => $payment->currency, 'product_data' => ['name' => $payment->product_name], 'unit_amount' => $payment->amount,
                        ], 'quantity' => 1]],
                        'metadata' => ['payment_id' => (string) $payment->id],
                        'success_url' => config('services.stripe.success_url'), 'cancel_url' => config('services.stripe.cancel_url'),
                    ]]);
                }
                return [$payment->fresh(), $replay];
            });

            $claim = (string) Str::uuid();
            $complete = DB::transaction(function () use ($payment, $claim): bool {
                $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
                if ($locked->checkout_state === 'complete') {
                    if ($locked->checkout_expires_at?->isPast()) {
                        throw new CheckoutException('This checkout session has expired. Start a new checkout.', 'CHECKOUT_EXPIRED', 409);
                    }
                    return true;
                }
                // Stripe may prune idempotency keys after 24h. Never blindly recreate beyond this window.
                if ($locked->created_at->lte(now()->subHours(23))) {
                    throw new CheckoutException('The safe retry window has expired. Contact the administrator before starting another checkout.', 'CHECKOUT_RETRY_EXPIRED', 409);
                }
                if ($locked->checkout_claim_token && $locked->checkout_claim_expires_at?->isFuture()) {
                    throw new CheckoutException('This checkout is still being created. Wait a few seconds and retry.', 'CHECKOUT_IN_PROGRESS', 409, true);
                }
                $locked->update(['checkout_state' => 'creating', 'checkout_claim_token' => $claim, 'checkout_claim_expires_at' => now()->addMinutes(2)]);
                return false;
            });
            if ($complete) return ['payment' => $payment->fresh(), 'replay' => true, 'mode' => $mode];
        } catch (CheckoutException|ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::warning('Checkout reservation failed.', ['exception' => $exception::class]);
            throw new CheckoutException('Checkout is temporarily unavailable. Retry the same request.', 'CHECKOUT_UNAVAILABLE', 503, true);
        }

        try {
            // The durable row and immutable request snapshot are committed before this external call.
            $session = $mode === 'stripe' ? $this->stripe->create($payment) : null;
            if ($mode === 'stripe' && (!$session->id || !$session->url)) {
                throw new \RuntimeException('Stripe returned an incomplete checkout session.');
            }
            $payment = DB::transaction(function () use ($payment, $claim, $session): Payment {
                $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
                if ($locked->checkout_state === 'complete') return $locked;
                if ($locked->checkout_claim_token !== $claim) {
                    throw new CheckoutException('Checkout recovery is still in progress. Retry shortly.', 'CHECKOUT_IN_PROGRESS', 409, true);
                }
                $locked->update([
                    'stripe_checkout_session_id' => $session?->id,
                    'stripe_payment_intent_id' => $session ? (is_string($session->payment_intent) ? $session->payment_intent : $locked->stripe_payment_intent_id) : 'pi_demo_'.$locked->id,
                    'checkout_url' => $session?->url,
                    'checkout_expires_at' => $session?->expires_at ? now()->setTimestamp($session->expires_at) : null,
                    'status' => $locked->status === 'created' ? 'processing' : $locked->status,
                    'checkout_state' => 'complete', 'checkout_claim_token' => null, 'checkout_claim_expires_at' => null,
                ]);
                return $locked->fresh();
            });
            return ['payment' => $payment, 'replay' => $replay, 'mode' => $mode];
        } catch (\Throwable $exception) {
            try {
                Payment::whereKey($payment->id)->where('checkout_claim_token', $claim)->update([
                    'checkout_state' => 'pending', 'checkout_claim_token' => null, 'checkout_claim_expires_at' => null,
                ]);
            } catch (\Throwable) {
                // If the DB is unavailable, the short lease permits later recovery with the same key.
            }
            if ($exception instanceof CheckoutException) throw $exception;
            if ($exception instanceof AuthenticationException) {
                throw new CheckoutException('Stripe test credentials need to be configured by the administrator.', 'STRIPE_CONFIGURATION_ERROR', 503);
            }
            if ($exception instanceof ApiErrorException && in_array($exception->getHttpStatus(), [400, 402, 404], true)) {
                throw new CheckoutException('Stripe rejected this checkout. Ask the administrator to check the product configuration.', 'CHECKOUT_REJECTED', 422);
            }
            Log::warning('Checkout requires recovery.', ['payment_id' => $payment->id, 'exception' => $exception::class]);
            throw new CheckoutException('Checkout could not be confirmed. Retry to recover the same checkout.', 'CHECKOUT_RECOVERY_REQUIRED', 503, true);
        }
    }
}
