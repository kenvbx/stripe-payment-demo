import { useState } from "react";
import {
  Activity,
  ArrowUpRight,
  Check,
  ChevronRight,
  Copy,
  CreditCard,
  FileText,
  RefreshCw,
  ShieldCheck,
  Webhook,
} from "lucide-react";

export const DOCS_URL =
  "https://github.com/kenvbx/stripe-payment-demo/blob/main/";
export const formatMoney = (cents, currency = "usd") =>
  new Intl.NumberFormat("en-US", {
    style: "currency",
    currency: currency.toUpperCase(),
  }).format(cents / 100);
const currencies = (payments) =>
  Object.entries(
    payments.reduce((totals, payment) => {
      const currency = payment.currency || "usd";
      totals[currency] = (totals[currency] || 0) + payment.amount;
      return totals;
    }, {}),
  );

export function Overview({
  payments,
  onCheckout,
  onViewPayments,
  children,
  apiEnabled,
}) {
  const succeeded = payments.filter((p) => p.status === "succeeded");
  const pending = payments.filter((p) =>
    ["created", "processing"].includes(p.status),
  );
  const metrics = [
    [
      "Successful payments",
      succeeded.length,
      "Confirmed payments in loaded records",
      "mint",
    ],
    ["Awaiting payment", pending.length, "Created or processing", "amber"],
    [
      "Refunded payments",
      payments.filter((p) => p.status === "refunded").length,
      "Confirmed full refunds",
      "violet",
    ],
    [
      "Needs webhook review",
      payments.filter((p) =>
        ["failed", "retrying", "pending"].includes(p.webhook),
      ).length,
      "Review the stored event log",
      "blue",
    ],
  ];
  return (
    <>
      <section className="hero">
        <div>
          <p className="eyebrow">
            PAYMENT OPERATIONS ·{" "}
            {apiEnabled ? "API WORKSPACE" : "LOCAL SIMULATION"}
          </p>
          <h1>Payment workspace</h1>
          <p className="sub">
            Inspect test payments, recover deliveries, and follow every
            checkout.
          </p>
        </div>
        <button className="primary" onClick={onCheckout}>
          <CreditCard size={17} /> Create checkout
        </button>
      </section>
      <div className="stat-grid">
        {metrics.map(([label, value, detail, tone]) => (
          <div className="card stat" key={label}>
            <div className={`stat-icon ${tone}`}>
              <Activity size={17} />
            </div>
            <div>
              <span>{label}</span>
              <strong>{value}</strong>
              <small>{detail}</small>
            </div>
          </div>
        ))}
      </div>
      <div className="grid-two">
        <section className="card chart-card">
          <div className="card-head">
            <div>
              <h2>Successful test volume</h2>
              <p>Loaded records · currencies shown separately</p>
            </div>
            <ArrowUpRight size={20} />
          </div>
          <div className="volume-list">
            {currencies(succeeded).length ? (
              currencies(succeeded).map(([currency, amount]) => (
                <div key={currency}>
                  <span>{currency.toUpperCase()}</span>
                  <strong>{formatMoney(amount, currency)}</strong>
                </div>
              ))
            ) : (
              <p className="empty-state">
                No successful payments to total yet.
              </p>
            )}
          </div>
          <p className="scope-note">
            Refunded payments are excluded. Load more payments to expand this
            view.
          </p>
        </section>
        <section className="card activity-card">
          <div className="card-head">
            <div>
              <h2>Recent activity</h2>
              <p>Latest loaded payment records</p>
            </div>
            <button className="text-btn" onClick={onViewPayments}>
              View all <ChevronRight size={14} />
            </button>
          </div>
          <div className="activity-list">
            {payments.length ? (
              payments.slice(0, 4).map((p) => (
                <div className="activity" key={p.id}>
                  <div className={`event-icon ${p.status}`}>
                    <Activity size={15} />
                  </div>
                  <div className="activity-copy">
                    <strong>{p.customer}</strong>
                    <span>
                      {p.product} · {formatMoney(p.amount, p.currency)}
                    </span>
                  </div>
                  <span className={`status ${p.status}`}>{p.status}</span>
                </div>
              ))
            ) : (
              <p className="empty-state">
                No payments loaded.{" "}
                {apiEnabled
                  ? "Sign in as an administrator to inspect payment history."
                  : "Create a checkout to see it here."}
              </p>
            )}
          </div>
        </section>
      </div>
      <section className="card table-card">
        <div className="card-head">
          <div>
            <h2>Transaction history</h2>
            <p>Follow checkout and webhook progress.</p>
          </div>
          <button className="outline" onClick={onViewPayments}>
            Open payments <ChevronRight size={14} />
          </button>
        </div>
        {children}
      </section>
    </>
  );
}

