import { expect, test } from "@playwright/test";

for (const width of [375, 768, 1024, 1440]) {
  test(`workspace navigation and layout at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    await page.emulateMedia({ reducedMotion: "reduce" });
    await page.goto("/");
    if (width < 760) {
      await page.getByRole("button", { name: "Open navigation" }).click();
      await expect(
        page.locator('button[aria-controls="workspace-navigation"]'),
      ).toHaveAttribute("aria-expanded", "true");
    }
    await page.getByRole("button", { name: "Products", exact: true }).click();
    await expect(
      page.getByRole("heading", { name: "Demo products" }),
    ).toBeVisible();
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
    ).toBe(true);
    if (width < 760) {
      await expect(
        page.getByRole("button", { name: "Open navigation" }),
      ).toBeFocused();
      await page.getByRole("button", { name: "Open navigation" }).click();
      await page.keyboard.press("Escape");
      await expect(
        page.getByRole("button", { name: "Open navigation" }),
      ).toBeFocused();
      await expect(
        page.getByRole("button", { name: "Open navigation" }),
      ).toHaveAttribute("aria-expanded", "false");
    }
  });
}

test("API explorer copies the Laravel contract and settings are read-only", async ({
  page,
  context,
}) => {
  await context.grantPermissions(["clipboard-read", "clipboard-write"]);
  await page.goto("/");
  await page.getByRole("button", { name: "API explorer", exact: true }).click();
  await page.getByRole("button", { name: "Copy checkout request" }).click();
  await expect(page.getByRole("status")).toHaveText(
    "Request copied to clipboard",
  );
  const copied = await page.evaluate(() => navigator.clipboard.readText());
  expect(copied).toContain("/api/payments/checkout");
  expect(copied).toContain("product_id");
  expect(copied).not.toContain("sk_test");
  await page.getByRole("button", { name: "Settings", exact: true }).click();
  await expect(page.getByLabel("Payment mode")).toHaveValue(
    "Stripe test mode only",
  );
  await expect(page.getByLabel("Payment mode")).toHaveAttribute("readonly", "");
  await expect(page.getByRole("button", { name: "Save settings" })).toHaveCount(
    0,
  );
});

test("search exposes an empty state and preserves keyboard access", async ({
  page,
}) => {
  await page.goto("/");
  await page
    .getByRole("button", { name: "Search payments", exact: true })
    .click();
  await expect(
    page.getByRole("textbox", { name: "Search payments" }),
  ).toBeFocused();
  await page
    .getByRole("textbox", { name: "Search payments" })
    .fill("no-such-customer");
  await expect(page.getByText(/No payments to show/)).toBeVisible();
});

test("resizing an open mobile menu restores the workspace in landscape", async ({
  page,
}) => {
  await page.setViewportSize({ width: 375, height: 812 });
  await page.goto("/");
  await page.getByRole("button", { name: "Open navigation" }).click();
  await page.setViewportSize({ width: 812, height: 375 });
  await expect(page.locator("main")).not.toHaveAttribute("inert");
  await page.getByRole("button", { name: "Products", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Demo products" }),
  ).toBeVisible();
});
