import React, { useEffect, useMemo, useRef, useState } from "react";
import { createRoot } from "react-dom/client";
import {
  ArrowDownLeft,
  ArrowUpRight,
  Check,
  ChevronRight,
  CircleHelp,
  Clock3,
  Code2,
  CreditCard,
  LayoutDashboard,
  Menu,
  Package,
  RefreshCw,
  Search,
  Settings2,
  ShieldCheck,
  Webhook,
  X,
  Zap,
} from "lucide-react";
import "./styles.css";
import AdminLoginModal from "./AdminLoginModal";
import CheckoutModal from "./CheckoutModal";
import { checkoutKey, finishCheckout } from "./checkoutAttempts";
import {
  Overview,
  Webhooks,
  Settings,
  ApiExplorer,
  DOCS_URL,
} from "./WorkspacePages";
import useDialogFocus from "./useDialogFocus";

const refundMessages = {
  pending: "Refund submitted; waiting for Stripe confirmation",
  requires_action: "Refund requires action; review it in the Stripe Dashboard",
  failed: "Refund failed; review it in the Stripe Dashboard before another attempt",
  canceled: "Refund canceled; review it in the Stripe Dashboard before another attempt",
  succeeded: "Test refund completed",
};

const initialPayments = [
  {
    id: "pi_3Qx9k7J2",
    customer: "Maya Chen",
    email: "maya@northstar.studio",
    product: "Pro workspace",
    amount: 8900,
    status: "succeeded",
    method: "Visa •••• 4242",
    created: "Today, 10:42",
    webhook: "processed",
  },
  {
    id: "pi_3Qx8b1F4",
    customer: "Theo Martin",
    email: "theo@orbitlabs.io",
    product: "Starter plan",
    amount: 2900,
    status: "processing",
    method: "Card",
    created: "Today, 09:17",
    webhook: "pending",
  },
  {
    id: "pi_3Qx7m4P8",
    customer: "Ari Santos",
    email: "ari@madebyari.co",
    product: "Pro workspace",
    amount: 8900,
    status: "failed",
    method: "Card •••• 0005",
    created: "Yesterday, 16:08",
    webhook: "retrying",
  },
  {
    id: "pi_3Qx6r2N1",
    customer: "Noah Williams",
    email: "noah@framekit.dev",
    product: "Team plan",
    amount: 14900,
    status: "succeeded",
    method: "Mastercard •••• 4444",
    created: "Yesterday, 13:21",
    webhook: "processed",
  },
  {
    id: "pi_3Qx5a8C2",
    customer: "Lena Park",
    email: "lena@lumenworks.co",
    product: "Starter plan",
    amount: 2900,
    status: "refunded",
    method: "Visa •••• 4242",
    created: "Nov 08, 11:02",
    webhook: "processed",
  },
];
const demoProducts = [
  {
    id: 1,
    name: "Starter plan",
    detail: "For trying the flow",
    amount: 2900,
    currency: "usd",
    color: "mint",
  },
  {
    id: 2,
    name: "Pro workspace",
    detail: "For growing teams",
    amount: 8900,
    currency: "usd",
    color: "violet",
  },
  {
    id: 3,
    name: "Team plan",
    detail: "For larger operations",
    amount: 14900,
    currency: "usd",
    color: "amber",
  },
];
const API_BASE = (import.meta.env.VITE_API_URL || "/api").replace(/\/$/, "");
const API_ENABLED = import.meta.env.VITE_USE_API !== "false";
const money = (cents, currency = "usd") =>
  new Intl.NumberFormat("en-US", {
    style: "currency",
    currency: currency.toUpperCase(),
  }).format(cents / 100);
function normalizeProduct(product, index = 0) {
  return {
    id: product.id,
    name: product.name,
    detail: product.description,
    amount: Number(product.price_cents),
    currency: product.currency || "usd",
    color: ["mint", "violet", "amber"][index % 3],
  };
}
function normalizePayment(payment) {
  return {
    id: payment.stripe_payment_intent_id || `payment_${payment.id}`,
    customer: payment.customer_name,
    email: payment.customer_email,
    product: payment.product_name,
    amount: Number(payment.amount),
    status: payment.status,
    method: payment.payment_method || "Test card",
    created: payment.created_at
      ? new Date(payment.created_at).toLocaleString([], {
          dateStyle: "medium",
          timeStyle: "short",
        })
      : "Just now",
    webhook: payment.webhook_status || "pending",
    backendId: payment.id,
    paymentIntentId: payment.stripe_payment_intent_id,
    checkoutSessionId: payment.stripe_checkout_session_id,
    idempotencyKey: payment.idempotency_key,
    eventId: payment.stripe_event_id,
    paidAt: payment.paid_at ? new Date(payment.paid_at).toLocaleString() : null,
    lastError: payment.last_error,
    refundId: payment.stripe_refund_id,
    refundStatus: payment.refund_status,
    refundedAt: payment.refunded_at
      ? new Date(payment.refunded_at).toLocaleString()
      : null,
    refundAvailable: Boolean(payment.refund_available),
    webhookEvents: (payment.webhook_events || []).map(normalizeWebhookEvent),
    currency: payment.currency,
    checkoutUrl: payment.checkout_url,
  };
}
function normalizeWebhookEvent(event) {
  return {
    id: event.event_id,
    event: event.type,
    webhook: event.status,
    attempts: Number(event.attempts || 0),
    stripeObjectId: event.stripe_object_id,
    payloadHash: event.payload_hash,
    lastError: event.last_error,
    created: event.received_at
      ? new Date(event.received_at).toLocaleString([], {
          dateStyle: "medium",
          timeStyle: "short",
        })
      : "—",
    paymentId: event.payment_id,
    paymentName: event.payment?.customer_name || "Unmatched Stripe event",
    product: event.payment?.product_name || "—",
  };
}
let adminAccessToken = null;
async function apiRequest(path, options = {}) {
  const requestToken = path === "/admin/login" ? null : adminAccessToken;
  const response = await fetch(`${API_BASE}${path}`, {
    ...options,
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      ...(requestToken ? { Authorization: `Bearer ${requestToken}` } : {}),
      ...(options.headers || {}),
    },
  });
  if (
    [401, 403].includes(response.status) &&
    requestToken &&
    requestToken === adminAccessToken
  ) {
    adminAccessToken = null;
    window.dispatchEvent(new Event("admin-session-expired"));
  }
  const data = await response.json().catch(() => ({}));
  if (requestToken && requestToken !== adminAccessToken)
    throw new Error("Admin session ended. Please sign in again.");
  if (!response.ok) {
    const detail = data.errors ? Object.values(data.errors).flat()[0] : null;
    const error = new Error(
      detail || data.message || `API request failed (${response.status})`,
    );
    error.code = data.error_code;
    error.restartAllowed = data.restart_allowed === true;
    throw error;
  }
  return data;
}