export function ApiExplorer({ apiBase, notify }) {
  const request = `curl '${apiBase.startsWith("http") ? apiBase : `http://127.0.0.1:8000${apiBase}`}/payments/checkout' \\\n  -X POST \\\n  -H 'Accept: application/json' \\\n  -H 'Content-Type: application/json' \\\n  -d '{"product_id":1,"customer_name":"Demo customer","customer_email":"demo@example.com","idempotency_key":"checkout-example-1042"}'`;
  async function copy() {
    try {
      await navigator.clipboard.writeText(request);
      notify("Request copied to clipboard");
    } catch {
      notify("Clipboard unavailable. Select and copy the request below.");
    }
  }
  return (
    <>
      <section className="page-title">
        <div>
          <p className="eyebrow">DEVELOPER TOOLS</p>
          <h1>API explorer</h1>
          <p className="sub">
            Create a Checkout Session through Laravel. Use an active product ID
            from the catalog.
          </p>
        </div>
      </section>
      <div className="api-grid">
        <section className="card code-card">
          <div className="code-head">
            <span>POST</span>
            <strong>/api/payments/checkout</strong>
            <button
              className="row-action"
              onClick={copy}
              aria-label="Copy checkout request"
            >
              <Copy size={16} />
            </button>
          </div>
          <pre tabIndex={0}>{request}</pre>
          <div className="code-foot">
            <span>
              <ShieldCheck size={14} /> Secrets stay on the server
            </span>
            <span>Same body + key for recovery</span>
          </div>
        </section>
        <section className="card explain">
          <h2>Response contract</h2>
          <ul>
            <li>
              <Check size={15} /> 201: session created; redirect to
              checkout_url.
            </li>
            <li>
              <Check size={15} /> 200: existing checkout recovered.
            </li>
            <li>
              <Check size={15} /> 409: input conflict, active processing, or
              expired retry window.
            </li>
            <li>
              <Check size={15} /> 422: invalid input or test configuration.
            </li>
            <li>
              <Check size={15} /> 503: inspect retryable and retry the same
              request when allowed.
            </li>
          </ul>
          <a
            className="outline doc-link"
            href={`${DOCS_URL}docs/API.md`}
            target="_blank"
            rel="noreferrer"
          >
            Read the API contract <FileText size={15} />
          </a>
        </section>
      </div>
    </>
  );
}

export function Settings({ apiBase, apiEnabled, apiConnected }) {
  return (
    <>
      <section className="page-title">
        <div>
          <p className="eyebrow">WORKSPACE</p>
          <h1>Settings</h1>
          <p className="sub">
            Read-only configuration. Update environment variables and redeploy
            to change these values.
          </p>
        </div>
      </section>
      <div className="settings-grid">
        <section className="card settings-card">
          <h2>Environment</h2>
          <label>
            Payment mode
            <input readOnly value="Stripe test mode only" />
          </label>
          <label>
            Data source
            <input
              readOnly
              value={
                apiEnabled
                  ? apiConnected
                    ? "Laravel API connected"
                    : "Laravel API · connection unavailable"
                  : "Local simulation"
              }
            />
          </label>
          <label>
            API base URL
            <input readOnly value={apiBase} />
          </label>
          <label>
            Webhook endpoint
            <input readOnly value={`${apiBase}/webhooks/stripe`} />
          </label>
          <a
            className="outline doc-link"
            href={`${DOCS_URL}DEPLOYMENT.md`}
            target="_blank"
            rel="noreferrer"
          >
            Deployment guide <FileText size={15} />
          </a>
        </section>
        <section className="card safety-card">
          <h2>Test workspace</h2>
          <p>
            Use Stripe test cards in hosted Checkout. The app does not offer a
            live-mode switch.
          </p>
          <div className="safety-row">
            <Check size={15} /> Server-side product pricing
          </div>
          <div className="safety-row">
            <Check size={15} /> Signed webhook verification
          </div>
          <div className="safety-row">
            <Check size={15} /> Admin authentication for payment records
          </div>
          <div className="safety-row">
            <Check size={15} /> No secret keys in browser configuration
          </div>
        </section>
      </div>
    </>
  );
}

