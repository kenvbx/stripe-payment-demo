# Stripe Payment Demo — Product brief

## Purpose and audience

A personal portfolio payment workspace for a developer or reviewer to inspect a Stripe test-mode lifecycle: database product → durable Checkout Session → signed webhook → payment state → test refund. The repository supplies reproducible setup and evidence without claiming real customer payments or production readiness.

## Delivered scope

- React/Vite interface with explicit local simulation and API modes.
- Laravel catalog, Checkout creation/recovery, result summaries, payment details, stored webhook history/replay, and full test refunds.
- Private administrator provisioning, Sanctum sign in/out, and role/ability checks.
- PostgreSQL demo deployment and SQLite local development.
- Responsive navigation, loading/error/empty states, loaded-record summaries, pagination, event search/filter, API request copy, and read-only environment information.
- Automated browser, feature, and PostgreSQL concurrency checks, with documented manual Stripe test-mode verification.

## Success criteria

A reviewer can start the UI without Stripe credentials, connect the API using the documented setup, understand each payment/event state, recover retryable failures without creating duplicate payments, and trace the evidence behind each claim. The interface distinguishes sample data, pending confirmation, and webhook-confirmed outcomes.

## Constraints and limits

All payments are test mode. Credentials remain on the server. API mode displays real errors instead of substituting sample catalog prices or simulating payment success. Metrics cover loaded records and preserve currency boundaries. There are no live-mode charges, partial refunds, subscriptions, background retry scheduler, global analytics service, or compliance claims. Deployment recipes do not assert an available public environment.

See [REQUIREMENTS.md](./REQUIREMENTS.md), [ARCHITECTURE.md](./ARCHITECTURE.md), and [docs/PORTFOLIO.md](./docs/PORTFOLIO.md) for acceptance criteria, technical decisions, and evidence.
