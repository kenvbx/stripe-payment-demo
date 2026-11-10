<?php
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\StripeWebhookController;
use Illuminate\Support\Facades\Route;
Route::get('payments', [PaymentController::class, 'index']);
Route::post('payments/checkout', [PaymentController::class, 'createCheckout']);
Route::post('payments/{payment}/retry-webhook', [PaymentController::class, 'retryWebhook']);
Route::post('webhooks/stripe', [StripeWebhookController::class, 'handle']);