function App() {
  const [mobileOpen, setMobileOpen] = useState(false);
  const sidebar = useRef(null);
  useDialogFocus(sidebar, () => setMobileOpen(false), false, mobileOpen);
  const [catalogLoading, setCatalogLoading] = useState(API_ENABLED);
  const [catalogError, setCatalogError] = useState("");
  const [dataError, setDataError] = useState("");
  const [detailLoading, setDetailLoading] = useState(false);
  const [loadingRecords, setLoadingRecords] = useState(false);
  const [nextPages, setNextPages] = useState({ payments: null, events: null });
  const [pendingAction, setPendingAction] = useState(null);
  const actionInFlight = useRef(false);
  const detailRequest = useRef(0);
  const toastTimer = useRef(null);
  const [adminSession, setAdminSession] = useState(null);
  const [signingOut, setSigningOut] = useState(false);
  const [checkoutSubmitting, setCheckoutSubmitting] = useState(false);
  const [checkoutError, setCheckoutError] = useState("");
  const [checkoutRestart, setCheckoutRestart] = useState(null);
  const checkoutInFlight = useRef(false);
  function clearAdminSession() {
    detailRequest.current++;
    setDetailLoading(false);
    setDataError("");
    setNextPages({ payments: null, events: null });
    adminAccessToken = null;
    setAdminSession(null);
    setPayments(API_ENABLED ? [] : initialPayments);
    setWebhookEvents([]);
    setSelectedPayment(null);
    setTab("Overview");
  }
  useEffect(() => {
    const expire = () => {
      clearAdminSession();
      setToast("Admin session expired. Please sign in again.");
    };
    window.addEventListener("admin-session-expired", expire);
    return () => window.removeEventListener("admin-session-expired", expire);
  }, []);
  useEffect(() => {
    if (!adminSession) return;
    const timeout = window.setTimeout(
      () => window.dispatchEvent(new Event("admin-session-expired")),
      Math.max(0, new Date(adminSession.expiresAt).getTime() - Date.now()),
    );
    return () => window.clearTimeout(timeout);
  }, [adminSession]);
  async function loginAdmin(email, password) {
    const response = await apiRequest("/admin/login", {
      method: "POST",
      body: JSON.stringify({ email, password }),
    });
    adminAccessToken = response.token;
    setAdminSession({ user: response.user, expiresAt: response.expires_at });
    setModal(null);
    notify("Signed in as administrator");
  }
  async function logoutAdmin() {
    setSigningOut(true);
    try {
      await apiRequest("/admin/logout", { method: "POST" });
      clearAdminSession();
      notify("Signed out");
    } catch (error) {
      notify(error.message || "Sign out failed. Please try again.");
    } finally {
      setSigningOut(false);
    }
  }
  const [tab, setTab] = useState("Overview");
  const [payments, setPayments] = useState(API_ENABLED ? [] : initialPayments);
  const [products, setProducts] = useState(API_ENABLED ? [] : demoProducts);
  const [filter, setFilter] = useState("all");
  const [query, setQuery] = useState("");
  const [modal, setModal] = useState(null);
  const [toast, setToast] = useState("");
  const [apiConnected, setApiConnected] = useState(false);
  const [selectedPayment, setSelectedPayment] = useState(null);
  const [webhookEvents, setWebhookEvents] = useState([]);
  const [checkoutResult, setCheckoutResult] = useState(null);
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const outcome = params.get("checkout");
    if (outcome === "cancelled") setCheckoutResult({ kind: "cancelled" });
    if (outcome === "success") {
      const sessionId = params.get("session_id");
      setCheckoutResult({
        kind: "success",
        sessionId,
        loading: Boolean(sessionId && API_ENABLED),
        error: !sessionId
          ? "Missing Checkout Session ID. Return to the dashboard to review your payment."
          : !API_ENABLED
            ? "Enable the Laravel API to confirm this checkout session."
            : null,
      });
      if (sessionId && API_ENABLED) {
        apiRequest(`/checkout/sessions/${encodeURIComponent(sessionId)}`)
          .then((response) =>
            setCheckoutResult((current) =>
              current
                ? { ...current, loading: false, data: response.data }
                : current,
            ),
          )
          .catch((error) =>
            setCheckoutResult((current) =>
              current
                ? { ...current, loading: false, error: error.message }
                : current,
            ),
          );
      }
    }
  }, []);
  async function loadCatalog() {
    setCatalogLoading(true);
    setCatalogError("");
    try {
      const response = await apiRequest("/products");
      setProducts((response.data || []).map(normalizeProduct));
      setApiConnected(true);
    } catch (error) {
      setCatalogError(error.message || "Could not load products.");
      setApiConnected(false);
    } finally {
      setCatalogLoading(false);
    }
  }
  async function loadRecords(target = "all", append = false) {
    if (!adminAccessToken) return;
    const token = adminAccessToken;
    setLoadingRecords(true);
    setDataError("");
    const targets = target === "all" ? ["payments", "events"] : [target];
    await Promise.all(
      targets.map(async (kind) => {
        const page = append ? nextPages[kind] : 1;
        if (!page) return;
        try {
          const response = await apiRequest(
            `/${kind === "events" ? "webhook-events" : "payments"}?per_page=100&page=${page}`,
          );
          if (adminAccessToken !== token) return;
          const records = (response.data || []).map(
            kind === "events" ? normalizeWebhookEvent : normalizePayment,
          );
          const setter = kind === "events" ? setWebhookEvents : setPayments;
          setter((current) =>
            append
              ? [
                  ...current.filter(
                    (item) => !records.some((record) => record.id === item.id),
                  ),
                  ...records,
                ]
              : records,
          );
          setNextPages((current) => ({
            ...current,
            [kind]:
              response.current_page < response.last_page
                ? response.current_page + 1
                : null,
          }));
          setApiConnected(true);
        } catch (error) {
          if (adminAccessToken === token)
            setDataError(error.message || "Could not refresh records.");
        }
      }),
    );
    if (adminAccessToken === token) setLoadingRecords(false);
  }
  useEffect(() => {
    if (API_ENABLED) loadCatalog();
  }, []);
  useEffect(() => {
    if (adminSession) loadRecords();
    else setLoadingRecords(false);
  }, [adminSession]);
  useEffect(() => () => window.clearTimeout(toastTimer.current), []);
  useEffect(() => {
    const desktop = window.matchMedia("(min-width: 761px)");
    const closeDrawer = () => {
      if (desktop.matches) setMobileOpen(false);
    };
    desktop.addEventListener("change", closeDrawer);
    return () => desktop.removeEventListener("change", closeDrawer);
  }, []);
  function navigate(next) {
    detailRequest.current++;
    setDetailLoading(false);
    setMobileOpen(false);
    setCheckoutResult(null);
    if (checkoutResult)
      window.history.replaceState({}, "", window.location.pathname);
    setTab(next);
  }
  const visible = useMemo(
    () =>
      payments.filter(
        (p) =>
          (filter === "all" || p.status === filter) &&
          `${p.customer} ${p.email} ${p.id}`
            .toLowerCase()
            .includes(query.toLowerCase()),
      ),
    [payments, filter, query],
  );
  function notify(message) {
    window.clearTimeout(toastTimer.current);
    setToast(message);
    toastTimer.current = window.setTimeout(() => setToast(""), 5000);
  }
  async function refreshCheckoutResult() {
    if (!checkoutResult?.sessionId) return;
    setCheckoutResult((current) =>
      current ? { ...current, loading: true, error: null } : current,
    );
    try {
      const response = await apiRequest(
        `/checkout/sessions/${encodeURIComponent(checkoutResult.sessionId)}`,
      );
      setCheckoutResult((current) =>
        current ? { ...current, loading: false, data: response.data } : current,
      );
    } catch (error) {
      setCheckoutResult((current) =>
        current
          ? { ...current, loading: false, error: error.message }
          : current,
      );
    }
  }
  function closeCheckoutResult() {
    setCheckoutResult(null);
    window.history.replaceState({}, "", window.location.pathname);
    setTab("Overview");
  }
  async function openPayment(payment) {
    const request = ++detailRequest.current;
    setDataError("");
    setSelectedPayment(payment);
    setTab("Payment detail");
    if (API_ENABLED && payment.backendId) {
      setDetailLoading(true);
      try {
        const response = await apiRequest(`/payments/${payment.backendId}`);
        if (detailRequest.current === request)
          setSelectedPayment(normalizePayment(response.data));
        setApiConnected(true);
      } catch (error) {
        if (detailRequest.current === request && adminAccessToken)
          setDataError(error.message || "Could not load payment details.");
      } finally {
        if (detailRequest.current === request) setDetailLoading(false);
      }
    }
  }
  function restartCheckout(product) {
    if (checkoutInFlight.current || checkoutRestart?.productId !== product.id) return;
    // Retire only the verified expired attempt; the new key survives any ambiguous failure.
    finishCheckout(product.id, checkoutRestart.key);
    simulateCheckout(product);
  }
  async function simulateCheckout(product) {
    if (checkoutInFlight.current) return;
    checkoutInFlight.current = true;
    setCheckoutSubmitting(true);
    setCheckoutError("");
    setCheckoutRestart(null);
    let attemptedKey;
    try {
      if (API_ENABLED) {
        const key = checkoutKey(product.id);
        attemptedKey = key;
        const payload = {
          product_id: product.id,
          customer_name: "Demo customer",
          customer_email: "demo@example.com",
          idempotency_key: key,
        };
        const response = await apiRequest("/payments/checkout", {
          method: "POST",
          body: JSON.stringify(payload),
        });
        if (!response.data?.id)
          throw new Error(
            "The API did not confirm this checkout. Retry to recover the same checkout.",
          );
        const record = normalizePayment(response.data);
        setPayments((current) => [
          record,
          ...current.filter(
            (payment) => payment.backendId !== record.backendId,
          ),
        ]);
        setApiConnected(true);
        setModal(null);
        if (response.checkout_url)
          window.location.assign(response.checkout_url);
        else
          notify(
            response.checkout_mode === "demo"
              ? "Demo payment created by Laravel"
              : response.idempotent_replay
                ? "Existing checkout recovered"
                : "Payment created through Laravel API",
          );
        finishCheckout(product.id, key);
        return;
      }
      const id = `pi_demo${crypto.randomUUID()}`;
      setPayments((current) => [
        {
          id,
          customer: "Demo customer",
          email: "demo@example.com",
          product: product.name,
          amount: product.amount,
          currency: product.currency,
          status: "processing",
          method: "Test card",
          created: "Just now",
          webhook: "pending",
        },
        ...current,
      ]);
      setModal(null);
      notify("Checkout session created in local demo mode");
    } catch (error) {
      setCheckoutError(
        error.message || "Checkout could not be confirmed. Please retry.",
      );
      if (error.code === "CHECKOUT_EXPIRED" && error.restartAllowed && attemptedKey) {
        setCheckoutRestart({ productId: product.id, key: attemptedKey });
      }
    } finally {
      checkoutInFlight.current = false;
      setCheckoutSubmitting(false);
    }
  }
  async function refundPayment(payment) {
    if (actionInFlight.current) return false;
    actionInFlight.current = true;
    setPendingAction("refund");
    try {
      await apiRequest(`/payments/${payment.backendId}/refund`, {
        method: "POST",
      });
      const response = await apiRequest(`/payments/${payment.backendId}`);
      const refreshed = normalizePayment(response.data);
      setSelectedPayment(refreshed);
      setPayments((current) =>
        current.map((item) =>
          item.backendId === refreshed.backendId ? refreshed : item,
        ),
      );
      notify(
        refreshed.status === "refunded"
          ? "Test refund completed"
          : refundMessages[refreshed.refundStatus] ||
            "Refresh payment to check refund status",
      );
      return true;
    } catch (error) {
      notify(error.message || "Could not create refund");
      return false;
    } finally {
      actionInFlight.current = false;
      setPendingAction(null);
    }
  }
  async function retryWebhook(id) {
    if (actionInFlight.current) return;
    const payment = payments.find((item) => item.id === id);
    if (!API_ENABLED || !payment?.backendId) {
      notify("Retry requires the Laravel API and a stored verified event");
      return;
    }
    actionInFlight.current = true;
    setPendingAction("retry");
    try {
      const response = await apiRequest(
        `/payments/${payment.backendId}/retry-webhook`,
        { method: "POST" },
      );
      const refreshed = normalizePayment(response.data);
      setPayments((current) =>
        current.map((item) =>
          item.backendId === refreshed.backendId ? refreshed : item,
        ),
      );
      setSelectedPayment((current) =>
        current?.backendId === refreshed.backendId ? refreshed : current,
      );
      apiRequest("/webhook-events?per_page=100")
        .then((events) =>
          setWebhookEvents((events.data || []).map(normalizeWebhookEvent)),
        )
        .catch(() => {});
      notify(
        response.webhook_event.status === "ignored"
          ? "Event replayed; payment state was preserved"
          : "Verified webhook event processed",
      );
    } catch (error) {
      notify(error.message || "Webhook retry failed");
    } finally {
      actionInFlight.current = false;
      setPendingAction(null);
    }
  }
  return (
    <div className="shell">
      <a href="#main-content" className="skip-link">
        Skip to content
      </a>
      {mobileOpen && (
        <button
          className="nav-scrim"
          aria-label="Close navigation"
          onClick={() => setMobileOpen(false)}
        />
      )}
      <aside
        ref={sidebar}
        id="workspace-navigation"
        className={`sidebar ${mobileOpen ? "is-open" : ""}`}
      >
        <button
          className="mobile-nav-close icon-btn"
          aria-label="Close menu"
          onClick={() => setMobileOpen(false)}
        >
          <X size={20} />
        </button>
        <div className="brand">
          <span className="brand-mark">
            <Zap size={15} />
          </span>
          <span>payflow</span>
          <small>DEMO</small>
        </div>
        <div className="mode-pill">
          <span className="pulse" /> Stripe test mode ·{" "}
          {API_ENABLED
            ? apiConnected
              ? "Laravel API"
              : "API unavailable"
            : "Local simulation"}
        </div>
        <nav>
          <p>Workspace</p>
          <NavItem
            icon={<LayoutDashboard size={17} />}
            label="Overview"
            active={tab === "Overview"}
            onClick={() => navigate("Overview")}
          />
          <NavItem
            icon={<CreditCard size={17} />}
            label="Payments"
            count={payments.length}
            active={tab === "Payments"}
            onClick={() => navigate("Payments")}
          />
          <NavItem
            icon={<Package size={17} />}
            label="Products"
            active={tab === "Products"}
            onClick={() => navigate("Products")}
          />
          <NavItem
            icon={<Webhook size={17} />}
            label="Webhooks"
            active={tab === "Webhooks"}
            onClick={() => navigate("Webhooks")}
          />
          <p>Build</p>
          <NavItem
            icon={<Code2 size={17} />}
            label="API explorer"
            active={tab === "API explorer"}
            onClick={() => navigate("API explorer")}
          />
          <NavItem
            icon={<Settings2 size={17} />}
            label="Settings"
            active={tab === "Settings"}
            onClick={() => navigate("Settings")}
          />
        </nav>
        <div className="sidebar-bottom">
          <a
            className="help-card"
            href={`${DOCS_URL}README.md`}
            target="_blank"
            rel="noreferrer"
          >
            <CircleHelp size={17} />
            <div>
              <strong>Payment docs</strong>
              <span>Learn the flow</span>
            </div>
            <ChevronRight size={15} />
          </a>
          <div className="profile">
            <div className="avatar">TP</div>
            <div>
              <strong>Tuyen Pham</strong>
              <span>Developer</span>
            </div>
          </div>
        </div>
      </aside>
      <main
        id="main-content"
        tabIndex={-1}
        className="main"
        inert={mobileOpen ? true : undefined}
      >
        <header className="topbar">
          <button
            className="mobile-menu"
            aria-label="Open navigation"
            aria-expanded={mobileOpen}
            aria-controls="workspace-navigation"
            onClick={() => setMobileOpen(true)}
          >
            <Menu size={20} />
          </button>
          <div className="crumb">
            <span>Payment operations</span>
            <ChevronRight size={14} />
            <strong>{tab}</strong>
          </div>
          <div className="top-actions">
            {API_ENABLED &&
              (adminSession ? (
                <button
                  className="outline"
                  onClick={logoutAdmin}
                  disabled={signingOut}
                >
                  {signingOut ? "Signing out…" : "Sign out"}
                </button>
              ) : (
                <button
                  className="outline"
                  onClick={() => setModal("admin-login")}
                >
                  Admin sign in
                </button>
              ))}
            <button
              className="icon-btn"
              aria-label="Search payments"
              onClick={() => {
                navigate("Payments");
                window.setTimeout(
                  () => document.getElementById("payment-search")?.focus(),
                  0,
                );
              }}
            >
              <Search size={18} />
            </button>
            <div className="avatar small">TP</div>
          </div>
        </header>
        <div className="content">
          {API_ENABLED && catalogError && (
            <div className="feedback-banner" role="alert">
              <span>Catalog unavailable: {catalogError}</span>
              <button
                className="outline"
                disabled={catalogLoading}
                onClick={loadCatalog}
              >
                Retry catalog
              </button>
            </div>
          )}
          {API_ENABLED && adminSession && (
            <div className="records-toolbar">
              <span>
                {loadingRecords
                  ? "Loading records…"
                  : `${payments.length} payments · ${webhookEvents.length} events loaded`}
              </span>
              <button
                className="outline"
                disabled={loadingRecords || Boolean(pendingAction)}
                onClick={() => loadRecords()}
              >
                <RefreshCw size={15} className={loadingRecords ? "spin" : ""} />{" "}
                Refresh records
              </button>
              {nextPages.payments && (
                <button
                  className="outline"
                  disabled={loadingRecords}
                  onClick={() => loadRecords("payments", true)}
                >
                  Load more payments
                </button>
              )}
              {nextPages.events && (
                <button
                  className="outline"
                  disabled={loadingRecords}
                  onClick={() => loadRecords("events", true)}
                >
                  Load more events
                </button>
              )}
            </div>
          )}
          {dataError && (
            <p className="checkout-error" role="alert">
              {dataError}
            </p>
          )}
          {checkoutError && modal !== "checkout" && (
            <p className="checkout-error" role="alert">
              {checkoutError}
            </p>
          )}
          {API_ENABLED && !adminSession && (
            <div className="admin-notice">
              <ShieldCheck size={16} />
              <span>
                Admin sign in is required to view payment records and manage
                refunds or webhook retries.
              </span>
            </div>
          )}
          {checkoutResult ? (
            <CheckoutResultPage
              result={checkoutResult}
              onRefresh={refreshCheckoutResult}
              onReturn={closeCheckoutResult}
              onTryAgain={() => {
                closeCheckoutResult();
                setModal("checkout");
              }}
            />
          ) : (
            <>
              {tab === "Overview" && (
                <Overview
                  apiEnabled={API_ENABLED}
                  payments={payments}
                  onCheckout={() => setModal("checkout")}
                  onRetry={retryWebhook}
                  onViewPayments={() => setTab("Payments")}
                  onSelectPayment={openPayment}
                >
                  <PaymentTable
                    payments={payments.slice(0, 5)}
                    onRetry={retryWebhook}
                    onSelectPayment={openPayment}
                    pendingAction={pendingAction}
                  />
                </Overview>
              )}{" "}
              {tab === "Payments" && (
                <Payments
                  payments={visible}
                  filter={filter}
                  setFilter={setFilter}
                  query={query}
                  setQuery={setQuery}
                  onRetry={retryWebhook}
                  onSelectPayment={openPayment}
                  pendingAction={pendingAction}
                />
              )}{" "}
              {tab === "Payment detail" && selectedPayment && (
                <PaymentDetail
                  payment={selectedPayment}
                  onBack={() => navigate("Payments")}
                  onRetry={retryWebhook}
                  onRefund={refundPayment}
                  onRefresh={
                    API_ENABLED ? () => openPayment(selectedPayment) : null
                  }
                  loading={detailLoading}
                  pendingAction={pendingAction}
                />
              )}{" "}
              {tab === "Products" && (
                <Products
                  products={products}
                  onCheckout={simulateCheckout}
                  submitting={checkoutSubmitting || catalogLoading}
                  loading={catalogLoading}
                />
              )}{" "}
              {tab === "Webhooks" && (
                <Webhooks
                  payments={payments}
                  webhookEvents={webhookEvents}
                  apiEnabled={API_ENABLED}
                  onRetry={retryWebhook}
                  pendingAction={pendingAction}
                  onSettings={() => navigate("Settings")}
                />
              )}{" "}
              {tab === "API explorer" && (
                <ApiExplorer apiBase={API_BASE} notify={notify} />
              )}{" "}
              {tab === "Settings" && (
                <Settings
                  apiBase={API_BASE}
                  apiEnabled={API_ENABLED}
                  apiConnected={apiConnected}
                />
              )}
            </>
          )}
        </div>
      </main>
      {modal === "admin-login" && (
        <AdminLoginModal onLogin={loginAdmin} onClose={() => setModal(null)} />
      )}{" "}
      {modal === "checkout" && (
        <CheckoutModal
          products={products}
          onClose={() => {
            if (!checkoutInFlight.current) setModal(null);
          }}
          onCreate={simulateCheckout}
          onRestart={restartCheckout}
          restartProductId={checkoutRestart?.productId}
          onProductChange={() => {
            setCheckoutError("");
            setCheckoutRestart(null);
          }}
          submitting={checkoutSubmitting}
          error={checkoutError}
          formatPrice={money}
        />
      )}{" "}
      {toast && (
        <div className="toast" role="status">
          <Check size={16} />
          {toast}
        </div>
      )}
    </div>
  );
}
function CheckoutResultPage({ result, onRefresh, onReturn, onTryAgain }) {
  const cancelled = result.kind === "cancelled";
  const refunded = result.data?.status === "refunded";
  const paid =
    !refunded &&
    (result.data?.payment_status === "paid" ||
      result.data?.status === "succeeded");
  const failed =
    refunded ||
    result.data?.status === "failed" ||
    result.data?.session_status === "expired";
  const pending = !cancelled && !paid && !failed;
  const title = refunded
    ? "Payment refunded"
    : cancelled
      ? "Checkout cancelled"
      : paid
        ? "Payment successful"
        : failed
          ? "Payment not completed"
          : "Awaiting payment confirmation";
  const message = refunded
    ? "This test payment has been refunded. Review the payment record for refund details."
    : cancelled
      ? "Your checkout was cancelled. No payment was completed."
      : paid
        ? `Your payment for ${result.data?.product_name || "the selected product"} is confirmed.`
        : failed
          ? "Stripe could not confirm this payment. You can return to the dashboard and try checkout again."
          : "Stripe returned you to the app. We are waiting for the signed webhook to confirm the final payment state.";
  return (
    <div className="checkout-result-wrap">
      <section
        className={`checkout-result-card ${cancelled || failed ? "result-muted" : paid ? "result-paid" : "result-pending"}`}
      >
        <div className="result-icon">
          {cancelled || failed ? (
            <X size={25} />
          ) : paid ? (
            <Check size={25} />
          ) : result.loading ? (
            <RefreshCw size={24} className="spin" />
          ) : (
            <Clock3 size={25} />
          )}
        </div>
        <p className="eyebrow">STRIPE TEST CHECKOUT</p>
        <h1>{title}</h1>
        <p className="result-message">{message}</p>
        {result.data && (
          <div className="result-summary">
            <div>
              <span>Product</span>
              <strong>{result.data.product_name}</strong>
            </div>
            <div>
              <span>Amount</span>
              <strong>
                {money(result.data.amount, result.data.currency)}{" "}
                {(result.data.currency || "usd").toUpperCase()}
              </strong>
            </div>
            <div>
              <span>Payment state</span>
              <strong className={`result-state ${result.data.status}`}>
                {result.data.status}
              </strong>
            </div>
            <div>
              <span>Webhook</span>
              <strong>{result.data.webhook_status}</strong>
            </div>
          </div>
        )}
        {pending && (
          <div className="pending-note">
            <ShieldCheck size={16} />
            <span>
              {result.loading
                ? "Checking the checkout session…"
                : result.error
                  ? "The API could not confirm the session right now. The webhook may still update the dashboard."
                  : result.data?.confirmation_pending
                    ? "Confirmation is pending. This page does not mark the payment successful until Stripe confirms it."
                    : "Use refresh to check the latest payment state."}
            </span>
          </div>
        )}
        {result.error && (
          <p className="result-error" role="alert">
            {result.error}
          </p>
        )}
        <div className="result-actions">
          {pending && result.sessionId && (
            <button
              className="outline"
              onClick={onRefresh}
              disabled={result.loading}
            >
              <RefreshCw size={15} /> Refresh status
            </button>
          )}
          {(cancelled || failed) && (
            <button className="primary" onClick={onTryAgain}>
              <CreditCard size={16} /> Try checkout again
            </button>
          )}
          <button
            className={pending || paid ? "primary" : "outline"}
            onClick={onReturn}
          >
            Return to dashboard <ChevronRight size={15} />
          </button>
        </div>
        {result.sessionId && (
          <small className="result-session">Session {result.sessionId}</small>
        )}
      </section>
    </div>
  );
}
function NavItem({ icon, label, count, active, onClick }) {
  return (
    <button
      aria-current={active ? "page" : undefined}
      className={`nav-item ${active ? "active" : ""}`}
      onClick={onClick}
    >
      {icon}
      <span>{label}</span>
      {count && <b>{count}</b>}
    </button>
  );
}
function Status({ status }) {
  return (
    <span className={`status ${status}`}>
      <i />
      {status}
    </span>
  );
}
function PaymentDetail({
  payment,
  onBack,
  onRetry,
  onRefund,
  pendingAction,
  onRefresh,
  loading,
}) {
  const [showRefundConfirm, setShowRefundConfirm] = useState(false);
  const [refundSubmitting, setRefundSubmitting] = useState(false);
  const refundDialog = useRef(null);
  useDialogFocus(
    refundDialog,
    () => setShowRefundConfirm(false),
    refundSubmitting,
    showRefundConfirm,
  );
  const timeline = [
    {
      title: "Payment created",
      detail: `Checkout initiated for ${payment.product}`,
      time: payment.created,
      done: true,
    },
    {
      title: "Checkout session",
      detail: payment.checkoutSessionId || "Stripe Checkout session",
      time: payment.created,
      done: Boolean(payment.checkoutSessionId),
    },
    {
      title: "Webhook received",
      detail:
        payment.webhook === "processed"
          ? "Signature verified and event processed"
          : `Webhook ${payment.webhook}`,
      time: payment.paidAt || "Awaiting Stripe event",
      done: payment.webhook === "processed",
    },
    {
      title:
        payment.status === "refunded"
          ? "Payment refunded"
          : payment.status === "failed"
            ? "Payment failed"
            : payment.status === "succeeded"
              ? "Payment succeeded"
              : "Final payment state",
      detail:
        payment.status === "refunded"
          ? "Stripe confirmed the test refund"
          : payment.status === "succeeded"
            ? "Payment confirmed by Stripe"
            : payment.status === "failed"
              ? "Stripe reported a failed payment"
              : "Waiting for confirmation",
      time: payment.refundedAt || payment.paidAt || "Pending",
      done: ["succeeded", "failed", "refunded"].includes(payment.status),
    },
  ];
  return (
    <>
      <section className="page-title detail-title">
        <div>
          <button className="back-link" onClick={onBack}>
            <ChevronRight size={14} /> Back to payments
          </button>
          <p className="eyebrow">PAYMENT DETAIL</p>
          <h1>Transaction details</h1>
          <p className="sub">
            Inspect payment state, checkout identifiers, and event progress.
          </p>
        </div>
        <div className="detail-title-actions">
          {onRefresh && (
            <button
              className="outline"
              disabled={loading || Boolean(pendingAction)}
              onClick={onRefresh}
            >
              <RefreshCw size={15} className={loading ? "spin" : ""} />
              {loading ? "Loading payment…" : "Refresh payment"}
            </button>
          )}
          <Status status={payment.status} />
          {payment.refundAvailable && (
            <button
              className="outline refund-button"
              onClick={() => setShowRefundConfirm(true)}
            >
              <ArrowDownLeft size={15} /> Refund test payment
            </button>
          )}
          {payment.webhook !== "processed" && (
            <button
              className="outline"
              disabled={Boolean(pendingAction)}
              onClick={() => onRetry(payment.id)}
            >
              Retry webhook <RefreshCw size={14} />
            </button>
          )}
        </div>
      </section>
      <div className="detail-layout">
        <div className="detail-main">
          <section className="card amount-card">
            <div>
              <span>Payment amount</span>
              <strong>
                {money(payment.amount, payment.currency)}{" "}
                <small>{(payment.currency || "usd").toUpperCase()}</small>
              </strong>
              <p>
                <Status status={payment.status} />
                <span className="detail-date">Created {payment.created}</span>
              </p>
            </div>
            <div className="amount-icon">
              <CreditCard size={21} />
            </div>
          </section>
          <section className="card details-card">
            <div className="card-head">
              <div>
                <h2>Payment information</h2>
                <p>Identifiers and checkout details from Laravel.</p>
              </div>
            </div>
            <div className="detail-fields">
              <DetailField label="Payment ID" value={payment.id} />
              <DetailField label="Product" value={payment.product} />
              <DetailField label="Customer" value={payment.customer} />
              <DetailField label="Email" value={payment.email} />
              <DetailField
                label="PaymentIntent"
                value={payment.paymentIntentId || payment.id}
              />
              <DetailField
                label="Checkout Session"
                value={
                  payment.checkoutSessionId || "Not available in local demo"
                }
              />
              <DetailField
                label="Checkout URL"
                value={payment.checkoutUrl || "—"}
              />
              <DetailField
                label="Idempotency key"
                value={payment.idempotencyKey || "Not exposed"}
              />
              <DetailField
                label="Paid at"
                value={
                  payment.paidAt ||
                  (payment.status === "succeeded" ? payment.created : "—")
                }
              />
              {payment.refundStatus && (
                <DetailField
                  label="Refund status"
                  value={payment.refundStatus}
                />
              )}
              {payment.refundStatus && (
                <DetailField
                  label="Refund guidance"
                  value={
                    refundMessages[payment.refundStatus] ||
                    "Refresh payment to check refund status"
                  }
                />
              )}
              {payment.refundId && (
                <DetailField label="Stripe refund" value={payment.refundId} />
              )}
              {payment.refundedAt && (
                <DetailField label="Refunded at" value={payment.refundedAt} />
              )}
            </div>
          </section>
          <section className="card details-card">
            <div className="card-head">
              <div>
                <h2>Webhook event log</h2>
                <p>Verified delivery attempts for this transaction.</p>
              </div>
              <span className={`webhook ${payment.webhook}`}>
                <i />
                {payment.webhook}
              </span>
            </div>
            {payment.webhookEvents?.length ? (
              <div className="payment-event-list">
                {payment.webhookEvents.map((event) => (
                  <div className="payment-event" key={event.id}>
                    <div className={`event-dot ${event.webhook}`} />
                    <div className="payment-event-copy">
                      <strong>{event.event}</strong>
                      <span>
                        {event.id} · {event.created}
                      </span>
                      <small>
                        Attempt {event.attempts} · SHA-256{" "}
                        {event.payloadHash?.slice(0, 16) || "—"}…
                      </small>
                      {event.lastError && (
                        <small className="event-error">{event.lastError}</small>
                      )}
                    </div>
                    <span className={`webhook ${event.webhook}`}>
                      <i />
                      {event.webhook}
                    </span>
                  </div>
                ))}
              </div>
            ) : (
              <div className="empty-events">
                <Webhook size={18} />
                <span>No verified webhook events recorded yet.</span>
              </div>
            )}
          </section>
        </div>
        <aside className="card timeline-card">
          <div className="card-head">
            <div>
              <h2>Payment timeline</h2>
              <p>Lifecycle events</p>
            </div>
          </div>
          <div className="timeline">
            {timeline.map((item, index) => (
              <div
                className={`timeline-item ${item.done ? "done" : ""}`}
                key={item.title}
              >
                <span className="timeline-marker">
                  {item.done ? <Check size={11} /> : index + 1}
                </span>
                <div>
                  <strong>{item.title}</strong>
                  <p>{item.detail}</p>
                  <small>{item.time}</small>
                </div>
              </div>
            ))}
          </div>
          <div className="timeline-note">
            <ShieldCheck size={15} />
            <span>
              Stripe webhooks are the source of truth for final payment status.
            </span>
          </div>
        </aside>
      </div>
      {showRefundConfirm && (
        <div className="modal-backdrop">
          <div
            ref={refundDialog}
            tabIndex={-1}
            className="modal refund-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="refund-title"
          >
            <div className="modal-head">
              <div>
                <p className="eyebrow">STRIPE TEST MODE</p>
                <h2 id="refund-title">Refund this payment?</h2>
                <p>
                  This sends a full refund request to Stripe for{" "}
                  {money(payment.amount, payment.currency)}.
                </p>
              </div>
              <button
                className="icon-btn"
                onClick={() => setShowRefundConfirm(false)}
                disabled={refundSubmitting}
                aria-label="Close refund"
              >
                <X size={18} />
              </button>
            </div>
            <div className="test-card">
              <ShieldCheck size={16} />
              <div>
                <strong>Test refund only</strong>
                <span>Live Stripe keys are rejected by the API.</span>
              </div>
            </div>
            <div className="modal-actions">
              <button
                className="outline"
                onClick={() => setShowRefundConfirm(false)}
                disabled={refundSubmitting}
              >
                Keep payment
              </button>
              <button
                className="primary refund-confirm"
                disabled={refundSubmitting}
                onClick={async () => {
                  setRefundSubmitting(true);
                  const completed = await onRefund(payment);
                  setRefundSubmitting(false);
                  if (completed) setShowRefundConfirm(false);
                }}
              >
                {refundSubmitting ? "Submitting…" : "Confirm refund"}{" "}
                <ArrowDownLeft size={15} />
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
function DetailField({ label, value }) {
  return (
    <div className="detail-field">
      <span>{label}</span>
      <strong title={value}>{value}</strong>
    </div>
  );
}
function PaymentTable({ payments, onRetry, onSelectPayment, pendingAction }) {
  return (
    <div
      className="table-wrap"
      role="region"
      aria-label="Payment records"
      tabIndex={0}
    >
      <table>
        <thead>
          <tr>
            <th>Payment</th>
            <th>Product</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Webhook</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          {!payments.length && (
            <tr>
              <td colSpan={6}>
                <p className="empty-state">
                  No payments to show. Try another search or filter, or create a
                  checkout.
                </p>
              </td>
            </tr>
          )}
          {payments.map((p) => (
            <tr key={p.id}>
              <td>
                <div className="payment-cell">
                  <span className="payment-avatar">
                    {p.customer
                      .split(" ")
                      .map((x) => x[0])
                      .join("")}
                  </span>
                  <div>
                    <strong>{p.customer}</strong>
                    <small>{p.id}</small>
                  </div>
                </div>
              </td>
              <td>
                {p.product}
                <small>{p.method}</small>
              </td>
              <td className="amount">{money(p.amount, p.currency)}</td>
              <td>
                <Status status={p.status} />
              </td>
              <td>
                <span className={`webhook ${p.webhook}`}>
                  <i />
                  {p.webhook}
                </span>
              </td>
              <td>
                <div className="row-actions">
                  {(p.webhook === "retrying" || p.webhook === "pending") && (
                    <button
                      className="row-action"
                      disabled={Boolean(pendingAction)}
                      onClick={() => onRetry(p.id)}
                      title="Retry webhook"
                      aria-label={`Retry ${p.id} webhook`}
                    >
                      <RefreshCw size={15} />
                    </button>
                  )}
                  <button
                    className="row-action"
                    title="View payment details"
                    aria-label={`View ${p.id} details`}
                    onClick={() => onSelectPayment?.(p)}
                  >
                    <ChevronRight size={16} />
                  </button>
                </div>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
function Payments({
  payments,
  filter,
  setFilter,
  query,
  setQuery,
  onRetry,
  onSelectPayment,
  pendingAction,
}) {
  return (
    <>
      <section className="page-title">
        <div>
          <p className="eyebrow">PAYMENTS</p>
          <h1>All payments</h1>
          <p className="sub">
            Search payment intents and inspect their webhook lifecycle.
          </p>
        </div>
      </section>
      <section className="card table-card">
        <div className="toolbar">
          <div className="search">
            <Search size={16} />
            <input
              id="payment-search"
              aria-label="Search payments"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="Search customer or payment ID"
            />
          </div>
          <div className="filters">
            {["all", "succeeded", "processing", "failed", "refunded"].map(
              (s) => (
                <button
                  key={s}
                  aria-pressed={filter === s}
                  className={filter === s ? "selected" : ""}
                  onClick={() => setFilter(s)}
                >
                  {s}
                </button>
              ),
            )}
          </div>
        </div>
        <PaymentTable
          payments={payments}
          onRetry={onRetry}
          onSelectPayment={onSelectPayment}
          pendingAction={pendingAction}
        />
      </section>
    </>
  );
}
function Products({ products, onCheckout, submitting, loading }) {
  return (
    <>
      <section className="page-title">
        <div>
          <p className="eyebrow">CATALOG</p>
          <h1>Demo products</h1>
          <p className="sub">
            Products are intentionally simple so the payment state machine stays
            visible.
          </p>
        </div>
      </section>
      <div className="product-grid">
        {!products.length && (
          <p className="empty-state" role="status">
            {loading
              ? "Loading product catalog…"
              : "No active products available."}
          </p>
        )}
        {products.map((p) => (
          <div className="card product" key={p.name}>
            <div className={`product-icon ${p.color}`}>
              <Package size={20} />
            </div>
            <div className="product-top">
              <span className="tag">ACTIVE</span>
            </div>
            <h2>{p.name}</h2>
            <p>{p.detail}</p>
            <strong>
              {money(p.amount, p.currency)}
              <small> / one-time</small>
            </strong>
            <button
              className="outline full"
              disabled={submitting}
              onClick={() => onCheckout(p)}
            >
              Run test checkout <ArrowUpRight size={15} />
            </button>
          </div>
        ))}
      </div>
    </>
  );
}
createRoot(document.getElementById("root")).render(<App />);
