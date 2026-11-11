# Demo deployment

This project has a reproducible demo deployment shape:

- **Frontend:** Vercel, configured by [`vercel.json`](./vercel.json).
- **Laravel API:** Render Docker web service, configured by [`render.yaml`](./render.yaml) and [`backend/Dockerfile`](./backend/Dockerfile).
- **CI:** GitHub Actions builds the Vite frontend and runs Laravel PHPUnit tests.

## Deploy the frontend to Vercel

Create a Vercel project from this GitHub repository and keep the project root at the repository root. Set:

```text
VITE_USE_API=true
VITE_API_URL=https://YOUR-RENDER-SERVICE.onrender.com/api
```

Vercel will run `npm run build` and serve `dist/`.

## Deploy the API to Render

Create a new Blueprint from this repository. Render reads `render.yaml`, builds `backend/Dockerfile`, runs migrations on boot, and exposes the Laravel health response at `/`.

Set these values in the Render dashboard:

```text
FRONTEND_URL=https://YOUR-VERCEL-PROJECT.vercel.app
STRIPE_KEY=pk_test_...
STRIPE_SECRET=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_CHECKOUT_SUCCESS_URL=https://YOUR-VERCEL-PROJECT.vercel.app/?checkout=success&session_id={CHECKOUT_SESSION_ID}
STRIPE_CHECKOUT_CANCEL_URL=https://YOUR-VERCEL-PROJECT.vercel.app/?checkout=cancelled
```

The free demo uses SQLite inside the container. Render free instances have ephemeral storage, so use a managed PostgreSQL database and update `DB_CONNECTION`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` before treating the deployment as persistent.

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
