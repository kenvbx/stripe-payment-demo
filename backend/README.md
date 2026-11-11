# Stripe Payment Demo API

Laravel API for the personal Stripe test-mode demo.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan serve
```

Set `STRIPE_SECRET` and `STRIPE_WEBHOOK_SECRET` only when connecting Stripe test mode. Without them, checkout creation uses a local `pi_demo_*` intent so the frontend can be reviewed safely.

## Endpoints

Public: `GET /api/products`, `POST /api/payments/checkout`, and `GET /api/checkout/sessions/{sessionId}`. Stripe authenticates `POST /api/webhooks/stripe` with its signature.

`POST /api/admin/login` accepts email/password and returns a one-hour Sanctum token. Use `Authorization: Bearer <token>` for the protected routes below. `GET /api/admin/me` checks the current administrator; `POST /api/admin/logout` revokes the current token. Every protected route requires both `role=admin` and the `admin` token ability. No account is created unless explicitly provisioned with `php artisan admin:create EMAIL` or the optional private bootstrap environment variables.

- `GET /api/payments`
- `GET /api/payments/{payment}`
- `GET /api/webhook-events`
- `POST /api/payments/{payment}/retry-webhook`
- `POST /api/payments/{payment}/refund`

The webhook endpoint verifies `Stripe-Signature`, locks the payment row, ignores duplicate event IDs, and applies payment intent state transitions inside a transaction.

`ProductSeeder` creates or updates the three sample catalog entries. The deployment container runs it after migrations so a fresh demo deployment has products available.

## Checkout error contract

`POST /api/payments/checkout` requires `product_id`, customer name/email, and an idempotency key (8–120 characters). A new checkout returns 201; recovery/replay returns 200 with `idempotent_replay: true`. Use the same body and key after a retryable response. Errors include `message`, `error_code`, and `retryable`; retryable responses also send `Retry-After: 5`.

The recovery migration adds an immutable request snapshot and a two-minute processing claim. Apply migrations before deploying the updated API. Pending attempts older than 23 hours return `CHECKOUT_RETRY_EXPIRED` and need administrator reconciliation before another checkout is started. Legacy rows without a fingerprint return `IDEMPOTENCY_CONFLICT`.

Feature tests use a fake Stripe gateway/HTTP transport. `tests/Integration/checkout-concurrency.php` runs two real PHP workers against the disposable `stripe_demo_ci` PostgreSQL database in Docker CI and verifies one payment, one gateway invocation, and stable replay.