export function Webhooks({
  payments,
  webhookEvents,
  apiEnabled,
  onRetry,
  pendingAction,
  onSettings,
}) {
  const [filter, setFilter] = useState("all");
  const [query, setQuery] = useState("");
  const events = apiEnabled
    ? webhookEvents
    : payments.map((p) => ({
        ...p,
        paymentId: p.backendId,
        event: "Sample payment activity",
        attempts: 0,
      }));
  const visible = events.filter(
    (e) =>
      (filter === "all" || e.webhook === filter) &&
      `${e.id} ${e.event} ${e.paymentName || e.customer || ""}`
        .toLowerCase()
        .includes(query.toLowerCase()),
  );
  return (
    <>
      <section className="page-title">
        <div>
          <p className="eyebrow">EVENT DELIVERY</p>
          <h1>Webhook health</h1>
          <p className="sub">
            {apiEnabled
              ? "Verified deliveries stored by Laravel."
              : "Sample activity only. Connect the API to inspect signed events."}
          </p>
        </div>
        <button className="primary" onClick={onSettings}>
          <Webhook size={16} /> Endpoint settings
        </button>
      </section>
      <div className="webhook-summary">
        {[
          ["Loaded events", events.length],
          [
            "Processed events",
            events.filter((e) => e.webhook === "processed").length,
          ],
          [
            "Needs attention",
            events.filter((e) =>
              ["failed", "received", "retrying"].includes(e.webhook),
            ).length,
          ],
        ].map(([label, value]) => (
          <div className="card summary" key={label}>
            <span className="summary-icon mint">
              <Webhook size={17} />
            </span>
            <div>
              <strong>{label}</strong>
              <span>Current loaded records</span>
            </div>
            <b>{value}</b>
          </div>
        ))}
      </div>
      <section className="card table-card">
        <div className="card-head">
          <div>
            <h2>Event delivery log</h2>
            <p>Filter by delivery state or search an event ID.</p>
          </div>
          <a
            className="outline doc-link"
            href={`${DOCS_URL}docs/RUNBOOK.md`}
            target="_blank"
            rel="noreferrer"
          >
            <FileText size={14} /> View runbook
          </a>
        </div>
        <div className="toolbar">
          <label className="search">
            <span className="sr-only">Search webhook events</span>
            <input
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="Search events"
            />
          </label>
          <label className="event-filter">
            Delivery status
            <select value={filter} onChange={(e) => setFilter(e.target.value)}>
              {[
                "all",
                "received",
                "processed",
                "ignored",
                "failed",
                "retrying",
              ].map((status) => (
                <option key={status}>{status}</option>
              ))}
            </select>
          </label>
        </div>
        <div className="event-log">
          {visible.length ? (
            visible.map((e) => {
              const payment = payments.find((p) => p.backendId === e.paymentId);
              return (
                <div className="log-row" key={e.id}>
                  <div className={`event-dot ${e.webhook}`} />
                  <div className="log-main">
                    <strong>{e.event}</strong>
                    <span>
                      {e.id} · {e.created}
                      {e.paymentName ? ` · ${e.paymentName}` : ""}
                    </span>
                    {e.lastError && (
                      <small className="event-error">{e.lastError}</small>
                    )}
                  </div>
                  <span className={`webhook ${e.webhook}`}>
                    <i />
                    {e.webhook}
                  </span>
                  <span className="latency">{e.attempts} attempts</span>
                  {apiEnabled &&
                    payment &&
                    ["received", "failed"].includes(e.webhook) && (
                      <button
                        className="outline"
                        disabled={Boolean(pendingAction)}
                        onClick={() => onRetry(payment.id)}
                        aria-label={`Retry payment ${payment.backendId} webhook`}
                      >
                        <RefreshCw size={15} /> Retry payment
                      </button>
                    )}
                </div>
              );
            })
          ) : (
            <p className="empty-state">
              {events.length
                ? "No events match these filters."
                : "No verified webhook events loaded yet."}
            </p>
          )}
        </div>
      </section>
    </>
  );
}
