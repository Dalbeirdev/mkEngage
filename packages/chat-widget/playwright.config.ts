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
  projects: [{ name: "chromium", use: { ...devices["Desktop Chrome"] } }],
  webServer: {
    command: "node node_modules/vite/bin/vite.js dev --port 5175 --strictPort --host 127.0.0.1",
    url: "http://127.0.0.1:5175/demo/index.html",
    reuseExistingServer: false,
    timeout: 60_000,
  },
});
