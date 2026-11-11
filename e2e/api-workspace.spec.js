import { expect, test } from "@playwright/test";

const product = {
  id: 1,
  name: "Euro catalog",
  description: "Database catalog",
  price_cents: 4500,
  currency: "eur",
};
const payment = {
  id: 1,
  stripe_payment_intent_id: "pi_euro",
  customer_name: "Euro customer",
  customer_email: "euro@example.test",
  product_name: "Euro catalog",
  amount: 4500,
  currency: "eur",
  status: "succeeded",
  webhook_status: "processed",
};

test("catalog failure displays retry without a sample-price fallback", async ({
  page,
}) => {
  let calls = 0;
  await page.route("**/api/products", (route) => {
    calls++;
    return route.fulfill(
      calls === 1
        ? { status: 503, json: { message: "Catalog offline" } }
        : { json: { data: [product] } },
    );
  });
  await page.goto("/");
  await expect(page.getByRole("alert")).toContainText("Catalog offline");
  await page.getByRole("button", { name: "Products", exact: true }).click();
  await expect(page.getByText("Starter plan", { exact: true })).toHaveCount(0);
  await page.getByRole("button", { name: "Retry catalog" }).click();
  await expect(
    page.getByRole("heading", { name: "Euro catalog" }),
  ).toBeVisible();
  await expect(page.getByText("€45.00")).toBeVisible();
});

test("admin can load another page, filter events, and refresh with errors visible", async ({
  page,
}) => {
  let fail = false;
  await page.route("**/api/**", (route) => {
    const request = route.request(),
      url = new URL(request.url());
    if (url.pathname === "/api/products")
      return route.fulfill({ json: { data: [product] } });
    if (url.pathname === "/api/admin/login")
      return route.fulfill({
        json: {
          token: "workspace-test-token",
          expires_at: new Date(Date.now() + 3600000).toISOString(),
          user: { name: "Admin" },
        },
      });
    expect(request.headers().authorization).toBe("Bearer workspace-test-token");
    if (url.pathname === "/api/payments") {
      if (fail)
        return route.fulfill({
          status: 503,
          json: { message: "Records temporarily unavailable" },
        });
      const pageNumber = Number(url.searchParams.get("page"));
      return route.fulfill({
        json: {
          current_page: pageNumber,
          last_page: 2,
          data: [
            pageNumber === 2
              ? {
                  ...payment,
                  id: 2,
                  stripe_payment_intent_id: "pi_second",
                  customer_name: "Second customer",
                  currency: "usd",
                }
              : payment,
          ],
        },
      });
    }
    if (url.pathname === "/api/webhook-events")
      return route.fulfill({
        json: {
          current_page: 1,
          last_page: 1,
          data: [
            {
              event_id: "evt_logged",
              type: "checkout.session.completed",
              status: "processed",
              attempts: 1,
              payment_id: 1,
              payment: { customer_name: "Euro customer" },
            },
          ],
        },
      });
    throw new Error(`Unexpected request ${url.pathname}`);
  });
  await page.goto("/");
  await page
    .getByRole("button", { name: "Admin sign in", exact: true })
    .click();
  await page.getByLabel("Email", { exact: true }).fill("admin@example.test");
  await page.getByLabel("Password", { exact: true }).fill("test-password");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(page.getByText("€45.00").first()).toBeVisible();
  await page.getByRole("button", { name: "Load more payments" }).click();
  await expect(page.getByText("Second customer").first()).toBeVisible();
  await expect(page.locator(".volume-list")).toContainText("€45.00");
  await expect(page.locator(".volume-list")).toContainText("$45.00");
  await expect(page.locator(".volume-list")).not.toContainText("€90.00");
  await expect(
    page.getByRole("button", { name: "Load more payments" }),
  ).toHaveCount(0);
  await page.getByRole("button", { name: "Webhooks", exact: true }).click();
  await page
    .getByRole("textbox", { name: "Search webhook events" })
    .fill("evt_logged");
  await expect(
    page.getByText("checkout.session.completed", { exact: true }),
  ).toBeVisible();
  await page.getByLabel("Delivery status").selectOption("failed");
  await expect(page.getByText("No events match these filters.")).toBeVisible();
  await page.getByRole("button", { name: "Endpoint settings" }).click();
  await expect(
    page.getByRole("heading", { name: "Settings", exact: true }),
  ).toBeVisible();
  fail = true;
  await page.getByRole("button", { name: "Refresh records" }).click();
  await expect(page.getByRole("alert")).toHaveText(
    "Records temporarily unavailable",
  );
});

