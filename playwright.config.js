import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './e2e',
  fullyParallel: true,
  reporter: 'list',
  use: { trace: 'retain-on-failure', ...devices['Desktop Chrome'] },
  projects: [
    { name: 'demo', testMatch: 'catalog-checkout.spec.js', use: { baseURL: 'http://127.0.0.1:4173' } },
    { name: 'admin', testMatch: ['admin-auth.spec.js', 'checkout-recovery.spec.js'], use: { baseURL: 'http://127.0.0.1:4174' } },
  ],
  webServer: [
    {
      command: 'npm run dev -- --host 127.0.0.1 --port 4173 --strictPort',
      url: 'http://127.0.0.1:4173', reuseExistingServer: !process.env.CI,
      env: { VITE_USE_API: 'false', VITE_API_URL: '/api' }, timeout: 30_000,
    },
    {
      command: 'npm run dev -- --host 127.0.0.1 --port 4174 --strictPort',
      url: 'http://127.0.0.1:4174', reuseExistingServer: !process.env.CI,
      env: { VITE_USE_API: 'true', VITE_API_URL: '/api' }, timeout: 30_000,
    },
  ],
});
