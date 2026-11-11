# Architecture

## Current vertical slice

The first slice is a Vite + React client with deterministic local demo data. It keeps the reviewable UI and payment state model usable before Stripe credentials or a database are configured.

## Target backend boundary

Laravel modular monolith:

- `Payment` domain owns products, orders, payment intents, and lifecycle transitions.
- `Webhook` domain verifies `Stripe-Signature`, deduplicates by Stripe event ID, and applies state transitions inside a database transaction.
- REST endpoints expose checkout creation, payment history, webhook health, and retry operations.
- MySQL stores orders, payment attempts, webhook events, and audit records.
- Stripe Checkout is an external dependency; secret keys are server-side only and webhook events are verified before state changes.

## State model

`created → processing → succeeded | failed`; confirmed success can recover `failed`, and a verified full refund can move a payment to `refunded` even when its success event arrives later. `succeeded` never regresses to `processing` or `failed`; `refunded` is terminal. Repeated confirmations preserve the first recorded payment/refund timestamps. A completed Checkout Session remains `processing` unless its payment status is `paid` or `no_payment_required`.

Webhook event delivery is independent: `received → processed | ignored | failed`. Unsupported events and transitions that would regress payment state are acknowledged as `ignored`. An unmatched supported event is retained as `failed` and returns HTTP 500 so Stripe can redeliver it after the payment is persisted.

## Consistency and reliability

The database is the source of truth after a verified webhook. Checkout Session creation accepts an idempotency key with a unique constraint and redirects to Stripe-hosted test checkout. Webhook processing locks both event and payment rows. Already processed/ignored event IDs are acknowledged without reapplying changes.

`POST /api/payments/{payment}/retry-webhook` selects the oldest received/failed verified event associated with that payment or its Stripe identifiers, then runs the same processor against the stored field snapshot. One call retries one event. Each processing attempt increments the event's audit counter; a failure is retained even if the payment update transaction rolls back. A missing retryable event returns HTTP 422; processing failure returns HTTP 503. Manual retries do not manufacture payment success, and the frontend shows API errors without simulating a successful retry. Stripe controls automatic redelivery/backoff; no application retry scheduler is implemented.

## Initial workload assumptions

Personal portfolio demo, low traffic, one region, low data volume, seconds-level latency acceptable. A relational database is sufficient; no cache, queue cluster, or service split is justified yet.

## Security decisions

Administrative payment reads, event logs, refunds, and manual retries require Sanctum bearer authentication, the persisted `admin` user role, and an `admin` token ability. Tokens expire after one hour, are stored as hashes in the database, and are revoked on logout. The browser keeps the token in memory and discards private data on logout or a 401 response; late requests from an ended session cannot restore private records. Login is rate limited by email/IP and by IP. Administrators are provisioned through a private CLI prompt or optional server-only first-deployment bootstrap; there is no public registration endpoint. Product lookup, customer checkout, and session results are public, while incoming webhooks require Stripe's signature.

Use test keys only, validate all input, verify webhook signatures, never log secrets or full card data, and keep Stripe secret keys out of the browser. Production deployment would require HTTPS, rate limiting, secret management, and backups.
