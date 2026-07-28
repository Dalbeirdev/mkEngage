import type { NextConfig } from "next";
import createNextIntlPlugin from "next-intl/plugin";

const withNextIntl = createNextIntlPlugin("./src/i18n/request.ts");

const nextConfig: NextConfig = {
  poweredByHeader: false,
  allowedDevOrigins: ["127.0.0.1", "localhost"],
  // Keep the dev-only overlay off the left sidebar / upsell card.
  devIndicators: { position: "bottom-right" },
};

export default withNextIntl(nextConfig);
