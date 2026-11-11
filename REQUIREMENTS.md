# Requirements and acceptance criteria

## Supported workflows

| Workflow | Acceptance criteria |
| --- | --- |
| Local review | Explicit simulation mode works without backend credentials and labels its sample activity. |
| Catalog | API mode uses active database products; loading/failure/empty states are visible and catalog retry is available. Server-side pricing overrides client values. |
| Checkout | Disable duplicate submissions, retain the same pending key on failure/reload, redirect to hosted test Checkout only after confirmation, and show API errors without creating a local payment. |
| Recovery | Same input/key reuses payment and immutable Stripe parameters; conflicting input returns 409. Active claims, lease expiry, save failure, and the safe retry window are covered. |
| Return page | Render cancelled, awaiting confirmation, succeeded, failed/expired, refunded, missing-session and API-error states; refresh reads state without marking payment successful. |
| Admin access | Sign in/out, in-memory token, one-hour expiry, and clearing private data on 401/403. No public registration/default administrator. |
| Payments | Search/filter loaded records, load additional pages, refresh, inspect details/timeline/events, format the record currency, and show empty/error states. |
| Webhooks | Verify signatures, deduplicate events, preserve terminal payment state, inspect delivery attempts/errors, filter/search loaded events, and replay one stored event via its payment. |
| Refunds | Only eligible succeeded Stripe test payments expose full refund. Confirmation is required; submission is disabled while pending. Backend rejects live mode. |
| Overview | Compute summaries from loaded records and total currencies separately. No invented growth rates, scheduled retries, or global success metrics. |
| Developer tools | Copy the Laravel Checkout request; Settings is read-only with a deployment link. Documentation links lead to repository guides. |
| Responsive access | Navigation opens/closes with buttons and Escape on mobile; dialogs trap/restore focus. Visible focus, labelled controls, text status, reduced motion, and layouts at 375/768/1024/1440px. |

## Verification

CI must pass frontend production build, browser E2E checks, the backend feature suite on SQLite/PostgreSQL 16, and deployment-image smoke/concurrent Checkout checks against PostgreSQL. Tests use fake Stripe transports and mocked browser API responses; real test-mode Stripe verification is a separate manual workflow documented in the runbook.

## Current limits

Search/metrics operate on loaded pages. Payment details are navigated within the workspace, without persistent detail URLs. The demo has full test refunds, not partial refunds or subscriptions. There is no automatic application retry queue, global analytics service, live-mode toggle, or browser secret editor. Deployment recipes do not imply a currently available public demo.
