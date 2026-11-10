<?php
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\Api\WebhookEventController;
use Illuminate\Support\Facades\Route;
Route::get('payments', [PaymentController::class, 'index']);
Route::get('payments/{payment}', [PaymentController::class, 'show']);
Route::get('webhook-events', [WebhookEventController::class, 'index']);
Route::post('payments/checkout', [PaymentController::class, 'createCheckout']);
Route::post('payments/{payment}/retry-webhook', [PaymentController::class, 'retryWebhook']);
Route::post('webhooks/stripe', [StripeWebhookController::class, 'handle']);
