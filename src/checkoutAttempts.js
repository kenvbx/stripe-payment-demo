const storageKey = 'payflow.pending-checkouts';
let attempts = {};
try {
  const stored = JSON.parse(sessionStorage.getItem(storageKey) || '{}');
  if (stored && typeof stored === 'object' && !Array.isArray(stored)) attempts = stored;
} catch { /* In-memory keys still survive retries when browser storage is unavailable. */ }

function persist() {
  try { sessionStorage.setItem(storageKey, JSON.stringify(attempts)); } catch { /* Storage is optional. */ }
}

export function checkoutKey(productId) {
  if (typeof attempts[productId] !== 'string' || !attempts[productId]) {
    attempts[productId] = `checkout-${crypto.randomUUID()}`;
    persist();
  }
  return attempts[productId];
}

export function finishCheckout(productId, key) {
  if (attempts[productId] === key) { delete attempts[productId]; persist(); }
}