for (const [status, title] of [
  ["processing", "Awaiting payment confirmation"],
  ["succeeded", "Payment successful"],
  ["refunded", "Payment refunded"],
]) {
  test(`checkout result renders ${status} and the correct currency`, async ({
    page,
  }) => {
    await page.route("**/api/products", (route) =>
      route.fulfill({ json: { data: [product] } }),
    );
    await page.route("**/api/checkout/sessions/cs_euro", (route) =>
      route.fulfill({
        json: {
          data: {
            ...payment,
            status,
            payment_status: status === "processing" ? "unpaid" : "paid",
            confirmation_pending: status === "processing",
          },
        },
      }),
    );
    await page.goto("/?checkout=success&session_id=cs_euro");
    await expect(
      page.getByRole("heading", { name: title, exact: true }),
    ).toBeVisible();
    await expect(page.getByText("€45.00 EUR")).toBeVisible();
    await page.getByRole("button", { name: "Return to dashboard" }).click();
    await expect(page).toHaveURL("/");
  });
}

for (const [refundStatus, message] of [
  ["succeeded", "Test refund completed"],
  ["pending", "Refund submitted; waiting for Stripe confirmation"],
  ["requires_action", "Refund requires action; review it in the Stripe Dashboard"],
  ["failed", "Refund failed; review it in the Stripe Dashboard before another attempt"],
  ["canceled", "Refund canceled; review it in the Stripe Dashboard before another attempt"],
]) {
  test(`full refund ${refundStatus} preserves dialog behavior and shows accurate guidance`, async ({
    page,
  }) => {
    let refunded = false,
      refundCalls = 0,
      release;
    const held = new Promise((resolve) => {
      release = resolve;
    });
    await page.route("**/api/**", async (route) => {
      const path = new URL(route.request().url()).pathname;
      if (path === "/api/products")
        return route.fulfill({ json: { data: [product] } });
      if (path === "/api/admin/login")
        return route.fulfill({
          json: {
            token: "refund-test-token",
            expires_at: new Date(Date.now() + 3600000).toISOString(),
            user: { name: "Admin" },
          },
        });
      expect(route.request().headers().authorization).toBe(
        "Bearer refund-test-token",
      );
      if (path === "/api/payments")
        return route.fulfill({ json: { data: [payment] } });
      if (path === "/api/webhook-events")
        return route.fulfill({ json: { data: [] } });
      if (path === "/api/payments/1/refund") {
        refundCalls++;
        await held;
        refunded = true;
        return route.fulfill({
          json: {
            data: { ...payment, status: refundStatus === "succeeded" ? "refunded" : "succeeded", refund_status: refundStatus },
            refund_status: refundStatus,
          },
        });
      }
      if (path === "/api/payments/1")
        return route.fulfill({
          json: {
            data: {
              ...payment,
              status: refunded && refundStatus === "succeeded" ? "refunded" : "succeeded",
              refund_available: !refunded,
              refund_status: refunded ? refundStatus : null,
            },
          },
        });
      throw new Error(`Unexpected request ${path}`);
    });
    await page.goto("/");
    await page
      .getByRole("button", { name: "Admin sign in", exact: true })
      .click();
    await page.getByLabel("Email", { exact: true }).fill("admin@example.test");
    await page.getByLabel("Password", { exact: true }).fill("test-password");
    await page.getByRole("button", { name: "Sign in", exact: true }).click();
    await page.getByRole("button", { name: "View pi_euro details" }).click();
    await page.getByRole("button", { name: "Refund test payment" }).click();
    await expect(page.getByRole("dialog")).toContainText("€45.00");
    await expect(
      page.getByRole("button", { name: "Close refund" }),
    ).toBeFocused();
    await page.keyboard.press("Shift+Tab");
    await expect(
      page.getByRole("button", { name: "Confirm refund" }),
    ).toBeFocused();
    await page.getByRole("button", { name: "Confirm refund" }).click();
    await expect(
      page.getByRole("button", { name: "Close refund" }),
    ).toBeDisabled();
    await page.keyboard.press("Escape");
    await expect(page.getByRole("dialog")).toBeVisible();
    expect(refundCalls).toBe(1);
    release();
    await expect(page.getByRole("dialog")).toHaveCount(0);
    await expect(
      page.getByRole("status").filter({ hasText: message }),
    ).toBeVisible();
    await expect(
      page.getByRole("button", { name: "Refund test payment" }),
    ).toHaveCount(0);
  });

}
