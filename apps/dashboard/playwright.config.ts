import { defineConfig, devices } from "@playwright/test";

/**
 * E2E + Axe accessibility (§3/§26). Runs against a dev server it starts
 * itself; specs needing the control-plane API skip unless
 * CONTROL_PLANE_API_URL points at a live backend.
 */
export default defineConfig({
  testDir: "./e2e",
  fullyParallel: true,
  reporter: [["list"]],
  use: {
    // Dedicated port: never reuse a foreign/stale dev server on 3000.
    baseURL: process.env.DASHBOARD_URL ?? "http://127.0.0.1:3100",
    trace: "on-first-retry",
  },
  // Cross-browser (DEF-002): agents use the console on Chrome, Firefox, and
  // Safari; WebKit + mobile emulation guard theming and responsive layout.
  projects: [
    { name: "chromium", use: { ...devices["Desktop Chrome"] } },
    { name: "firefox", use: { ...devices["Desktop Firefox"] } },
    { name: "webkit", use: { ...devices["Desktop Safari"] } },
    { name: "mobile-safari", use: { ...devices["iPhone 13"] } },
  ],
  webServer: process.env.DASHBOARD_URL
    ? undefined
    : {
        command: "node node_modules/next/dist/bin/next dev -p 3100",
        url: "http://127.0.0.1:3100/login",
        // 3100 is this project's dedicated port (3000 may host foreign
        // servers); anything already on 3100 is our own dev server.
        reuseExistingServer: true,
        timeout: 120_000,
      },
});
