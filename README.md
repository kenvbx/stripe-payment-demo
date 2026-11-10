# Stripe Payment Demo

Personal/demo project showing a Stripe test-mode payment flow with checkout creation, transaction history, webhook states, idempotency, and recovery paths. It is not a production payment system and makes no live-payment claims.

## Run the frontend demo

```bash
npm install
npm run dev
```

Then open the Vite URL shown in the terminal. The UI uses local deterministic demo data, so Stripe credentials are not required for the first vertical slice.

## Verify

```bash
npm run build
```

The build is the current automated smoke check. Before a live Stripe integration, add PHPUnit/API tests for signature verification, duplicate events, idempotency, and payment state transitions.

## Planned Laravel integration

The intended backend is a Laravel modular monolith using Stripe test mode. Keep `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, and database credentials in `.env`; never commit them. The API explorer in the demo shows the server-side PaymentIntent request and its `Idempotency-Key`.

Use Stripe's test card `4242 4242 4242 4242` only in Stripe test mode, with any future expiry and any CVC. Do not enter real card data.

## Portfolio label

`Personal Project` / `Demo Project`.
