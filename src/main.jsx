import React, { useEffect, useMemo, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { Activity, ArrowDownLeft, ArrowUpRight, Check, ChevronRight, CircleHelp, Clock3, Code2, Copy, CreditCard, ExternalLink, FileText, LayoutDashboard, Menu, MoreHorizontal, Package, RefreshCw, Search, Settings2, ShieldCheck, Sparkles, Webhook, X, Zap } from 'lucide-react';
import './styles.css';

const initialPayments = [
  { id: 'pi_3Qx9k7J2', customer: 'Maya Chen', email: 'maya@northstar.studio', product: 'Pro workspace', amount: 8900, status: 'succeeded', method: 'Visa •••• 4242', created: 'Today, 10:42', webhook: 'processed' },
  { id: 'pi_3Qx8b1F4', customer: 'Theo Martin', email: 'theo@orbitlabs.io', product: 'Starter plan', amount: 2900, status: 'processing', method: 'Card', created: 'Today, 09:17', webhook: 'pending' },
  { id: 'pi_3Qx7m4P8', customer: 'Ari Santos', email: 'ari@madebyari.co', product: 'Pro workspace', amount: 8900, status: 'failed', method: 'Card •••• 0005', created: 'Yesterday, 16:08', webhook: 'retrying' },
  { id: 'pi_3Qx6r2N1', customer: 'Noah Williams', email: 'noah@framekit.dev', product: 'Team plan', amount: 14900, status: 'succeeded', method: 'Mastercard •••• 4444', created: 'Yesterday, 13:21', webhook: 'processed' },
  { id: 'pi_3Qx5a8C2', customer: 'Lena Park', email: 'lena@lumenworks.co', product: 'Starter plan', amount: 2900, status: 'refunded', method: 'Visa •••• 4242', created: 'Nov 08, 11:02', webhook: 'processed' },
];
const demoProducts = [{ id: 1, name: 'Starter plan', detail: 'For trying the flow', amount: 2900, currency: 'usd', color: 'mint' }, { id: 2, name: 'Pro workspace', detail: 'For growing teams', amount: 8900, currency: 'usd', color: 'violet' }, { id: 3, name: 'Team plan', detail: 'For larger operations', amount: 14900, currency: 'usd', color: 'amber' }];
const API_BASE = import.meta.env.VITE_API_URL || '/api';
const API_ENABLED = import.meta.env.VITE_USE_API !== 'false';
const money = (cents, currency = 'usd') => new Intl.NumberFormat('en-US', { style: 'currency', currency: currency.toUpperCase() }).format(cents / 100);
function normalizeProduct(product, index = 0) { return { id: product.id, name: product.name, detail: product.description, amount: Number(product.price_cents), currency: product.currency || 'usd', color: ['mint','violet','amber'][index % 3] }; }
function normalizePayment(payment) {
  return {
    id: payment.stripe_payment_intent_id || `payment_${payment.id}`,
    customer: payment.customer_name,
    email: payment.customer_email,
    product: payment.product_name,
    amount: Number(payment.amount),
    status: payment.status,
    method: payment.payment_method || 'Test card',
    created: payment.created_at ? new Date(payment.created_at).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : 'Just now',
    webhook: payment.webhook_status || 'pending',
    backendId: payment.id,
    paymentIntentId: payment.stripe_payment_intent_id,
    checkoutSessionId: payment.stripe_checkout_session_id,
    idempotencyKey: payment.idempotency_key,
    eventId: payment.stripe_event_id,
    paidAt: payment.paid_at ? new Date(payment.paid_at).toLocaleString() : null,
    lastError: payment.last_error,
    refundId: payment.stripe_refund_id,
    refundStatus: payment.refund_status,
    refundedAt: payment.refunded_at ? new Date(payment.refunded_at).toLocaleString() : null,
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
    created: event.received_at ? new Date(event.received_at).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : '—',
    paymentId: event.payment_id,
    paymentName: event.payment?.customer_name || 'Unmatched Stripe event',
    product: event.payment?.product_name || '—',
  };
}
async function apiRequest(path, options = {}) {
  const response = await fetch(`${API_BASE}${path}`, { ...options, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(options.headers || {}) } });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(data.message || `API request failed (${response.status})`);
  return data;
}

