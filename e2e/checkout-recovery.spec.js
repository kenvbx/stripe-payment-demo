import { test, expect } from '@playwright/test';

const products = [
  { id: 1, name: 'Recovery product', price_cents: 2900, currency: 'usd' },
  { id: 2, name: 'Other product', price_cents: 5900, currency: 'usd' },
];
async function setup(page, checkout) {
  await page.route('**/api/**', route => {
    const path = new URL(route.request().url()).pathname;
    if (path === '/api/products') return route.fulfill({ json: { data: products } });
    if (path === '/api/payments/checkout') return checkout(route);
    throw new Error(`Unexpected public request: ${path}`);
  });
  await page.goto('/');
}
async function openCheckout(page) {
  await page.getByRole('button', { name: 'Create checkout', exact: true }).click();
  await page.getByRole('button', { name: /Recovery product.*29/ }).click();
}

test('ambiguous API failure retains the key across reload and recovers without a fake payment', async ({ page }) => {
  const payloads = [];
  await setup(page, route => {
    payloads.push(route.request().postDataJSON());
    if (payloads.length === 1) return route.fulfill({ status: 503, json: { message: 'Retry the same checkout.', retryable: true } });
    return route.fulfill({ json: { data: { id: 7, customer_name: 'Demo customer', customer_email: 'demo@example.com', product_name: 'Recovery product', amount: 2900, currency: 'usd', status: 'processing', webhook_status: 'pending' }, checkout_mode: 'stripe', idempotent_replay: true, checkout_url: null } });
  });
  await openCheckout(page);
  await page.getByRole('button', { name: 'Create test payment' }).click();
  await expect(page.getByRole('alert')).toHaveText('Retry the same checkout.');
  await expect(page.getByRole('dialog')).toBeVisible();
  await expect(page.locator('.toast')).toHaveCount(0);
  await expect(page.getByRole('navigation').getByRole('button', { name: /^Payments/ })).toHaveText('Payments0');
  await page.reload();
  await openCheckout(page);
  await page.getByRole('button', { name: 'Create test payment' }).click();
  await expect(page.getByText('Existing checkout recovered')).toBeVisible();
  expect(payloads).toHaveLength(2);
  expect(payloads[1]).toEqual(payloads[0]);
  expect(await page.evaluate(() => sessionStorage.getItem('payflow.pending-checkouts'))).toBe('{}');
});

test('pending checkout disables repeated submissions and product switches use separate keys', async ({ page }) => {
  const payloads = [];
  let release;
  const held = new Promise(resolve => { release = resolve; });
  await setup(page, async route => {
    payloads.push(route.request().postDataJSON());
    if (payloads.length === 1) await held;
    return route.fulfill({ status: 422, json: { message: 'The given data was invalid.', errors: { product_id: ['Product unavailable.'] } } });
  });
  await openCheckout(page);
  await page.getByRole('button', { name: 'Create test payment' }).dblclick();
  await expect(page.getByRole('button', { name: 'Creating checkout…' })).toBeDisabled();
  await expect(page.getByRole('button', { name: 'Cancel', exact: true })).toBeDisabled();
  await expect(page.getByRole('button', { name: /Other product.*59/ })).toBeDisabled();
  expect(payloads).toHaveLength(1);
  release();
  await expect(page.getByRole('alert')).toHaveText('Product unavailable.');
  await page.getByRole('button', { name: /Other product.*59/ }).click();
  await page.getByRole('button', { name: 'Create test payment' }).click();
  await expect.poll(() => payloads.length).toBe(2);
  expect(payloads[1].product_id).toBe(2);
  expect(payloads[1].idempotency_key).not.toBe(payloads[0].idempotency_key);
  await expect(page.locator('.toast')).toHaveCount(0);
});
