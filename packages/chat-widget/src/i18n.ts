/**
 * Minimal localization (§4: "i18next or an equivalent lightweight system").
 * A dictionary-based equivalent keeps the bundle tiny; locales are additive
 * and RTL locales flip the panel via dir on the host element.
 */

type Messages = Record<string, string>;

const dictionaries: Record<string, Messages> = {
  en: {
    launcher_label: "Open chat",
    close_label: "Close chat",
    title: "Chat with us",
    input_label: "Type your message",
    input_placeholder: "Type a message…",
    send: "Send",
    sending: "Sending…",
    pending: "Sending",
    reconnecting: "Reconnecting…",
    offline: "You appear to be offline. Messages will send when you reconnect.",
    error_send: "Message could not be sent. It will retry automatically.",
    log_label: "Conversation",
    online: "Online",
    attach_label: "Attach a file",
    uploading: "Uploading…",
    attachment_error: "File could not be attached.",
    attachment_remove: "Remove attachment",
    attachment_scanning: "Scanning…",
    attachment_blocked: "Blocked",
    emoji_label: "Choose an emoji",
    emoji_search: "Search emoji",
    emoji_recent: "Recently used",
    emoji_skin: "Skin tone",
    emoji_none: "No emoji found",
  },
};

export const RTL_LOCALES = new Set(["ar", "he", "fa", "ur"]);

export function createTranslator(locale: string | undefined): (key: string) => string {
  const lang = (locale ?? "en").split("-")[0] ?? "en";
  const dict = dictionaries[lang] ?? dictionaries["en"] ?? {};
  return (key: string): string => dict[key] ?? key;
}

/** Additive locale registration for host applications. */
export function registerLocale(lang: string, messages: Messages): void {
  dictionaries[lang] = { ...(dictionaries[lang] ?? {}), ...messages };
}
