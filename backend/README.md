# Stripe Payment Demo API

Laravel API for the personal Stripe test-mode demo.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

Set `STRIPE_SECRET` and `STRIPE_WEBHOOK_SECRET` only when connecting Stripe test mode. Without them, checkout creation uses a local `pi_demo_*` intent so the frontend can be reviewed safely.

## Endpoints

- `GET /api/payments`
- `POST /api/payments/checkout`
- `POST /api/payments/{payment}/retry-webhook`
- `POST /api/webhooks/stripe`

The webhook endpoint verifies `Stripe-Signature`, locks the payment row, ignores duplicate event IDs, and applies payment intent state transitions inside a transaction.
