# Stripe Payment Demo

Personal/demo project showing a Stripe test-mode payment flow with checkout creation, transaction history, webhook states, idempotency, recovery paths, and full refunds in Stripe test mode. It is not a production payment system and makes no live-payment claims.

## Run the frontend demo

```bash
npm install
npm run dev
```

Then open the Vite URL shown in the terminal. The UI connects to the Laravel API when it is available. If the API is offline, it falls back to local demo data so the interface remains reviewable.

## Run with Laravel API

```bash
cd backend
php artisan migrate
php artisan db:seed
php artisan serve --port=8000
```

In a separate terminal, start the frontend with `VITE_USE_API=true` (the default). Vite proxies `/api` to `http://127.0.0.1:8000`. For a separately hosted API, set `VITE_API_URL` to its `/api` URL.

## Verify

```bash
npm run build
```

`npm run build` builds the frontend. Run the Laravel feature suite with `cd backend && php artisan test`.
Run the browser end-to-end checks with `npx playwright install chromium && npm run test:e2e`.

## Product catalog

The Laravel database is the source of truth for the catalog. Seed the three sample products with `php artisan db:seed` and read active products from `GET /api/products`. Checkout requests send only `product_id` and customer details; the API reads the product name, amount in cents, and currency from the database. The frontend keeps its local sample catalog only as an offline fallback.

## Administrator access

After installing dependencies and running migrations, create an administrator from the backend:

```bash
php artisan admin:create admin@example.com
```

The command prompts privately for a password of at least 12 characters and its confirmation. Open **Admin sign in** in the dashboard to authenticate. There is no public registration endpoint or default admin password.

Login issues a Sanctum bearer token with the `admin` ability and a one-hour lifetime. The browser holds it only in memory; reloading the page requires signing in again. Sign out revokes the current token and clears private records. Expired/revoked tokens return 401; a non-admin or token without the admin ability returns 403. Login is limited to five attempts per minute per email/IP and twenty per minute per IP.

Payment history/detail, webhook logs, refund, retry, and admin account endpoints require authentication and the admin role/ability. Catalog, Checkout creation, and the session result endpoint remain public; the Stripe webhook endpoint requires its signature. For first deployment without shell access, see the private environment bootstrap in [DEPLOYMENT.md](./DEPLOYMENT.md).

## Refund a test payment

Open a succeeded payment in the detail view and choose **Refund test payment**. The Laravel API requires a Stripe test secret, only accepts succeeded payments, and uses an idempotency key for the refund request. Stripe confirmation updates the payment to `refunded`; `charge.refunded` and `refund.updated` webhooks also synchronize refund state. Live Stripe keys are rejected by checkout and refund endpoints.

## Webhook recovery

Retry replays one received/failed event whose verified fields are already stored in the database. If no such event exists, the API returns 422; it never marks a payment successful just because retry was clicked. Unmatched supported events return 500 and remain retryable so Stripe can redeliver them. Late events cannot regress succeeded/refunded payments, and an unpaid completed Checkout Session waits for a confirmed success event.

## Laravel configuration

The backend is a Laravel modular monolith using Stripe Checkout in test mode. With `STRIPE_SECRET` configured, checkout creation returns a Stripe-hosted Checkout URL and the browser redirects there. Configure the Stripe CLI to forward events to `/api/webhooks/stripe`:

```bash
stripe listen --forward-to 127.0.0.1:8000/api/webhooks/stripe
```

Copy the CLI `whsec_...` value into `STRIPE_WEBHOOK_SECRET`. Keep `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, and database credentials in `.env`; never commit them. The API explorer in the demo shows the server-side PaymentIntent request and its `Idempotency-Key`.

Use Stripe's test card `4242 4242 4242 4242` only in Stripe test mode, with any future expiry and any CVC. Do not enter real card data.

## Portfolio evidence

See [docs/PORTFOLIO.md](./docs/PORTFOLIO.md) for a reviewer walkthrough, architecture flow, evidence map, dashboard screenshot, and payment detail view.

![Payment operations dashboard](./docs/dashboard-overview.png)

## Portfolio label

`Personal Project` / `Demo Project`.
