# Demo deployment

This project has a reproducible demo deployment shape:

- **Frontend:** Vercel, configured by [`vercel.json`](./vercel.json).
- **Laravel API:** Render Docker web service backed by a managed PostgreSQL database, configured by [`render.yaml`](./render.yaml) and [`backend/Dockerfile`](./backend/Dockerfile).
- **CI:** GitHub Actions builds the Vite frontend, runs PHPUnit on SQLite/PostgreSQL 16 and Playwright, and builds/starts the deployment image against PostgreSQL 16.

## Deploy the frontend to Vercel

Create a Vercel project from this GitHub repository and keep the project root at the repository root. Set:

```text
VITE_USE_API=true
VITE_API_URL=https://YOUR-RENDER-SERVICE.onrender.com/api
```

Vercel will run `npm run build` and serve `dist/`.

## Deploy the API to Render

Create or update the Blueprint from this repository. Render provisions the `stripe-payment-demo-db` PostgreSQL database, injects its internal connection string as `DB_URL`, builds `backend/Dockerfile` with `pdo_pgsql`, runs migrations and idempotent product seeding on boot, and exposes the Laravel health response at `/`.

The Docker build installs production Composer dependencies without scripts or an autoloader first. After the Laravel sources and writable runtime directories are in place, it generates the optimized autoloader, runs package discovery, and checks PHP platform requirements. PostgreSQL and mbstring extensions are installed in the image. Local `.env` files, vendor dependencies, and compiled Laravel cache files are excluded from the build context. Database credentials and `APP_KEY` are supplied at runtime; migrations and seeding run when the container starts, not during image construction.

The CI Docker job verifies a fresh image can migrate and seed PostgreSQL, return the three catalog products, reject anonymous admin access, authenticate the private test administrator, and revoke its token on logout. It also forces overlapping Checkout requests and simultaneous inserts from independent workers, verifying one payment and one fake gateway call. Its database and account credentials are disposable CI fixtures.

Set these values in the Render dashboard:

```text
FRONTEND_URL=https://YOUR-VERCEL-PROJECT.vercel.app
ADMIN_EMAIL=YOUR-PRIVATE-ADMIN-EMAIL
ADMIN_PASSWORD=YOUR-PRIVATE-PASSWORD-AT-LEAST-12-CHARACTERS
STRIPE_KEY=pk_test_...
STRIPE_SECRET=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_CHECKOUT_SUCCESS_URL=https://YOUR-VERCEL-PROJECT.vercel.app/?checkout=success&session_id={CHECKOUT_SESSION_ID}
STRIPE_CHECKOUT_CANCEL_URL=https://YOUR-VERCEL-PROJECT.vercel.app/?checkout=cancelled
```

The Blueprint uses Render's free PostgreSQL plan to avoid requiring a paid service. Free PostgreSQL databases have a limited lifetime and are intended for evaluation; upgrade the database plan in Render before relying on long-term data retention. Keep the web service and database in the same Render region so the injected connection string uses the private network.

`ADMIN_EMAIL` and `ADMIN_PASSWORD` are private server environment values used only to create the first administrator during seeding. The seeder creates no default account, never resets an existing administrator password, and refuses to promote an existing non-admin account. After the first successful deployment, remove both bootstrap values from the service environment; the account remains in PostgreSQL. Never set them as frontend `VITE_*` values or commit actual credentials. Alternatively, create an admin using the backend's interactive `php artisan admin:create EMAIL` command when shell access is available.

The dashboard signs in through `POST /api/admin/login` and sends its one-hour bearer token in the `Authorization` header. Tokens are stored only in browser memory. CORS allows the configured frontend origin and the Authorization header; this flow does not use cross-domain session cookies. Ensure both frontend and API are served over HTTPS. Run `php artisan sanctum:prune-expired --hours=24` periodically to remove old expired token rows; expiration is enforced even before pruning.

## Stripe webhook forwarding

For local development:

```bash
stripe listen --forward-to 127.0.0.1:8000/api/webhooks/stripe
```

For a hosted demo, add a Stripe Dashboard test-mode endpoint:

```text
https://YOUR-RENDER-SERVICE.onrender.com/api/webhooks/stripe
```

Subscribe to `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `payment_intent.succeeded`, `payment_intent.processing`, and `payment_intent.payment_failed`, `charge.refunded`, and `refund.updated`.

Never commit `.env`, Stripe secret keys, or webhook signing secrets.
