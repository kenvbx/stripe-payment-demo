<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Exceptions\CheckoutException;
use App\Services\CheckoutService;
use App\Services\StripeRefundService;
use App\Services\RefundStateMachine;
use Illuminate\Support\Facades\DB;
use App\Services\StripeWebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function createCheckout(Request $request, CheckoutService $checkout): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'min:1'],
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_email' => ['required', 'email', 'max:255'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
        ]);
        try {
            $result = $checkout->create($data);
        } catch (CheckoutException $exception) {
            $response = response()->json([
                'message' => $exception->getMessage(), 'error_code' => $exception->errorCode, 'retryable' => $exception->retryable,
            ], $exception->httpStatus)->header('Cache-Control', 'no-store');
            return $exception->retryable ? $response->header('Retry-After', '5') : $response;
        }
        $payment = $result['payment'];
        return response()->json([
            'data' => $payment,
            'checkout_url' => in_array($payment->status, ['succeeded', 'refunded'], true) ? null : $payment->checkout_url,
            'checkout_mode' => $result['mode'], 'idempotent_replay' => $result['replay'],
        ], $result['replay'] ? 200 : 201)->header('Cache-Control', 'no-store');
    }

    public function refund(Payment $payment, StripeRefundService $refundService, RefundStateMachine $refundStates): JsonResponse
    {
        if (!$refundService->testModeEnabled()) {
            return response()->json(['message' => 'Refunds are available only when Stripe test mode is configured.'], 422);
        }
        // Read current state under lock; never hold the lock during the Stripe request.
        $payment = DB::transaction(fn () => Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail());
        if ($payment->status === 'refunded' || $payment->refund_status === 'succeeded') {
            return response()->json(['data' => $payment, 'refund_status' => $payment->refund_status, 'idempotent_replay' => true]);
        }
        if ($payment->status !== 'succeeded' || blank($payment->stripe_payment_intent_id)) {
            return response()->json(['message' => 'Only paid Stripe transactions can be refunded.'], 422);
        }
        if (filled($payment->stripe_refund_id) || filled($payment->refund_status)) {
            // Replay pending/failed/canceled attempts, without silently creating another refund.
            return response()->json(['data' => $payment, 'refund_status' => $payment->refund_status, 'idempotent_replay' => true]);
        }

        try {
            $refund = $refundService->createRefund($payment);
        } catch (\Stripe\Exception\ApiErrorException $exception) {
            return response()->json(['message' => 'Stripe could not create the refund. Check the test-mode payment and try again.'], 502);
        }

        $payment = DB::transaction(function () use ($payment, $refund, $refundStates): Payment {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $refundStates->apply($locked, $refund->id, (string) $refund->status, true);
            return $locked->fresh();
        });
        return response()->json(['data' => $payment, 'refund_status' => $payment->refund_status], 201);
    }

    public function retryWebhook(Payment $payment, StripeWebhookProcessor $processor): JsonResponse
    {
        try {
            $event = $processor->retryForPayment($payment);
        } catch (\RuntimeException) {
            return response()->json(['message' => 'The verified event could not be processed. Check the webhook event log before retrying.'], 503);
        }
        if (!$event) {
            return response()->json(['message' => 'No received or failed verified webhook event is available for this payment.'], 422);
        }

        return response()->json([
            'data' => $payment->fresh()->load('webhookEvents'),
            'webhook_event' => $event,
        ]);
    }
}
