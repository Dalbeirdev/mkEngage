import { MkEngageWidget } from "./widget.js";
import type { WidgetConfig } from "./types.js";

export { MkEngageWidget } from "./widget.js";
export { registerLocale } from "./i18n.js";
export type { WidgetConfig } from "./types.js";

if (!customElements.get("mkengage-widget")) {
  customElements.define("mkengage-widget", MkEngageWidget);
}

/** Programmatic mount for SPA hosts (React/Vue/Angular wrappers call this). */
export function mount(config: WidgetConfig): MkEngageWidget {
  const element = document.createElement("mkengage-widget") as MkEngageWidget;
  element.configure(config);
  document.body.appendChild(element);
  return element;
}

/**
 * Script-tag auto-init for plain HTML/PHP/WordPress hosts (§4 async loading):
 *
 *   <script async src="https://cdn.mkengage.example/widget/mkengage-widget.iife.js"
 *           data-mkengage data-site-key="sk_..." data-api-url="https://api...."></script>
 */
const script =
  typeof document !== "undefined"
    ? document.querySelector<HTMLScriptElement>("script[data-mkengage]")
    : null;

if (script?.dataset["siteKey"] !== undefined && script.dataset["apiUrl"] !== undefined) {
  const config: WidgetConfig = {
    siteKey: script.dataset["siteKey"],
    apiUrl: script.dataset["apiUrl"],
  };
  const locale = script.dataset["locale"];
  const title = script.dataset["title"];
  const consent = script.dataset["consentState"];
  if (locale !== undefined) config.locale = locale;
  if (title !== undefined) config.title = title;
  if (consent === "granted" || consent === "denied" || consent === "unknown") {
    config.consentState = consent;
  }

  const boot = (): void => {
    mount(config);
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot, { once: true });
  } else {
    boot();
  }
}
