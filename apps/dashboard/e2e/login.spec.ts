import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";

test.describe("login page", () => {
  test("renders the form", async ({ page }) => {
    await page.goto("/login");

    await expect(page.getByRole("heading", { name: "mkEngage" })).toBeVisible();
    await expect(page.getByLabel("Organization")).toBeVisible();
    await expect(page.getByLabel("Email")).toBeVisible();
    await expect(page.getByLabel("Password")).toBeVisible();
    await expect(page.getByRole("button", { name: "Sign in" })).toBeEnabled();
  });

  test("redirects unauthenticated app routes to login", async ({ page }) => {
    // "/" is now the public marketing home; the app lives behind auth.
    await page.goto("/dashboard");
    await expect(page).toHaveURL(/\/login$/);

    await page.goto("/settings/profile");
    await expect(page).toHaveURL(/\/login$/);
  });

  test("serves the public marketing home at /", async ({ page }) => {
    await page.goto("/");
    await expect(page).toHaveURL(/\/$/);
    await expect(page.getByRole("link", { name: "Start Free Trial" }).first()).toBeVisible();
  });

  test("has no serious accessibility violations (light and dark)", async ({ page }) => {
    await page.goto("/login");

    for (const theme of ["light", "dark"] as const) {
      await page.evaluate((t) => {
        document.documentElement.classList.toggle("dark", t === "dark");
      }, theme);

      const results = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();

      const serious = results.violations.filter((v) =>
        ["serious", "critical"].includes(v.impact ?? ""),
      );
      expect(serious, `${theme}: ${serious.map((v) => v.id).join(", ")}`).toEqual([]);
    }
  });
});
