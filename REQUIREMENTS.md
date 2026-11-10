# Requirements and Acceptance Criteria

## MVP stories

1. **Create checkout (MVP):** Choose a demo product and create a test payment. The new transaction appears as `processing` and webhook state `pending`.
2. **Inspect payments (MVP):** Search by customer or payment ID and filter by succeeded, processing, failed, or refunded.
3. **Trace events (MVP):** Inspect webhook delivery state, signature verification status, and retry queue status.
4. **Recover failed delivery (MVP):** Retry a pending/failed event; the UI moves it to processed and records the payment state.
5. **Understand safety (MVP):** The demo explains test mode, sample card data, server-side secret handling, and idempotency.
6. **Run locally (MVP):** Install dependencies, start Vite, and run the demo with documented commands.

## Acceptance criteria

- No real card details are requested or persisted.
- Buttons have accessible labels and visible keyboard focus.
- Responsive layouts work at 375px, 768px, 1024px, and desktop widths.
- Status labels use text plus color and never rely on color alone.
- Pending/failed webhook rows expose a retry action.
- Build completes successfully with `npm run build`.

## Follow-up acceptance criteria

- Laravel endpoint rejects invalid Stripe signatures with HTTP 400.
- A unique Stripe event ID prevents duplicate state transitions.
- Payment creation accepts an idempotency key and returns the existing order on retry.
- PHPUnit covers success, failure, duplicate webhook, and invalid signature paths.
