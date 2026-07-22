import { getRequestConfig } from "next-intl/server";

/**
 * i18n scaffolding (§3: internationalization + RTL). Single locale for now;
 * locale negotiation (user preference → org default) arrives with the
 * settings module. RTL readiness: the root layout derives `dir` from the
 * active locale — adding ar/he/fa requires messages + no layout changes.
 */
export const DEFAULT_LOCALE = "en";
export const RTL_LOCALES: ReadonlySet<string> = new Set(["ar", "he", "fa", "ur"]);

export default getRequestConfig(async () => {
  const locale = DEFAULT_LOCALE;

  return {
    locale,
    messages: (await import(`../../messages/${locale}.json`)).default,
  };
});
