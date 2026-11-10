# Architecture

## Current vertical slice

The first slice is a Vite + React client with deterministic local demo data. It keeps the reviewable UI and payment state model usable before Stripe credentials or a database are configured.

## Target backend boundary

Laravel modular monolith:

- `Payment` domain owns products, orders, payment intents, and lifecycle transitions.
- `Webhook` domain verifies `Stripe-Signature`, deduplicates by Stripe event ID, and applies state transitions inside a database transaction.
- REST endpoints expose checkout creation, payment history, webhook health, and retry operations.
- MySQL stores orders, payment attempts, webhook events, and audit records.
- Stripe is an external dependency; secret keys are server-side only.

## State model

`created → processing → succeeded | failed`; `succeeded → refunded` is an explicit transition. Webhook delivery is independent: `pending → processed | retrying | dead_letter`.

## Consistency and reliability

The database is the source of truth after a verified webhook. Checkout creation accepts an idempotency key with a unique constraint. Webhook processing locks the event row and ignores an already processed Stripe event ID. Retries are bounded and use exponential backoff. The demo UI exposes pending state rather than hiding eventual consistency.

## Initial workload assumptions

Personal portfolio demo, low traffic, one region, low data volume, seconds-level latency acceptable. A relational database is sufficient; no cache, queue cluster, or service split is justified yet.

## Security decisions

Use test keys only, validate all input, verify webhook signatures, never log secrets or full card data, and keep Stripe secret keys out of the browser. Production deployment would require HTTPS, rate limiting, secret management, and backups.
