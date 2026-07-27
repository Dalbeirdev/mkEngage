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
    subtitle_default: "We're here to help",
    powered_by: "Powered by",
    attach_label: "Attach a file",
    uploading: "Uploading…",
    attachment_error: "File could not be attached.",
    attachment_remove: "Remove attachment",
    attachment_scanning: "Scanning…",
    attachment_blocked: "Blocked",
    prechat_title: "Before we start, tell us a bit about you",
    prechat_name: "Your name",
    prechat_email: "Email",
    prechat_email_optional: "Email (optional)",
    prechat_start: "Start chat",
    offline_hours: "We're currently away. Leave a message and we'll get back to you.",
    csat_title: "How was your experience?",
    csat_comment_placeholder: "Add a comment (optional)",
    csat_submit: "Submit rating",
    csat_thanks: "Thanks for your feedback!",
    conversation_closed: "This conversation has ended.",
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
