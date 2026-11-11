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


## PostgreSQL integration tests

CI runs the complete feature suite on both SQLite and PostgreSQL 16. The PostgreSQL run covers API persistence, immutable checkout JSON snapshots, timeout and local-save recovery, processing-claim expiry, webhook state transitions/retries, refunds, and administrator access.

To run locally, create a dedicated disposable database named `stripe_demo_test` (or `stripe_demo_test_<lowercase suffix>`). The feature suite rebuilds its schema. Set the connection variables in your shell before these commands; use the test database credentials for your local PostgreSQL installation:

```sh
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432
export DB_DATABASE=stripe_demo_test DB_USERNAME=stripe_demo_test
export DB_URL=''
# Set DB_PASSWORD if your local server requires one.
php artisan test --no-coverage
php artisan db:seed --force
php tests/Integration/checkout-concurrency.php
```

The standalone test also accepts Docker CI's `stripe_demo_ci` database. It uses independent PHP processes and explicit barriers to verify:

- A duplicate request returns `CHECKOUT_IN_PROGRESS` while the owner waits for the gateway.
- Conflicting customer input is rejected while the original request is active.
- Two transactions which both observe a missing key race to insert: the unique constraint and savepoint recovery yield one payment and one gateway call.
- Persisted parameters stay unchanged, claims are cleared, and replay returns the same payment without another gateway call.

Worker waits and database lock/statement timeouts are bounded. Workers and fixtures are cleaned up on failure. Stripe is replaced with a local fake gateway; these are real PostgreSQL integration checks, not live Stripe API checks.
