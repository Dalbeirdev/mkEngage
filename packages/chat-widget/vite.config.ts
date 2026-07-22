import { defineConfig } from "vite";

/**
 * Library mode (§4): ES module for bundlers + IIFE for the plain <script>
 * embed on PHP/WordPress/static sites. Rollup-compatible output, no
 * externals — the widget ships self-contained (Lit inlined) so host pages
 * need exactly one file.
 */
export default defineConfig({
  build: {
    lib: {
      entry: "src/index.ts",
      name: "MkEngageWidget",
      fileName: "mkengage-widget",
      formats: ["es", "umd", "iife"],
    },
    sourcemap: true,
    target: "es2020",
  },
  test: {
    environment: "happy-dom",
    include: ["test/**/*.test.ts"],
    setupFiles: ["test/setup.ts"],
  },
});
