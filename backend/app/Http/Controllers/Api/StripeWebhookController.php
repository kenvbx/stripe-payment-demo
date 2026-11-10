<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StripeWebhookProcessor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Webhook;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    public function handle(Request $request, StripeWebhookProcessor $processor): Response
    {
        $payload = $request->getContent();
        try {
            $event = Webhook::constructEvent($payload, (string) $request->header('Stripe-Signature'), (string) config('services.stripe.webhook_secret'));
        } catch (UnexpectedValueException|\Stripe\Exception\SignatureVerificationException) {
            return response('Invalid webhook signature.', 400);
        }

        try {
            $processor->recordAndProcess($event, $payload);
        } catch (\RuntimeException) {
            return response('Webhook processing failed. Stripe may retry this event.', 500);
        }

        return response('', 200);
    }
}
