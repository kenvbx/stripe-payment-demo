import { expect, test } from '@playwright/test';

test('shows the demo product catalog and prices', async ({ page }) => {
  await page.goto('/');
  await page.getByRole('button', { name: 'Products' }).click();

  await expect(page.getByRole('heading', { name: 'Demo products' })).toBeVisible();
  await expect(page.getByText('Starter plan', { exact: true })).toBeVisible();
  await expect(page.getByText('Pro workspace', { exact: true })).toBeVisible();
  await expect(page.getByText('Team plan', { exact: true })).toBeVisible();
  await expect(page.getByText('$29.00')).toBeVisible();
  await expect(page.getByText('$89.00')).toBeVisible();
  await expect(page.getByText('$149.00')).toBeVisible();
});

test('selects a catalog product and creates a local demo payment', async ({ page }) => {
  await page.goto('/');
  await page.getByRole('button', { name: 'Create checkout' }).click();

  await expect(page.getByRole('heading', { name: 'Start secure checkout' })).toBeVisible();
  await expect(page.getByText('4242 4242 4242 4242')).toBeVisible();
  await page.getByRole('button', { name: /Pro workspace.*\$89\.00/ }).click();
  await expect(page.getByRole('button', { name: 'Create test payment' })).toBeEnabled();
  await page.getByRole('button', { name: 'Create test payment' }).click();

  await expect(page.getByText('Checkout session created in local demo mode')).toBeVisible();
  await page.getByRole('navigation').getByRole('button', { name: /^Payments/ }).click();
  await expect(page.getByText('Demo customer')).toBeVisible();
  await expect(page.getByText('Pro workspace').last()).toBeVisible();
});

test('retry without a verified event cannot turn a failed payment into success', async ({ page }) => {
  await page.goto('/');
  await page.getByRole('navigation').getByRole('button', { name: /^Payments/ }).click();
  await page.getByRole('button', { name: 'failed', exact: true }).click();
  const row = page.getByRole('row').filter({ hasText: 'pi_3Qx7m4P8' });
  await expect(row).toBeVisible();
  await row.getByRole('button', { name: 'Retry pi_3Qx7m4P8 webhook' }).click();
  await expect(page.getByText('Retry requires the Laravel API and a stored verified event')).toBeVisible();
  await expect(row.getByText('failed', { exact: true })).toBeVisible();
});