function App() {
  const [tab, setTab] = useState('Overview'); const [payments, setPayments] = useState(initialPayments); const [products, setProducts] = useState(demoProducts); const [filter, setFilter] = useState('all'); const [query, setQuery] = useState(''); const [modal, setModal] = useState(null); const [toast, setToast] = useState(''); const [apiConnected, setApiConnected] = useState(false); const [selectedPayment, setSelectedPayment] = useState(null); const [webhookEvents, setWebhookEvents] = useState([]); const [checkoutResult, setCheckoutResult] = useState(null);
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const outcome = params.get('checkout');
    if (outcome === 'cancelled') setCheckoutResult({ kind: 'cancelled' });
    if (outcome === 'success') {
      const sessionId = params.get('session_id');
      setCheckoutResult({ kind: 'success', sessionId, loading: Boolean(sessionId && API_ENABLED), error: sessionId && !API_ENABLED ? 'Enable the Laravel API to confirm this checkout session.' : null });
      if (sessionId && API_ENABLED) {
        apiRequest(`/checkout/sessions/${encodeURIComponent(sessionId)}`)
          .then(response => setCheckoutResult(current => ({ ...current, loading: false, data: response.data })))
          .catch(error => setCheckoutResult(current => ({ ...current, loading: false, error: error.message })));
      }
    }
  }, []);
  useEffect(() => {
    if (!API_ENABLED) return;
    apiRequest('/payments?per_page=100').then(response => {
      const records = Array.isArray(response.data) ? response.data : [];
      setPayments(records.map(normalizePayment));
      apiRequest('/webhook-events?per_page=100').then(eventResponse => setWebhookEvents((eventResponse.data || []).map(normalizeWebhookEvent))).catch(() => {});
      apiRequest('/products').then(productResponse => setProducts((productResponse.data || []).map(normalizeProduct))).catch(() => {});
      setApiConnected(true);
    }).catch(() => setApiConnected(false));
  }, []);
  const visible = useMemo(() => payments.filter(p => (filter === 'all' || p.status === filter) && `${p.customer} ${p.email} ${p.id}`.toLowerCase().includes(query.toLowerCase())), [payments, filter, query]);
  const succeeded = payments.filter(p => p.status === 'succeeded'); const volume = succeeded.reduce((sum, p) => sum + p.amount, 0);
  function notify(message) { setToast(message); window.setTimeout(() => setToast(''), 2600); }
  async function refreshCheckoutResult() {
    if (!checkoutResult?.sessionId) return;
    setCheckoutResult(current => ({ ...current, loading: true, error: null }));
    try {
      const response = await apiRequest(`/checkout/sessions/${encodeURIComponent(checkoutResult.sessionId)}`);
      setCheckoutResult(current => ({ ...current, loading: false, data: response.data }));
    } catch (error) {
      setCheckoutResult(current => ({ ...current, loading: false, error: error.message }));
    }
  }
  function closeCheckoutResult() {
    setCheckoutResult(null);
    window.history.replaceState({}, '', window.location.pathname);
    setTab('Overview');
  }
  async function openPayment(payment) {
    setSelectedPayment(payment);
    setTab('Payment detail');
    if (API_ENABLED && payment.backendId) {
      try {
        const response = await apiRequest(`/payments/${payment.backendId}`);
        setSelectedPayment(normalizePayment(response.data));
        setApiConnected(true);
      } catch { setApiConnected(false); }
    }
  }
  async function simulateCheckout(product) {
    const amount = product.amount;
    const payload = { product_id: product.id, customer_name: 'Demo customer', customer_email: 'demo@example.com', idempotency_key: `checkout-${crypto.randomUUID()}` };
    if (API_ENABLED) {
      try {
        const response = await apiRequest('/payments/checkout', { method: 'POST', body: JSON.stringify(payload) });
        setPayments(current => [normalizePayment(response.data), ...current]); setApiConnected(true); setModal(null); if (response.checkout_url) { window.location.assign(response.checkout_url); return; } notify('Payment created through Laravel API'); return;
      } catch { setApiConnected(false); }
    }
    const id = `pi_demo${Math.random().toString(36).slice(2, 8)}`;
    setPayments(current => [{ id, customer: 'Demo customer', email: 'demo@example.com', product: product.name, amount, status: 'processing', method: 'Test card', created: 'Just now', webhook: 'pending' }, ...current]); setModal(null); notify('Checkout session created in local demo mode');
  }
  async function refundPayment(payment) {
    try {
      await apiRequest(`/payments/${payment.backendId}/refund`, { method: 'POST' });
      const response = await apiRequest(`/payments/${payment.backendId}`);
      const refreshed = normalizePayment(response.data);
      setSelectedPayment(refreshed);
      setPayments(current => current.map(item => item.backendId === refreshed.backendId ? refreshed : item));
      notify(refreshed.status === 'refunded' ? 'Test refund completed' : 'Refund submitted; waiting for Stripe confirmation');
      return true;
    } catch (error) {
      notify(error.message || 'Could not create refund');
      return false;
    }
  }
  async function retryWebhook(id) {
    const payment = payments.find(item => item.id === id);
    if (!API_ENABLED || !payment?.backendId) {
      notify('Retry requires the Laravel API and a stored verified event');
      return;
    }
    try {
      const response = await apiRequest(`/payments/${payment.backendId}/retry-webhook`, { method: 'POST' });
      const refreshed = normalizePayment(response.data);
      setPayments(current => current.map(item => item.backendId === refreshed.backendId ? refreshed : item));
      setSelectedPayment(current => current?.backendId === refreshed.backendId ? refreshed : current);
      apiRequest('/webhook-events?per_page=100').then(events => setWebhookEvents((events.data || []).map(normalizeWebhookEvent))).catch(() => {});
      notify(response.webhook_event.status === 'ignored' ? 'Event replayed; payment state was preserved' : 'Verified webhook event processed');
    } catch (error) {
      notify(error.message || 'Webhook retry failed');
    }
  }
  return <div className="shell"><aside className="sidebar"><div className="brand"><span className="brand-mark"><Zap size={15}/></span><span>payflow</span><small>DEMO</small></div><div className="mode-pill"><span className="pulse"/> Stripe test mode · {apiConnected ? 'Laravel API' : 'Local fallback'}</div><nav><p>Workspace</p><NavItem icon={<LayoutDashboard size={17}/>} label="Overview" active={tab === 'Overview'} onClick={() => setTab('Overview')}/><NavItem icon={<CreditCard size={17}/>} label="Payments" count={payments.length} active={tab === 'Payments'} onClick={() => setTab('Payments')}/><NavItem icon={<Package size={17}/>} label="Products" active={tab === 'Products'} onClick={() => setTab('Products')}/><NavItem icon={<Webhook size={17}/>} label="Webhooks" active={tab === 'Webhooks'} onClick={() => setTab('Webhooks')}/><p>Build</p><NavItem icon={<Code2 size={17}/>} label="API explorer" active={tab === 'API explorer'} onClick={() => setTab('API explorer')}/><NavItem icon={<Settings2 size={17}/>} label="Settings" active={tab === 'Settings'} onClick={() => setTab('Settings')}/></nav><div className="sidebar-bottom"><div className="help-card"><CircleHelp size={17}/><div><strong>Payment docs</strong><span>Learn the flow</span></div><ChevronRight size={15}/></div><div className="profile"><div className="avatar">TP</div><div><strong>Tuyen Pham</strong><span>Developer</span></div><MoreHorizontal size={17}/></div></div></aside><main className="main"><header className="topbar"><button className="mobile-menu"><Menu size={20}/></button><div className="crumb"><span>Payment operations</span><ChevronRight size={14}/><strong>{tab}</strong></div><div className="top-actions"><button className="icon-btn" aria-label="Search"><Search size={18}/></button><div className="avatar small">TP</div></div></header><div className="content">{checkoutResult ? <CheckoutResultPage result={checkoutResult} onRefresh={refreshCheckoutResult} onReturn={closeCheckoutResult} onTryAgain={() => { closeCheckoutResult(); setModal('checkout'); }}/>: <>{tab === 'Overview' && <Overview payments={payments} volume={volume} onCheckout={() => setModal('checkout')} onRetry={retryWebhook} onViewPayments={() => setTab('Payments')} onSelectPayment={openPayment}/>} {tab === 'Payments' && <Payments payments={visible} filter={filter} setFilter={setFilter} query={query} setQuery={setQuery} onRetry={retryWebhook} onSelectPayment={openPayment}/>} {tab === 'Payment detail' && selectedPayment && <PaymentDetail payment={selectedPayment} onBack={() => setTab('Payments')} onRetry={retryWebhook} onRefund={refundPayment}/>} {tab === 'Products' && <Products products={products} onCheckout={simulateCheckout}/>} {tab === 'Webhooks' && <Webhooks payments={payments} webhookEvents={webhookEvents} apiConnected={apiConnected} onRetry={retryWebhook}/>} {tab === 'API explorer' && <ApiExplorer onCopy={() => notify('Request copied to clipboard')}/>} {tab === 'Settings' && <Settings/>}</>}</div></main>{modal === 'checkout' && <CheckoutModal products={products} onClose={() => setModal(null)} onCreate={simulateCheckout}/>} {toast && <div className="toast"><Check size={16}/>{toast}</div>}</div>;
}
function CheckoutResultPage({ result, onRefresh, onReturn, onTryAgain }) {
  const cancelled = result.kind === 'cancelled';
  const paid = result.data?.payment_status === 'paid' || result.data?.status === 'succeeded';
  const failed = result.data?.status === 'failed' || result.data?.session_status === 'expired';
  const pending = !cancelled && !paid && !failed;
  const title = cancelled ? 'Checkout cancelled' : paid ? 'Payment successful' : failed ? 'Payment not completed' : 'Payment received';
  const message = cancelled
    ? 'Your checkout was cancelled. No payment was completed.'
    : paid
      ? `Your payment for ${result.data?.product_name || 'the selected product'} is confirmed.`
      : failed
        ? 'Stripe could not confirm this payment. You can return to the dashboard and try checkout again.'
        : 'Stripe returned you to the app. We are waiting for the signed webhook to confirm the final payment state.';
  return <div className="checkout-result-wrap"><section className={`checkout-result-card ${cancelled || failed ? 'result-muted' : paid ? 'result-paid' : 'result-pending'}`}>
    <div className="result-icon">{cancelled || failed ? <X size={25}/> : paid ? <Check size={25}/> : result.loading ? <RefreshCw size={24} className="spin"/> : <Clock3 size={25}/>}</div>
    <p className="eyebrow">STRIPE TEST CHECKOUT</p><h1>{title}</h1><p className="result-message">{message}</p>
    {result.data && <div className="result-summary"><div><span>Product</span><strong>{result.data.product_name}</strong></div><div><span>Amount</span><strong>{money(result.data.amount)} {(result.data.currency || 'usd').toUpperCase()}</strong></div><div><span>Payment state</span><strong className={`result-state ${result.data.status}`}>{result.data.status}</strong></div><div><span>Webhook</span><strong>{result.data.webhook_status}</strong></div></div>}
    {pending && <div className="pending-note"><ShieldCheck size={16}/><span>{result.loading ? 'Checking the checkout session…' : result.error ? 'The API could not confirm the session right now. The webhook may still update the dashboard.' : result.data?.confirmation_pending ? 'Confirmation is pending. This page does not mark the payment successful until Stripe confirms it.' : 'Use refresh to check the latest payment state.'}</span></div>}
    {result.error && <p className="result-error" role="alert">{result.error}</p>}
    <div className="result-actions">{pending && result.sessionId && <button className="outline" onClick={onRefresh} disabled={result.loading}><RefreshCw size={15}/> Refresh status</button>}{(cancelled || failed) && <button className="primary" onClick={onTryAgain}><CreditCard size={16}/> Try checkout again</button>}<button className={pending || paid ? 'primary' : 'outline'} onClick={onReturn}>Return to dashboard <ChevronRight size={15}/></button></div>
    {result.sessionId && <small className="result-session">Session {result.sessionId}</small>}
  </section></div>;
}
function NavItem({ icon, label, count, active, onClick }) { return <button className={`nav-item ${active ? 'active' : ''}`} onClick={onClick}>{icon}<span>{label}</span>{count && <b>{count}</b>}</button> }
function Overview({ payments, volume, onCheckout, onRetry, onViewPayments, onSelectPayment }) { const success = payments.filter(p => p.status === 'succeeded').length; return <><section className="hero"><div><p className="eyebrow">PAYMENT OPERATIONS <span className="live-dot"/> LIVE SIMULATION</p><h1>Good afternoon, Tuyen <span>✦</span></h1><p className="sub">A compact Stripe test-mode workspace for building reliable payment flows.</p></div><button className="primary" onClick={onCheckout}><CreditCard size={17}/> Create checkout</button></section><div className="stat-grid"><Stat icon={<ArrowUpRight size={17}/>} label="Test volume" value={money(volume)} detail="+18.4% this month" tone="mint"/><Stat icon={<Check size={17}/>} label="Successful payments" value={success} detail="96.8% success rate" tone="violet"/><Stat icon={<Clock3 size={17}/>} label="Pending webhooks" value={payments.filter(p => p.webhook !== 'processed').length} detail="1 retry scheduled" tone="amber"/><Stat icon={<ShieldCheck size={17}/>} label="Idempotency" value="100%" detail="Duplicate-safe writes" tone="blue"/></div><div className="grid-two"><section className="card chart-card"><div className="card-head"><div><h2>Payment volume</h2><p>Successful test payments · last 7 days</p></div><button className="select-btn">Last 7 days <ChevronRight size={14}/></button></div><div className="chart"><div className="chart-y"><span>$1k</span><span>$750</span><span>$500</span><span>$250</span><span>$0</span></div><div className="chart-body"><div className="bars">{[35,56,41,74,59,88,68].map((height, i) => <div className="bar-wrap" key={i}><div className="bar" style={{height: `${height}%`}}/><span>{['Mon','Tue','Wed','Thu','Fri','Sat','Sun'][i]}</span></div>)}</div><div className="chart-line"/></div></div><div className="chart-note"><span><i className="legend mint"/> Net volume</span><span>Updated just now <RefreshCw size={12}/></span></div></section><section className="card activity-card"><div className="card-head"><div><h2>Recent activity</h2><p>Latest payment events</p></div><button className="text-btn" onClick={onViewPayments}>View all <ChevronRight size={14}/></button></div><div className="activity-list">{payments.slice(0, 4).map(p => <div className="activity" key={p.id}><div className={`event-icon ${p.status}`}><Activity size={15}/></div><div className="activity-copy"><strong>{p.customer}</strong><span>{p.product} · {money(p.amount)}</span></div><Status status={p.status}/><small>{p.created.split(',')[0]}</small></div>)}</div></section></div><section className="card table-card"><div className="card-head"><div><h2>Transaction history</h2><p>Trace each payment from intent to webhook.</p></div><button className="outline" onClick={onViewPayments}>Open payments <ExternalLink size={14}/></button></div><PaymentTable payments={payments.slice(0, 5)} onRetry={onRetry} onSelectPayment={onSelectPayment}/></section></> }
function Stat({ icon, label, value, detail, tone }) { return <div className="card stat"><div className={`stat-icon ${tone}`}>{icon}</div><div><span>{label}</span><strong>{value}</strong><small>{detail}</small></div><div className="spark"><i/><i/><i/><i/><i/><i/></div></div> }
function Status({status}) { return <span className={`status ${status}`}><i/>{status}</span> }
function PaymentDetail({ payment, onBack, onRetry, onRefund }) {
  const [showRefundConfirm, setShowRefundConfirm] = useState(false);
  const [refundSubmitting, setRefundSubmitting] = useState(false);
  const timeline = [
    { title: 'Payment created', detail: `Checkout initiated for ${payment.product}`, time: payment.created, done: true },
    { title: 'Checkout session', detail: payment.checkoutSessionId || 'Stripe Checkout session', time: payment.created, done: Boolean(payment.checkoutSessionId) },
    { title: 'Webhook received', detail: payment.webhook === 'processed' ? 'Signature verified and event processed' : `Webhook ${payment.webhook}`, time: payment.paidAt || 'Awaiting Stripe event', done: payment.webhook === 'processed' },
    { title: payment.status === 'refunded' ? 'Payment refunded' : payment.status === 'failed' ? 'Payment failed' : payment.status === 'succeeded' ? 'Payment succeeded' : 'Final payment state', detail: payment.status === 'refunded' ? 'Stripe confirmed the test refund' : payment.status === 'succeeded' ? 'Payment confirmed by Stripe' : payment.status === 'failed' ? 'Stripe reported a failed payment' : 'Waiting for confirmation', time: payment.refundedAt || payment.paidAt || 'Pending', done: ['succeeded','failed','refunded'].includes(payment.status) },
  ];
  return <><section className="page-title detail-title"><div><button className="back-link" onClick={onBack}><ChevronRight size={14}/> Back to payments</button><p className="eyebrow">PAYMENT DETAIL</p><h1>Transaction details</h1><p className="sub">Inspect payment state, checkout identifiers, and event progress.</p></div><div className="detail-title-actions"><Status status={payment.status}/>{payment.refundAvailable && <button className="outline refund-button" onClick={() => setShowRefundConfirm(true)}><ArrowDownLeft size={15}/> Refund test payment</button>}{payment.webhook !== 'processed' && <button className="outline" onClick={() => onRetry(payment.id)}>Retry webhook <RefreshCw size={14}/></button>}</div></section>
    <div className="detail-layout"><div className="detail-main"><section className="card amount-card"><div><span>Payment amount</span><strong>{money(payment.amount)} <small>{(payment.currency || 'usd').toUpperCase()}</small></strong><p><Status status={payment.status}/><span className="detail-date">Created {payment.created}</span></p></div><div className="amount-icon"><CreditCard size={21}/></div></section>
      <section className="card details-card"><div className="card-head"><div><h2>Payment information</h2><p>Identifiers and checkout details from Laravel.</p></div></div><div className="detail-fields"><DetailField label="Payment ID" value={payment.id}/><DetailField label="Product" value={payment.product}/><DetailField label="Customer" value={payment.customer}/><DetailField label="Email" value={payment.email}/><DetailField label="PaymentIntent" value={payment.paymentIntentId || payment.id}/><DetailField label="Checkout Session" value={payment.checkoutSessionId || 'Not available in local demo'}/><DetailField label="Checkout URL" value={payment.checkoutUrl || '—'}/><DetailField label="Idempotency key" value={payment.idempotencyKey || 'Not exposed'}/><DetailField label="Paid at" value={payment.paidAt || (payment.status === 'succeeded' ? payment.created : '—')}/>{payment.refundStatus && <DetailField label="Refund status" value={payment.refundStatus}/>}{payment.refundId && <DetailField label="Stripe refund" value={payment.refundId}/>}{payment.refundedAt && <DetailField label="Refunded at" value={payment.refundedAt}/>}</div></section>
      <section className="card details-card"><div className="card-head"><div><h2>Webhook event log</h2><p>Verified delivery attempts for this transaction.</p></div><span className={`webhook ${payment.webhook}`}><i/>{payment.webhook}</span></div>{payment.webhookEvents?.length ? <div className="payment-event-list">{payment.webhookEvents.map(event=><div className="payment-event" key={event.id}><div className={`event-dot ${event.webhook}`}/><div className="payment-event-copy"><strong>{event.event}</strong><span>{event.id} · {event.created}</span><small>Attempt {event.attempts} · SHA-256 {event.payloadHash?.slice(0,16) || '—'}…</small>{event.lastError&&<small className="event-error">{event.lastError}</small>}</div><span className={`webhook ${event.webhook}`}><i/>{event.webhook}</span></div>)}</div> : <div className="empty-events"><Webhook size={18}/><span>No verified webhook events recorded yet.</span></div>}</section></div>
      <aside className="card timeline-card"><div className="card-head"><div><h2>Payment timeline</h2><p>Lifecycle events</p></div></div><div className="timeline">{timeline.map((item,index)=><div className={`timeline-item ${item.done?'done':''}`} key={item.title}><span className="timeline-marker">{item.done?<Check size={11}/>:index+1}</span><div><strong>{item.title}</strong><p>{item.detail}</p><small>{item.time}</small></div></div>)}</div><div className="timeline-note"><ShieldCheck size={15}/><span>Stripe webhooks are the source of truth for final payment status.</span></div></aside></div>{showRefundConfirm && <div className="modal-backdrop"><div className="modal refund-modal" role="dialog" aria-modal="true" aria-labelledby="refund-title"><div className="modal-head"><div><p className="eyebrow">STRIPE TEST MODE</p><h2 id="refund-title">Refund this payment?</h2><p>This sends a full refund request to Stripe for {money(payment.amount)}.</p></div><button className="icon-btn" onClick={() => setShowRefundConfirm(false)} aria-label="Close"><X size={18}/></button></div><div className="test-card"><ShieldCheck size={16}/><div><strong>Test refund only</strong><span>Live Stripe keys are rejected by the API.</span></div></div><div className="modal-actions"><button className="outline" onClick={() => setShowRefundConfirm(false)} disabled={refundSubmitting}>Keep payment</button><button className="primary refund-confirm" disabled={refundSubmitting} onClick={async () => { setRefundSubmitting(true); const completed = await onRefund(payment); setRefundSubmitting(false); if (completed) setShowRefundConfirm(false); }}>{refundSubmitting ? 'Submitting…' : 'Confirm refund'} <ArrowDownLeft size={15}/></button></div></div></div>}</>;
}
function DetailField({label,value}) { return <div className="detail-field"><span>{label}</span><strong title={value}>{value}</strong></div> }
function PaymentTable({ payments, onRetry, onSelectPayment }) { return <div className="table-wrap"><table><thead><tr><th>Payment</th><th>Product</th><th>Amount</th><th>Status</th><th>Webhook</th><th></th></tr></thead><tbody>{payments.map(p => <tr key={p.id}><td><div className="payment-cell"><span className="payment-avatar">{p.customer.split(' ').map(x=>x[0]).join('')}</span><div><strong>{p.customer}</strong><small>{p.id}</small></div></div></td><td>{p.product}<small>{p.method}</small></td><td className="amount">{money(p.amount)}</td><td><Status status={p.status}/></td><td><span className={`webhook ${p.webhook}`}><i/>{p.webhook}</span></td><td><div className="row-actions">{(p.webhook === 'retrying' || p.webhook === 'pending') && <button className="row-action" onClick={() => onRetry(p.id)} title="Retry webhook" aria-label={`Retry ${p.id} webhook`}><RefreshCw size={15}/></button>}<button className="row-action" title="View payment details" aria-label={`View ${p.id} details`} onClick={() => onSelectPayment?.(p)}><ChevronRight size={16}/></button></div></td></tr>)}</tbody></table></div> }
function Payments({ payments, filter, setFilter, query, setQuery, onRetry, onSelectPayment }) { return <><section className="page-title"><div><p className="eyebrow">PAYMENTS</p><h1>All payments</h1><p className="sub">Search payment intents and inspect their webhook lifecycle.</p></div></section><section className="card table-card"><div className="toolbar"><div className="search"><Search size={16}/><input value={query} onChange={e=>setQuery(e.target.value)} placeholder="Search customer or payment ID"/></div><div className="filters">{['all','succeeded','processing','failed','refunded'].map(s => <button key={s} className={filter===s?'selected':''} onClick={()=>setFilter(s)}>{s}</button>)}</div></div><PaymentTable payments={payments} onRetry={onRetry} onSelectPayment={onSelectPayment}/></section></> }
function Products({ products, onCheckout }) { return <><section className="page-title"><div><p className="eyebrow">CATALOG</p><h1>Demo products</h1><p className="sub">Products are intentionally simple so the payment state machine stays visible.</p></div></section><div className="product-grid">{products.map(p => <div className="card product" key={p.name}><div className={`product-icon ${p.color}`}><Package size={20}/></div><div className="product-top"><span className="tag">ACTIVE</span><MoreHorizontal size={17}/></div><h2>{p.name}</h2><p>{p.detail}</p><strong>{money(p.amount, p.currency)}<small> / one-time</small></strong><button className="outline full" onClick={()=>onCheckout(p)}>Run test checkout <ArrowUpRight size={15}/></button></div>)}</div></> }
function Webhooks({payments,webhookEvents,apiConnected}) { const events = (apiConnected || webhookEvents.length) ? webhookEvents : payments.map((p,i)=>({ ...p, event: p.status==='succeeded'?'payment_intent.succeeded':p.status==='failed'?'payment_intent.payment_failed':'payment_intent.processing', latency: `${120+i*37} ms`, attempts: 1 })); const processedCount = events.filter(e=>e.webhook==='processed').length; const retryCount = events.filter(e=>['failed','retrying','received'].includes(e.webhook)).length; return <><section className="page-title"><div><p className="eyebrow">EVENT DELIVERY</p><h1>Webhook health</h1><p className="sub">Signature verification, retry state, and idempotent event handling.</p></div><button className="primary"><Webhook size={16}/> Endpoint settings</button></section><div className="webhook-summary"><div className="card summary"><span className="summary-icon mint"><ShieldCheck size={17}/></span><div><strong>Signature verification</strong><span>Invalid signatures are rejected</span></div><b>{events.length}</b></div><div className="card summary"><span className="summary-icon violet"><RefreshCw size={17}/></span><div><strong>Processed events</strong><span>Recorded as verified</span></div><b>{processedCount}</b></div><div className="card summary"><span className="summary-icon amber"><Clock3 size={17}/></span><div><strong>Needs attention</strong><span>Failed, received, or retrying</span></div><b>{retryCount}</b></div></div><section className="card table-card"><div className="card-head"><div><h2>Event delivery log</h2><p>Newest events appear first.</p></div><button className="outline"><FileText size={14}/> View runbook</button></div><div className="event-log">{events.length === 0 ? <div className="empty-events"><Webhook size={18}/><span>No verified webhook events have arrived yet.</span></div> : events.map(e=><div className="log-row" key={e.id}><div className={`event-dot ${e.webhook}`}/><div className="log-main"><strong>{e.event}</strong><span>{e.id} · {e.created}{e.paymentName ? ` · ${e.paymentName}` : ''}</span>{e.lastError&&<small className="event-error">{e.lastError}</small>}</div><span className={`webhook ${e.webhook}`}><i/>{e.webhook}</span><span className="latency">{e.attempts} {e.attempts===1?'attempt':'attempts'}</span></div>)}</div></section></> }
function ApiExplorer({onCopy}) { const request = `curl https://api.stripe.com/v1/payment_intents \\\n  -u sk_test_••••••••: \\\n  -d amount=8900 \\\n  -d currency=usd \\\n  -d "metadata[order_id]"=order_demo_1042 \\\n  -H "Idempotency-Key: checkout-demo-1042"`; return <><section className="page-title"><div><p className="eyebrow">DEVELOPER TOOLS</p><h1>API explorer</h1><p className="sub">A readable request example for the payment creation flow.</p></div></section><div className="api-grid"><section className="card code-card"><div className="code-head"><span><i/> POST</span><strong>/v1/payment_intents</strong><button className="row-action" onClick={onCopy}><Copy size={15}/></button></div><pre>{request}</pre><div className="code-foot"><span><ShieldCheck size={14}/> Secret keys stay server-side</span><span><Zap size={14}/> Idempotency enabled</span></div></section><section className="card explain"><div className="explain-icon"><Sparkles size={19}/></div><h2>What this demo proves</h2><ul><li><Check size={15}/> Payment creation is retriable without double charging.</li><li><Check size={15}/> Webhooks are the source of truth for final state.</li><li><Check size={15}/> Failed events expose a clear recovery path.</li></ul></section></div></> }
function Settings(){ return <><section className="page-title"><div><p className="eyebrow">WORKSPACE</p><h1>Settings</h1><p className="sub">Environment and integration details for this portfolio demo.</p></div></section><div className="settings-grid"><section className="card settings-card"><h2>Environment</h2><label>Mode<select defaultValue="test"><option value="test">Test mode</option><option value="live">Live mode</option></select></label><label>Webhook endpoint<input value="/api/webhooks/stripe" readOnly/></label><label>Default currency<input value="USD · United States Dollar" readOnly/></label><button className="primary">Save settings</button></section><section className="card safety-card"><span className="summary-icon mint"><ShieldCheck size={17}/></span><h2>Demo safety guardrails</h2><p>This project never uses real card data. All checkout actions stay in Stripe test mode and the UI uses safe sample identities.</p><div className="safety-row"><Check size={15}/> No secrets committed</div><div className="safety-row"><Check size={15}/> Webhook signature required</div><div className="safety-row"><Check size={15}/> Duplicate events are ignored</div></section></div></> }
function CheckoutModal({products,onClose,onCreate}){ const [selected,setSelected]=useState(products[1] || products[0] || null); return <div className="modal-backdrop"><div className="modal"><div className="modal-head"><div><p className="eyebrow">TEST MODE CHECKOUT</p><h2>Start secure checkout</h2><p>Choose a product to create a safe demo transaction.</p></div><button className="icon-btn" onClick={onClose} aria-label="Close"><X size={18}/></button></div>{products.length ? <><div className="checkout-products">{products.map(p=><button key={p.name} className={`checkout-product ${selected?.id===p.id?'selected':''}`} onClick={()=>setSelected(p)}><div className={`product-icon ${p.color}`}><Package size={18}/></div><span><strong>{p.name}</strong><small>{p.detail}</small></span><b>{money(p.amount, p.currency)}</b></button>)}</div><div className="test-card"><CreditCard size={16}/><div><strong>Test card</strong><span>4242 4242 4242 4242 · any future date · any CVC</span></div></div></> : <p>No active products are available.</p>}<div className="modal-actions"><button className="outline" onClick={onClose}>Cancel</button><button className="primary" disabled={!selected} onClick={()=>selected && onCreate(selected)}>Create test payment <ArrowUpRight size={15}/></button></div></div></div> }
createRoot(document.getElementById('root')).render(<App/>);
