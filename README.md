# Stripe Payment Demo

[![CI](https://github.com/kenvbx/stripe-payment-demo/actions/workflows/ci.yml/badge.svg)](https://github.com/kenvbx/stripe-payment-demo/actions/workflows/ci.yml)

A personal portfolio project demonstrating Stripe Checkout in test mode with a React/Vite operations dashboard, Laravel API, and PostgreSQL deployment. It includes a database product catalog, durable checkout recovery, signed webhooks, administrator access, and full test refunds.

![Payment workspace in local simulation mode](./docs/dashboard-overview.png)

## Start with the UI demo

Requires Node.js 22+ and npm.

```sh
npm ci
cp .env.example .env
npm run dev
```

The example sets `VITE_USE_API=false` for a standalone simulation with sample records. Open the Vite URL shown in the terminal. Checkout creates local sample payments; refunds and verified webhook retries require the API.

## Run the Laravel API

Requires PHP 8.3+, Composer, and PHP curl, mbstring, and SQLite extensions. From the repository root:

```sh
cd backend
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan db:seed
php artisan admin:create admin@example.com
php artisan serve --host=127.0.0.1 --port=8000
```

The admin command privately prompts for a password of at least 12 characters. No default administrator exists. The backend example uses SQLite for local development and leaves Stripe credentials empty. An empty Stripe secret enables the backend's explicit demo checkout mode; it does not contact Stripe or produce signed events.

In the root `.env`, set `VITE_USE_API=true` and restart Vite. Vite proxies `/api` to `http://127.0.0.1:8000`. For a separate API host, set `VITE_API_URL=https://YOUR-API-HOST/api`. The API-enabled interface shows connection errors and uses the database catalog; it never turns an API checkout failure into a local payment.

## Connect Stripe test mode

Set these values in **backend/.env**, then restart the API:

```dotenv
STRIPE_SECRET=sk_test_YOUR_TEST_SECRET
STRIPE_WEBHOOK_SECRET=whsec_YOUR_LISTENER_SECRET
STRIPE_CHECKOUT_SUCCESS_URL=http://localhost:5173/?checkout=success&session_id={CHECKOUT_SESSION_ID}
STRIPE_CHECKOUT_CANCEL_URL=http://localhost:5173/?checkout=cancelled
```

Run `stripe listen --forward-to 127.0.0.1:8000/api/webhooks/stripe` and use its signing secret above. Select a product and choose **Create checkout**. Hosted Checkout accepts Stripe test card `4242 4242 4242 4242`, a future expiry, and any CVC. Live secret keys are rejected. Final state comes from verified events; the result page can refresh the session status.

## Explore the workspace

- **Overview:** counts and successful volume from loaded payment records, with each currency totaled separately.
- **Payments:** search/filter, load additional pages, open details, inspect identifiers and event history, and request a full test refund after confirmation.
- **Products:** the active database catalog in API mode or clearly separated sample products in simulation mode.
- **Webhooks:** search/filter stored deliveries, inspect errors/attempts, and request payment-level replay of a stored event. Unmatched events need reconciliation before retry.
- **API explorer:** copy a working Laravel Checkout request with the correct request body.
- **Settings:** inspect read-only connection values and open deployment instructions.

Sign in as an administrator to load private payment records and events. The one-hour Sanctum token stays in memory; reload requires another sign in. Sign out revokes the token, and 401/403 responses clear private records. Catalog and Checkout remain public.

Records are loaded in pages of up to 100. **Load more** expands the local search/filter and overview totals; these are loaded-record summaries, not global analytics. Use **Refresh records** to fetch the first page again after a webhook or refund.

## Recovery guarantees

Checkout reserves a payment and immutable Stripe request parameters before the network call. Retries preserve the original payment, product price, and Stripe idempotency key after timeout or local persistence failure. A two-minute processing claim rejects overlapping requests; the external call runs outside database transactions with bounded timeouts.

The browser keeps pending keys per product in `sessionStorage` across reloads and clears them only after a confirmed API response. Input conflicts return 409. Unfinished attempts older than 23 hours require reconciliation because Stripe may discard keys after 24 hours. Retry never manufactures a successful payment.

## Verify

```sh
npm run build
npx playwright install chromium
npm run test:e2e
cd backend
php artisan test --no-coverage
```

CI runs the Laravel feature suite on SQLite and PostgreSQL 16, browser tests in explicit simulation/API configurations, and a production-image PostgreSQL integration test using independent workers. The concurrency test forces simultaneous inserts and overlapping requests, verifies one payment/gateway call, and checks stable replay. A separate refund race commits webhook success before releasing a delayed pending API response, then verifies terminal state, timestamps, and replay. Stripe transports and browser API responses are mocked in automated checks; this is not evidence of a live Stripe transaction.

## Documentation

- [API contract](./docs/API.md): routes, authentication, examples, and errors.
- [Operations runbook](./docs/RUNBOOK.md): Checkout recovery, webhook reconciliation, refunds, and troubleshooting.
- [Architecture](./ARCHITECTURE.md): boundaries, state machines, and consistency decisions.
- [Acceptance criteria](./REQUIREMENTS.md): supported behavior and scope.
- [Deployment](./DEPLOYMENT.md): Vercel frontend and Render/PostgreSQL Blueprint.
- [Backend and PostgreSQL test setup](./backend/README.md#postgresql-integration-tests).
- [Portfolio evidence](./docs/PORTFOLIO.md): walkthrough, screenshots, and test map.

This is a **Personal Project / Demo Project**. Deployment configuration is provided; no live URL, production revenue, compliance certification, or real customer volume is claimed.
