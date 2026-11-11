# API contract

Base URL: `/api`. Send `Accept: application/json`; POST bodies use `Content-Type: application/json`. Monetary amounts are integer minor units, with an explicit ISO currency code.

## Routes

| Method and route | Access | Behavior |
| --- | --- | --- |
| `GET /products` | Public | Active database catalog. |
| `POST /payments/checkout` | Public, rate limited | Reserve/recover a Checkout Session. |
| `GET /checkout/sessions/{sessionId}` | Public, rate limited | Limited result summary for a known session, without customer identity. |
| `POST /webhooks/stripe` | Stripe signature | Verify and process signed test events. |
| `POST /admin/login` | Public, login rate limit | Email/password → one-hour bearer token. |
| `GET /admin/me` | Admin | Current administrator. |
| `POST /admin/logout` | Admin | Revoke current token. |
| `GET /payments` | Admin | Paginated payment records. |
| `GET /payments/{id}` | Admin | Payment, webhook history, refund eligibility. |
| `GET /webhook-events` | Admin | Paginated stored event log. |
| `POST /payments/{id}/retry-webhook` | Admin | Replay oldest associated received/failed verified event. |
| `POST /payments/{id}/refund` | Admin | Full refund for an eligible succeeded Stripe test payment. |

Admin routes require `Authorization: Bearer YOUR_TOKEN`, persisted role `admin`, and token ability `admin`. 401 means authentication ended; 403 means insufficient access. Lists return Laravel pagination fields including `data`, `current_page`, and `last_page`. Request `?page=2&per_page=100`; page size is capped at 100.

## Create or recover Checkout

```sh
curl http://127.0.0.1:8000/api/payments/checkout \
  -X POST \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"product_id":1,"customer_name":"Demo customer","customer_email":"demo@example.com","idempotency_key":"checkout-example-1042"}'
```

Use an active product ID from the catalog. Name is required (max 120), email is required (max 255), and the idempotency key is 8–120 characters. Supplied product names/prices are ignored.

201 means the initial creation completed; 200 means replay/recovery. The response includes `data` (payment), `checkout_url`, `checkout_mode` (`stripe` or `demo`), and `idempotent_replay`. A demo response has no hosted URL. Succeeded/refunded payments return no top-level Checkout redirect URL. Internal snapshots and claim tokens are hidden.

For retryable errors, retain the exact input/key and wait at least the `Retry-After` seconds. Do not generate a new key after an ambiguous network failure.

| Code/status | Client action |
| --- | --- |
| `IDEMPOTENCY_CONFLICT` / 409 | Different input or legacy attempt. Reconcile the original attempt; never blindly replace an ambiguous key. |
| `CHECKOUT_IN_PROGRESS` / 409 | Retry the same request after the indicated delay. |
| `CHECKOUT_EXPIRED` / 409 | Existing session expired; review the payment before starting a new attempt. |
| `CHECKOUT_RETRY_EXPIRED` / 409 | Safe recovery window ended. Administrator reconciliation required. |
| `STRIPE_TEST_MODE_REQUIRED` / 422 | Configure a test secret on the server. |
| `CHECKOUT_REJECTED` / 422 | Administrator must inspect product/Stripe configuration. |
| `CHECKOUT_UNAVAILABLE` or `CHECKOUT_RECOVERY_REQUIRED` / 503 | Retry the same request when `retryable` is true. |
| `STRIPE_CONFIGURATION_ERROR` / 503 | Administrator must fix credentials. |

Service errors include `message`, `error_code`, and `retryable`. Retryable service errors include `Retry-After: 5`; framework validation errors return 422 with `message` and `errors`. Rate limiting returns 429.

## Webhooks and refunds

Subscribe to `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `payment_intent.succeeded`, `payment_intent.processing`, `payment_intent.payment_failed`, `charge.refunded`, and `refund.updated`.

A missing, empty, or whitespace-only webhook signing secret disables processing and returns 503 with no event/payment writes. Stripe can retry after the server configuration is corrected. With a configured secret, an invalid signature returns 400. Unsupported/regressive events are acknowledged as ignored. Supported unmatched events return 500 and remain eligible for redelivery. Manual payment retry requires a stored event; it returns 422 when none exists and 503 if processing fails. The operation retries one event, not an arbitrarily selected event row.

Refund eligibility comes from the detail endpoint's `refund_available`. Confirm the full amount/currency before POST. Inspect `refund_status` and verified events after submission; never infer payment/refund success from a UI action alone.
