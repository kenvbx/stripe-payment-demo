# Portfolio evidence

> **Personal Project / Demo Project** — Stripe Payment Demo

## What this demonstrates

This project demonstrates a test-mode payment flow with a React/Vite operations console and Laravel API. The implementation covers Stripe Checkout Session creation, server-side idempotency, signed webhook verification, duplicate-event handling, transaction state transitions, and retry recovery.

![Payment operations dashboard](./dashboard-overview.png)

## Reviewer walkthrough

1. Start the Laravel API and Vite frontend using the commands in the root README.
2. Open **Overview** and choose **Create checkout**.
3. Select a demo product. With test credentials configured, the browser redirects to Stripe-hosted Checkout.
4. Use Stripe test card `4242 4242 4242 4242`, any future expiry and any CVC.
5. Forward events with Stripe CLI and return to the dashboard to inspect `succeeded`, `failed`, `pending`, and `processed` states.
6. Open **Webhooks** to inspect delivery state and retry a pending event.
7. Open **API explorer** to review the server-side PaymentIntent/idempotency contract.

## Evidence map

| Area | Evidence in repository |
| --- | --- |
| Checkout creation | `backend/app/Http/Controllers/Api/PaymentController.php` |
| Checkout Session persistence | `backend/database/migrations/2026_11_10_000002_add_checkout_fields_to_payments_table.php` |
| Signature verification | `backend/app/Http/Controllers/Api/StripeWebhookController.php` |
| Duplicate event protection | `backend/app/Http/Controllers/Api/StripeWebhookController.php` and `backend/tests/Feature/PaymentApiTest.php` |
| Idempotent checkout creation | `backend/tests/Feature/PaymentApiTest.php` |
| Retry and failure recovery | `backend/app/Http/Controllers/Api/PaymentController.php` |
| Responsive interface | `src/main.jsx` and `src/styles.css` |
| Reproducible deployment | `DEPLOYMENT.md`, `render.yaml`, `backend/Dockerfile`, `vercel.json` |
| Automated verification | `.github/workflows/ci.yml` |

## Architecture flow

```mermaid
flowchart LR
  Browser[React/Vite dashboard] -->|POST /api/payments/checkout| API[Laravel API]
  API -->|Checkout Session, test mode| Stripe[Stripe Checkout]
  Stripe -->|redirect| Browser
  Stripe -->|signed events| Webhook[/api/webhooks/stripe]
  Webhook -->|verify + lock + dedupe| DB[(Payments database)]
  Browser -->|GET payments / retry webhook| API
```

## Honest scope

This is a personal demo. It uses Stripe test mode only and does not claim production payment volume, real customer revenue, compliance certification, or live operational availability. The Render demo configuration uses SQLite with ephemeral storage; persistent deployment should use managed PostgreSQL.
