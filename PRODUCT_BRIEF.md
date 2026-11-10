# Stripe Payment Demo — Product Brief

## Purpose

A personal/demo payment operations console that shows a complete Stripe test-mode lifecycle: create a checkout, track a PaymentIntent, receive a signed webhook, handle duplicate delivery safely, and expose recovery for failed events.

## Target user

A developer or reviewer who needs a compact, inspectable example of reliable payment integration without real money or production claims.

## Success criteria

- A reviewer can create a test checkout in under one minute.
- Every payment shows an explicit lifecycle state and webhook state.
- Failed/pending webhook events have a visible retry path.
- The repository contains reproducible setup, safe environment documentation, and meaningful tests for state transitions.

## Scope

### MVP

- Demo products and checkout creation.
- Payment transaction history with search and status filters.
- Webhook delivery log with signature/idempotency explanation.
- API explorer showing server-side PaymentIntent request.
- Responsive UI and safe test-card guidance.

### Follow-up

- Live Stripe Checkout Session creation from Laravel.
- Stripe CLI local webhook forwarding.
- Persistent MySQL transaction/event tables and PHPUnit integration tests.
- Authenticated admin area and audit export.

### Out of scope

- Live mode charges, real card data, payout/reconciliation, subscriptions, and production compliance claims.

## Constraints and assumptions

- This is a personal/demo project.
- Stripe test mode only; all credentials stay in environment variables.
- The first vertical slice is intentionally runnable without Stripe credentials using local simulation data.
