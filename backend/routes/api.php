<?php

use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\Api\WebhookEventController;
use Illuminate\Support\Facades\Route;

Route::get('products', [ProductController::class, 'index']);
Route::get('checkout/sessions/{sessionId}', [PaymentController::class, 'checkoutSession'])->middleware('throttle:api');
Route::post('payments/checkout', [PaymentController::class, 'createCheckout'])->middleware('throttle:api');
Route::post('webhooks/stripe', [StripeWebhookController::class, 'handle']);
Route::post('admin/login', [AdminAuthController::class, 'login'])->middleware('throttle:login');

Route::middleware(['auth:sanctum', 'admin', 'throttle:api'])->group(function (): void {
    Route::get('admin/me', [AdminAuthController::class, 'me']);
    Route::post('admin/logout', [AdminAuthController::class, 'logout']);
    Route::get('payments', [PaymentController::class, 'index']);
    Route::get('payments/{payment}', [PaymentController::class, 'show']);
    Route::get('webhook-events', [WebhookEventController::class, 'index']);
    Route::post('payments/{payment}/retry-webhook', [PaymentController::class, 'retryWebhook']);
    Route::post('payments/{payment}/refund', [PaymentController::class, 'refund']);
});
