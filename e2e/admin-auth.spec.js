import { expect, test } from '@playwright/test';

const payment = {
  id: 1, stripe_payment_intent_id: 'pi_admin_test', customer_name: 'Private API Customer',
  customer_email: 'private@example.com', product_name: 'Database Pro', amount: 8900,
  currency: 'usd', status: 'succeeded', webhook_status: 'processed',
};

async function mockApi(page, detailHandler) {
  const requests = { payments: 0, logout: 0 };
  await page.route('**/api/**', async route => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    const respond = (json, status = 200) => route.fulfill({ status, json });
    if (path === '/api/products') return respond({ data: [{ id: 1, name: 'Database Pro', description: 'Public catalog', price_cents: 8900, currency: 'usd' }] });
    if (path === '/api/admin/login') {
      const credentials = request.postDataJSON();
      if (credentials.password !== 'test-browser-password') return respond({ message: 'Invalid administrator credentials.' }, 401);
      return respond({ token: 'test-browser-token', expires_at: new Date(Date.now() + 3600000).toISOString(), user: { id: 1, name: 'Admin', email: 'admin@example.com', role: 'admin' } });
    }
    expect(request.headers().authorization).toBe('Bearer test-browser-token');
    if (path === '/api/payments') { requests.payments++; return respond({ data: [payment] }); }
    if (path === '/api/webhook-events') return respond({ data: [] });
    if (path === '/api/admin/logout') { requests.logout++; return route.fulfill({ status: 204 }); }
    if (path === '/api/payments/1' && detailHandler) return detailHandler(route);
    throw new Error(`Unexpected API request: ${path}`);
  });
  return requests;
}

async function login(page) {
  await page.getByRole('button', { name: 'Admin sign in', exact: true }).click();
  await page.getByLabel('Email', { exact: true }).fill('admin@example.com');
  await page.getByLabel('Password', { exact: true }).fill('test-browser-password');
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Sign out', exact: true })).toBeVisible();
  await expect(page.getByText('Private API Customer').first()).toBeVisible();
}

test('admin sign in uses a memory-only token and sign out clears private records', async ({ page }) => {
  const requests = await mockApi(page);
  await page.goto('/');
  await expect(page.getByRole('button', { name: 'Admin sign in' })).toBeVisible();
  expect(requests.payments).toBe(0);
  await page.getByRole('button', { name: 'Products', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Database Pro' })).toBeVisible();
  await page.getByRole('button', { name: 'Overview', exact: true }).click();
  await page.getByRole('button', { name: 'Admin sign in' }).click();
  await page.getByLabel('Email', { exact: true }).fill('admin@example.com');
  await page.getByLabel('Password', { exact: true }).fill('wrong-password');
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page.getByRole('alert')).toHaveText('Invalid administrator credentials.');
  expect(requests.payments).toBe(0);
  await page.getByLabel('Password', { exact: true }).fill('test-browser-password');
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page.getByText('Private API Customer').first()).toBeVisible();
  expect(await page.evaluate(() => JSON.stringify([localStorage, sessionStorage]))).not.toContain('test-browser-token');
  await page.reload();
  await expect(page.getByRole('button', { name: 'Admin sign in' })).toBeVisible();
  await expect(page.getByText('Private API Customer')).toHaveCount(0);
  expect(requests.payments).toBe(1);
  await login(page);
  await page.getByRole('button', { name: 'Sign out', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Admin sign in' })).toBeVisible();
  await expect(page.getByText('Private API Customer')).toHaveCount(0);
  expect(requests.logout).toBe(1);
});

for (const status of [401, 403]) {
test(`ended admin access (${status}) clears private data and requires another sign in`, async ({ page }) => {
  await mockApi(page, route => route.fulfill({ status, json: { message: 'Admin access ended.' } }));
  await page.goto('/');
  await login(page);
  await page.getByRole('button', { name: 'View pi_admin_test details' }).click();
  await expect(page.getByRole('button', { name: 'Admin sign in' })).toBeVisible();
  await expect(page.getByText('Private API Customer')).toHaveCount(0);
});

}

test('a late private response cannot restore data after sign out', async ({ page }) => {
  let release;
  let requested;
  const detailRequested = new Promise(resolve => { requested = resolve; });
  const held = new Promise(resolve => { release = resolve; });
  await mockApi(page, async route => {
    requested();
    await held;
    await route.fulfill({ json: { data: payment } });
  });
  await page.goto('/');
  await login(page);
  await page.getByRole('button', { name: 'View pi_admin_test details' }).click();
  await detailRequested;
  await page.getByRole('button', { name: 'Sign out', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Admin sign in' })).toBeVisible();
  const lateResponse = page.waitForResponse(response => response.url().endsWith('/api/payments/1'));
  release();
  await lateResponse;
  await expect(page.getByText('Private API Customer')).toHaveCount(0);
});
