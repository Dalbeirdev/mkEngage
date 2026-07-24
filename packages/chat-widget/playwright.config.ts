import { defineConfig, devices } from "@playwright/test";

/**
 * Widget e2e + accessibility suite (§4: Playwright + Axe). The control-plane
 * API is MOCKED via page.route — deterministic and CI-runnable with no
 * backend; the live end-to-end path is exercised against the demo page in
 * phase verification.
 */
export default defineConfig({
  testDir: "./e2e",
  fullyParallel: true,
  reporter: [["list"]],
  use: {
    baseURL: "http://127.0.0.1:5175",
    trace: "on-first-retry",
  },
  // Cross-browser (DEF-002): the widget embeds on arbitrary host pages, so
  // WebKit (Safari) and Firefox coverage is load-bearing, not optional. Mobile
  // emulation guards Shadow-DOM + touch behavior on small viewports.
  projects: [
    { name: "chromium", use: { ...devices["Desktop Chrome"] } },
    { name: "firefox", use: { ...devices["Desktop Firefox"] } },
    { name: "webkit", use: { ...devices["Desktop Safari"] } },
    { name: "mobile-safari", use: { ...devices["iPhone 13"] } },
    { name: "mobile-chrome", use: { ...devices["Pixel 7"] } },
  ],
  webServer: {
    command: "node node_modules/vite/bin/vite.js dev --port 5175 --strictPort --host 127.0.0.1",
    url: "http://127.0.0.1:5175/demo/index.html",
    reuseExistingServer: false,
    timeout: 60_000,
  },
});
