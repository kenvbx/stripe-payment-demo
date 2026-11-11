# Demo operations runbook

## Check the environment

1. Confirm **Settings** shows the intended API base and data source. Local simulation cannot process verified event retries or real test refunds.
2. In API mode, use **Retry catalog** for catalog errors and **Refresh records** after signing in. Browser search, filters, and totals cover loaded pages; use **Load more** when needed.
3. For a cold hosted API, wait for startup/migrations and retry. Check the API health endpoint and server logs if it remains unavailable.

## Checkout could not be confirmed

Keep the pending browser key; retry the same product/request after the indicated delay. The server reuses its payment and frozen Stripe request. An active processing claim can last up to two minutes after a worker crashes. Do not clear sessionStorage or start a replacement attempt to bypass an ambiguous failure.

For conflicts, expired sessions, or the 23-hour recovery cutoff, an administrator must inspect the payment and its Stripe test Session/PaymentIntent before creating another checkout. Verify whether the original session exists, is expired, or has already paid. Fix missing/invalid test credentials on the API, then retry inside the safe window. Never put secrets in `VITE_*` variables.

## Webhook is pending or failed

1. Forward Stripe test events locally, or confirm the hosted test endpoint and signing secret. If the endpoint returns 503 with `Webhook endpoint is not configured.`, set a nonempty `STRIPE_WEBHOOK_SECRET` on the API and restart it (clear/rebuild Laravel configuration cache if used). Requests are rejected without storing events or changing payments until the secret is configured; Stripe can then redeliver.
2. Sign in and open **Webhooks**. Search the event ID; filter received/failed events and inspect their error and attempt counter.
3. Ensure the payment exists and its Stripe identifiers/metadata match. An unmatched event needs reconciliation; Stripe redelivery can resolve it after the matching payment is available.
4. Use **Retry payment** or the payment detail retry action. The backend selects the oldest associated received/failed event. No stored event returns 422; retry does not turn an unpaid payment into success.
5. Refresh records to inspect the new state. A completed but unpaid Checkout Session may correctly remain processing until a success event arrives. Ignored late events must preserve succeeded/refunded state.

Stripe handles automatic redelivery. The application does not schedule a background retry queue.

## Request a test refund

Open a succeeded Stripe test payment, wait for detail eligibility to load, and choose **Refund test payment**. Confirm the full displayed amount/currency. Submission is disabled while pending. Review the returned status and refresh after `charge.refunded` or `refund.updated`; pending refunds may need a later Stripe event. Live keys, local simulations, and ineligible payments are rejected.

## Authentication ended

401/403 responses clear the dashboard's private records. Sign in again with a provisioned administrator. Tokens live only in memory and expire after one hour. Reload also requires sign in. If role/ability is missing, correct the administrator provisioning on the server; there is no browser role editor.

## Manual Stripe test-mode walkthrough

1. Start the API/frontend using the README and provision an administrator.
2. Run `stripe listen --forward-to 127.0.0.1:8000/api/webhooks/stripe`; copy its signing secret to the backend environment and restart the API.
3. Configure a valid Stripe test secret, create a Checkout Session, and complete hosted Checkout with `4242 4242 4242 4242`.
4. Verify the return page and signed event log; refresh the admin records and confirm succeeded.
5. Open details, submit a full test refund, and verify refunded after Stripe confirmation.
6. Cancel another Checkout and verify the cancelled page does not report payment success.

Record date, environment, test session/event IDs, and outcome when performing this walkthrough. Automated CI uses fake Stripe gateways and mocked browser responses, so it does not establish that this manual workflow was executed.

## Repeatable verification

Run the build and Playwright commands in the README, the feature suite, and the [PostgreSQL integration commands](../backend/README.md#postgresql-integration-tests). CI checks SQLite/PostgreSQL 16 and independent worker races inside the production image. Use only disposable test databases; the feature suite rebuilds their schema.
