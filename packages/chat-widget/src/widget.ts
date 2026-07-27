import { LitElement, css, html, nothing } from "lit";
import { state } from "lit/decorators.js";

import { ApiError, WidgetApi } from "./api.js";
import {
  EMOJI_CATEGORIES,
  SKIN_SWATCHES,
  searchEmoji,
  withSkin,
  type EmojiEntry,
} from "./emoji-data.js";
import { RTL_LOCALES, createTranslator } from "./i18n.js";
import { SessionStorage } from "./storage.js";
import { MessageStore } from "./store.js";
import { GatewayTransport, PollingTransport, type Transport } from "./transport.js";
import type { AttachmentMeta, WidgetConfig } from "./types.js";

/**
 * <mkengage-widget> — universal chat widget (§4).
 *
 * Shadow DOM isolates styles from ANY host page (WordPress themes, Tailwind
 * apps, ancient PHP sites alike). No secrets: only the public site key and a
 * revocable visitor token. Accessible: launcher/panel are keyboard operable,
 * the message list is a polite live region, Escape closes.
 */
export class MkEngageWidget extends LitElement {
  static override styles = css`
    :host {
      --mk-accent: #4f46e5;
      --mk-accent-2: #6366f1;
      --mk-accent-contrast: #ffffff;
      --mk-surface: #ffffff;
      --mk-text: #18181b;
      --mk-muted: #52525b;
      --mk-border: #d4d4d8;
      --mk-radius: 14px;
      --mk-z: 2147483000;
      /* Themeable surfaces (dark theme overrides below) */
      --mk-header-bg: linear-gradient(135deg, var(--mk-accent), var(--mk-accent-2));
      --mk-header-edge: var(--mk-accent);
      --mk-remote-bubble: #f1f2f6;
      --mk-remote-text: #18181b;
      --mk-chip-bg: #e4e4e7;
      --mk-chip-text: #18181b;
      --mk-branding: #52525b;
      --mk-time: rgb(255 255 255 / 0.85);
      --mk-time-remote: #52525b;
      --mk-composer-bg: #ffffff;

      /* !important on host typography: host-page rules (even universal
         "* { ... !important }" resets) target the host ELEMENT and inherit
         inward — but important declarations from the inner (shadow) tree
         beat important declarations from the document (CSS Scoping §
         cascading). This is what makes §4 style isolation hold on hostile
         pages; the demo page exists to prove it. */
      position: fixed !important;
      inset-inline-end: 20px;
      inset-block-end: 20px;
      z-index: var(--mk-z) !important;
      font-family: system-ui, -apple-system, "Segoe UI", sans-serif !important;
      font-size: 14px !important;
      line-height: 1.45 !important;
      color: var(--mk-text) !important;
      letter-spacing: normal !important;
      text-transform: none !important;
    }

    /* Dark theme (config.theme = "dark") — matches the premium dark reference:
       flat near-black surfaces, dark-gray remote bubbles, light gray chips.
       Every pair keeps WCAG AA (Axe-scanned in e2e). */
    :host([data-theme="dark"]) {
      --mk-surface: #0c0c0e;
      --mk-text: #fafafa;
      --mk-muted: #a1a1aa;
      --mk-border: #3f3f46;
      --mk-header-bg: #27272a;
      --mk-header-edge: #27272a;
      --mk-remote-bubble: #2e2e33;
      --mk-remote-text: #fafafa;
      --mk-chip-bg: #d4d4d8;
      --mk-chip-text: #18181b;
      --mk-branding: #a1a1aa;
      --mk-time-remote: #a1a1aa;
      /* Composer bar sits slightly lighter than the log (reference). */
      --mk-composer-bg: #1a1a1e;
      --mk-border: #2e2e33;
    }

    :host([data-theme="dark"]) .offline-hours {
      background: #422006;
      color: #fde68a;
      border-color: #92400e;
    }

    :host([data-theme="dark"]) .star.active {
      color: #fbbf24; /* amber-400: 10.5:1 on #0c0c0e */
    }

    :host([data-theme="dark"]) .branding strong {
      color: #a5b4fc; /* indigo-300: 9.4:1 on #0c0c0e (accent is only 3.1:1) */
    }

    .launcher {
      inline-size: 56px;
      block-size: 56px;
      border-radius: 50%;
      border: none;
      background: var(--mk-accent);
      color: var(--mk-accent-contrast);
      font-size: 24px;
      cursor: pointer;
      box-shadow: 0 4px 14px rgb(0 0 0 / 0.25);
      display: grid;
      place-items: center;
    }

    .launcher:focus-visible,
    button:focus-visible,
    textarea:focus-visible {
      outline: 3px solid #a5b4fc;
      outline-offset: 2px;
    }

    .panel {
      position: absolute;
      inset-inline-end: 0;
      inset-block-end: 72px;
      /* Desktop-first proportions (reference): taller, roomier panel. */
      inline-size: min(400px, calc(100vw - 32px));
      block-size: min(650px, calc(100vh - 110px));
      background: var(--mk-surface);
      border: 1px solid var(--mk-border);
      border-radius: var(--mk-radius);
      box-shadow: 0 12px 40px rgb(0 0 0 / 0.22);
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }

    header {
      background: var(--mk-header-bg);
      color: var(--mk-accent-contrast);
      padding: 16px 16px;
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .avatar {
      position: relative;
      inline-size: 44px;
      block-size: 44px;
      border-radius: 50%;
      background: rgb(255 255 255 / 0.2);
      display: grid;
      place-items: center;
      flex: 0 0 auto;
      overflow: visible;
    }

    .avatar img {
      inline-size: 100%;
      block-size: 100%;
      border-radius: 50%;
      object-fit: cover;
    }

    .avatar-fallback {
      font-size: 20px;
    }

    .avatar-dot {
      position: absolute;
      inset-block-end: 0;
      inset-inline-end: 0;
      inline-size: 11px;
      block-size: 11px;
      border-radius: 50%;
      background: #4ade80;
      border: 2px solid var(--mk-header-edge);
    }

    .header-text {
      flex: 1;
      min-inline-size: 0;
    }

    header h2 {
      margin: 0;
      font-size: 15px;
      font-weight: 700;
    }

    header .status {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      font-size: 12px;
      opacity: 0.92;
    }

    .close {
      border: none;
      background: transparent;
      color: inherit;
      /* Back-chevron (reference): sits left of the avatar. */
      font-size: 26px;
      line-height: 1;
      cursor: pointer;
      padding: 2px 8px 6px 2px;
      border-radius: 8px;
      opacity: 0.9;
    }

    .close:hover {
      opacity: 1;
      background: rgb(255 255 255 / 0.15);
    }

    .log {
      flex: 1;
      overflow-y: auto;
      padding: 14px 12px;
      display: flex;
      flex-direction: column;
      gap: 10px;
      margin: 0;
      scrollbar-width: thin; /* slim rail instead of chunky Windows arrows */
    }

    .row {
      display: flex;
      align-items: flex-end;
      gap: 8px;
    }

    .row.visitor {
      justify-content: flex-end;
    }

    .msg-avatar {
      inline-size: 26px;
      block-size: 26px;
      border-radius: 50%;
      background: var(--mk-accent);
      color: #fff;
      display: grid;
      place-items: center;
      font-size: 14px;
      flex: 0 0 auto;
      overflow: hidden;
    }

    .msg-avatar img {
      inline-size: 100%;
      block-size: 100%;
      object-fit: cover;
    }

    /* Keeps continuation bubbles aligned under the first avatar'd bubble. */
    .msg-avatar-spacer {
      inline-size: 26px;
      flex: 0 0 auto;
    }

    /* Right-aligned filled pills (reference) — they act for the visitor. */
    .quick-replies {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      justify-content: flex-end;
      margin-block-start: 2px;
    }

    .quick-reply {
      border: none;
      background: var(--mk-chip-bg);
      color: var(--mk-chip-text);
      border-radius: 999px;
      padding: 8px 16px;
      font: inherit;
      font-size: 13px;
      font-weight: 500;
      cursor: pointer;
      transition:
        background-color 0.15s ease,
        color 0.15s ease;
    }

    .quick-reply:hover {
      background: var(--mk-accent);
      color: var(--mk-accent-contrast);
    }

    .msg {
      max-inline-size: 78%;
      padding: 10px 14px;
      border-radius: 18px;
      overflow-wrap: anywhere;
      /* NO pre-wrap here: Lit template newlines/indentation between the
         body, attachments, and timestamp would render as blank lines and
         balloon the bubble (emoji-only messages made it a 150px square).
         User newlines are preserved on .body only. */
    }

    .msg .body {
      white-space: pre-wrap;
      overflow-wrap: anywhere;
    }

    /* Emoji-only messages read better large (modern-chat convention). */
    .msg .body.emoji-only {
      font-size: 26px;
      line-height: 1.25;
    }

    .msg.visitor {
      background: var(--mk-accent);
      color: var(--mk-accent-contrast);
      border-end-end-radius: 5px;
    }

    .msg.remote {
      background: var(--mk-remote-bubble);
      color: var(--mk-remote-text);
      border-end-start-radius: 5px;
    }

    /* Sender label above a remote bubble group (reference: bot name). */
    .sender-label {
      font-size: 12px;
      color: var(--mk-muted);
      padding-inline-start: 36px;
      margin-block-end: -6px;
    }

    /* In-bubble timestamp, bottom-right (reference: "06:38 PM"). */
    .msg .time {
      display: block;
      text-align: end;
      font-size: 10px;
      margin-block-start: 3px;
      color: var(--mk-time-remote);
    }

    .msg.visitor .time {
      color: var(--mk-time);
    }

    .composer-hidden {
      display: none !important;
    }

    .offline-hours {
      background: #fef3c7;
      color: #78350f; /* 8.3:1 on the amber tint — AA-safe */
      border: 1px solid #fcd34d;
      border-radius: 10px;
      padding: 8px 12px;
      margin-block-end: 10px;
    }

    /* Pre-chat lead form (Phase 23) */
    .prechat {
      display: flex;
      flex-direction: column;
      gap: 8px;
      background: var(--mk-surface);
      border: 1px solid var(--mk-border);
      border-radius: 12px;
      padding: 12px;
      margin: 4px 0 8px 30px; /* aligns under the greeting bubble */
    }

    .prechat-title {
      margin: 0 0 2px;
      font-size: 13px;
      font-weight: 600;
      color: var(--mk-text);
    }

    .prechat input {
      border: 1px solid var(--mk-border);
      border-radius: 8px;
      padding: 8px 10px;
      font: inherit;
      color: var(--mk-text);
      background: var(--mk-surface);
    }

    .prechat input::placeholder {
      color: var(--mk-muted);
      opacity: 1;
    }

    .prechat-start {
      border: none;
      border-radius: 8px;
      background: var(--mk-accent);
      color: var(--mk-accent-contrast);
      font: inherit;
      font-weight: 600;
      padding: 9px 12px;
      cursor: pointer;
    }

    .prechat-start:disabled {
      opacity: 0.55;
      cursor: default;
    }

    /* CSAT rating card (Phase 23) */
    .csat {
      border-block-start: 1px solid var(--mk-border);
      padding: 10px 14px 6px;
      text-align: center;
    }

    .csat-closed {
      margin: 0 0 2px;
      font-size: 12px;
      color: var(--mk-muted);
    }

    .csat-title,
    .csat-thanks {
      margin: 2px 0 6px;
      font-size: 13px;
      font-weight: 600;
      color: var(--mk-text);
    }

    .stars {
      display: flex;
      justify-content: center;
      gap: 4px;
    }

    .star {
      border: none;
      background: transparent;
      font-size: 24px;
      line-height: 1;
      padding: 2px 4px;
      cursor: pointer;
      color: var(--mk-muted); /* 7.0:1 — AA-safe even as text glyph */
    }

    .star.active {
      color: #b45309; /* amber-700, 4.6:1 on white */
    }

    .csat-comment {
      inline-size: 100%;
      box-sizing: border-box;
      margin-block-start: 8px;
      border: 1px solid var(--mk-border);
      border-radius: 8px;
      padding: 8px 10px;
      font: inherit;
      color: var(--mk-text);
      resize: none;
    }

    .csat-comment::placeholder {
      color: var(--mk-muted);
      opacity: 1;
    }

    .csat-submit {
      margin: 8px 0 6px;
      border: none;
      border-radius: 8px;
      background: var(--mk-accent);
      color: var(--mk-accent-contrast);
      font: inherit;
      font-weight: 600;
      padding: 8px 16px;
      cursor: pointer;
    }

    .csat-submit:disabled {
      opacity: 0.55;
      cursor: default;
    }

    textarea::placeholder {
      /* Explicit AA-safe placeholder (7.0:1 on white) — browser defaults
         (~#757575 in WebKit) sit borderline at 4.5:1 and flake under Axe. */
      color: var(--mk-muted);
      opacity: 1;
    }

    .branding {
      text-align: center;
      font-size: 11px;
      background: var(--mk-composer-bg); /* one continuous bottom bar */
      /* Theme-pinned (light #52525b / dark #a1a1aa) so "Powered by" clears
         WCAG AA (4.5:1) on both surfaces and any host page bleed-through. */
      color: var(--mk-branding);
      padding: 6px 0 9px;
    }

    .branding strong {
      color: var(--mk-accent);
      font-weight: 600;
    }

    /* Pending state is signalled by the "Sending" meta label — we must NOT dim
       the bubble body, or the white-on-accent text drops below AA contrast
       (Axe flagged 2.03:1 when the a11y scan caught a still-pending bubble). */
    .msg.pending .meta {
      opacity: 0.85;
    }

    .msg .meta {
      display: block;
      font-size: 10px;
      opacity: 0.75;
      margin-block-start: 2px;
    }

    .notice {
      font-size: 12px;
      color: var(--mk-muted);
      text-align: center;
      padding: 4px 8px;
    }

    form {
      display: flex;
      align-items: flex-end;
      gap: 4px;
      padding: 10px 12px;
      border-block-start: 1px solid var(--mk-border);
      background: var(--mk-composer-bg);
    }

    textarea {
      box-sizing: border-box;
      flex: 1;
      resize: none;
      /* Flat bar (reference): bare placeholder on the composer surface —
         no pill outline. Focus keeps the a11y ring. */
      border: none;
      background: transparent;
      border-radius: 12px;
      padding: 10px 8px;
      font: inherit;
      block-size: 40px;
      min-block-size: 40px;
      max-block-size: 96px;
      color: var(--mk-text);
      /* Auto-grown via JS; hidden overflow prevents the Windows scrollbar
         arrows that showed inside the fixed-height composer. */
      overflow-y: hidden;
      scrollbar-width: none;
    }

    textarea:focus-visible {
      border-color: var(--mk-accent);
      outline: none;
    }

    .send {
      flex: 0 0 auto;
      border: none;
      border-radius: 50%;
      background: var(--mk-accent);
      color: var(--mk-accent-contrast);
      inline-size: 40px;
      block-size: 40px;
      display: grid;
      place-items: center;
      cursor: pointer;
      transition:
        filter 0.15s ease,
        transform 0.1s ease;
    }

    .send:hover:not(:disabled) {
      filter: brightness(1.1);
    }

    .send:active:not(:disabled) {
      transform: scale(0.94);
    }

    .send:disabled {
      opacity: 0.5;
      cursor: default;
    }

    .att {
      display: flex;
      align-items: center;
      gap: 6px;
      margin-block-start: 6px;
      padding: 6px 8px;
      border: 1px solid rgb(0 0 0 / 0.15);
      border-radius: 8px;
      background: rgb(255 255 255 / 0.35);
      color: inherit;
      font: inherit;
      font-size: 12px;
      cursor: pointer;
      max-inline-size: 100%;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .att:disabled {
      cursor: default;
      opacity: 0.7;
    }

    .att .meta {
      display: inline;
      margin: 0;
    }

    .chip-row {
      padding: 6px 10px 0;
    }

    .chip {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      border: 1px solid var(--mk-border);
      border-radius: 999px;
      padding: 4px 10px;
      font-size: 12px;
      background: #f4f4f5;
      color: var(--mk-text);
    }

    .chip-remove {
      border: none;
      background: transparent;
      cursor: pointer;
      font-size: 12px;
      color: var(--mk-muted);
      padding: 0 2px;
    }

    .file-input {
      display: none;
    }

    .emoji-picker {
      border-block-start: 1px solid var(--mk-border);
      background: var(--mk-surface);
      display: flex;
      flex-direction: column;
      max-block-size: 260px;
    }

    .emoji-top {
      display: flex;
      gap: 8px;
      align-items: center;
      padding: 8px 10px;
      border-block-end: 1px solid var(--mk-border);
    }

    .emoji-search {
      flex: 1;
      border: 1px solid var(--mk-border);
      border-radius: 999px;
      padding: 6px 12px;
      font: inherit;
      font-size: 13px;
      outline: none;
      color: var(--mk-text);
      background: var(--mk-surface);
    }

    .skin-row {
      display: flex;
      gap: 1px;
    }

    .skin-swatch {
      border: none;
      background: transparent;
      cursor: pointer;
      font-size: 15px;
      line-height: 1;
      padding: 2px;
      border-radius: 6px;
      opacity: 0.55;
    }

    .skin-swatch.active,
    .skin-swatch:hover {
      opacity: 1;
      background: rgb(0 0 0 / 0.06);
    }

    .emoji-scroll {
      overflow-y: auto;
      padding: 6px 8px 10px;
    }

    .emoji-section-label {
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: var(--mk-muted);
      padding: 8px 4px 4px;
      position: sticky;
      inset-block-start: 0;
      background: var(--mk-surface);
    }

    .emoji-grid {
      display: grid;
      grid-template-columns: repeat(8, 1fr);
      gap: 1px;
    }

    .emoji-cell {
      border: none;
      background: transparent;
      cursor: pointer;
      font-size: 20px;
      line-height: 1;
      padding: 5px 0;
      border-radius: 8px;
    }

    .emoji-cell:hover,
    .emoji-cell:focus-visible {
      background: rgb(0 0 0 / 0.07);
    }

    .attach {
      flex: 0 0 auto;
      inline-size: 38px;
      block-size: 40px;
      display: grid;
      place-items: center;
      border: none;
      border-radius: 10px;
      background: transparent;
      cursor: pointer;
      font-size: 19px;
      line-height: 1;
      opacity: 0.75;
      transition:
        background-color 0.15s ease,
        opacity 0.15s ease;
    }

    .attach:hover:not(:disabled),
    .attach[aria-expanded="true"] {
      opacity: 1;
      background: rgb(0 0 0 / 0.06);
    }

    .attach:disabled {
      opacity: 0.4;
      cursor: default;
    }

    .status.online {
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }

    .online-dot {
      inline-size: 7px;
      block-size: 7px;
      border-radius: 50%;
      background: #4ade80;
      box-shadow: 0 0 0 2px rgb(255 255 255 / 0.25);
    }

    .typing-bubble {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding-block: 12px;
    }

    .typing-dot {
      inline-size: 6px;
      block-size: 6px;
      border-radius: 50%;
      background: var(--mk-muted);
      opacity: 0.5;
    }

    @media (prefers-reduced-motion: no-preference) {
      .panel {
        animation: mk-pop 0.16s ease-out;
      }

      /* Transform-only (no opacity fade): a mid-animation Axe scan must never
         see blended colors — mobile-safari on CI runs this animation even
         under Playwright's reducedMotion emulation, and an opacity fade made
         every color in the panel fail contrast checks (1.63:1 blends). */
      @keyframes mk-pop {
        from {
          transform: translateY(10px) scale(0.98);
        }
      }

      .typing-dot {
        animation: mk-typing 1.1s ease-in-out infinite;
      }

      .typing-dot:nth-child(2) {
        animation-delay: 0.15s;
      }

      .typing-dot:nth-child(3) {
        animation-delay: 0.3s;
      }

      @keyframes mk-typing {
        0%,
        60%,
        100% {
          opacity: 0.35;
          transform: translateY(0);
        }
        30% {
          opacity: 1;
          transform: translateY(-3px);
        }
      }
    }
  `;

  @state() private open = false;

  @state() private draft = "";

  @state() private sending = false;

  @state() private connection: "connected" | "reconnecting" | "offline" = "connected";

  @state() private revision = 0;

  @state() private remoteTyping = false;

  @state() private agentPresent = false;

  @state() private emojiOpen = false;

  @state() private emojiSearch = "";

  @state() private emojiSkin = 0;

  @state() private recentEmojis: string[] = [];

  @state() private pendingAttachment: AttachmentMeta | null = null;

  @state() private uploading = false;

  @state() private attachmentError = false;

  /** Pre-chat form config from session bootstrap (null ⇒ no form, Phase 23). */
  @state() private prechat: { enabled: boolean; requireEmail: boolean } | null = null;

  @state() private profiled = false;

  @state() private prechatName = "";

  @state() private prechatEmail = "";

  @state() private prechatSubmitting = false;

  /** Business-hours state at bootstrap: false shows the offline notice. */
  @state() private orgOpen = true;

  /** Proactive trigger message shown as a bot bubble when a rule fires (Phase 24). */
  @state() private triggerMessage: string | null = null;

  /** Org-managed appearance (Phase 26); wins over embed config. */
  @state() private appearance: import("./types.js").WidgetAppearance | null = null;

  /** Server-authoritative (Phase 26): only white-label plans hide branding. */
  @state() private showBranding = true;

  private triggers: import("./types.js").TriggerConfig[] = [];

  private triggerTimers: Array<ReturnType<typeof setTimeout>> = [];

  private heartbeatTimer: ReturnType<typeof setInterval> | null = null;

  @state() private conversationStatus: "open" | "pending" | "closed" = "open";

  @state() private csatSelected = 0;

  @state() private csatComment = "";

  @state() private csatDone = false;

  @state() private csatSubmitting = false;

  private config!: WidgetConfig;

  private api!: WidgetApi;

  private sessionStore!: SessionStorage;

  private transport: Transport | null = null;

  private transportKind: "none" | "poll" | "ws" = "none";

  private readonly messages = new MessageStore();

  private conversationId: string | null = null;

  private t: (key: string) => string = createTranslator("en");

  /** Throttled outgoing typing signal + safety timers for the incoming one. */
  private lastTypingSentAt = 0;

  private typingIdleTimer: ReturnType<typeof setTimeout> | null = null;

  private remoteTypingClearTimer: ReturnType<typeof setTimeout> | null = null;

  configure(config: WidgetConfig): void {
    this.config = config;
    this.t = createTranslator(config.locale);
    this.api = new WidgetApi(config.apiUrl);
    this.sessionStore = new SessionStorage(config.siteKey);
    if (RTL_LOCALES.has((config.locale ?? "en").split("-")[0] ?? "")) {
      this.setAttribute("dir", "rtl");
    }
    if (config.theme === "dark") {
      this.setAttribute("data-theme", "dark");
    } else {
      this.removeAttribute("data-theme");
    }
    this.loadEmojiPrefs();
  }

  /** Preset palettes (Phase 26): theme + accent pair per design. */
  private static readonly PRESETS: Record<
    string,
    { dark: boolean; accent: string; accent2: string; flatHeader: boolean }
  > = {
    gradient: { dark: false, accent: "#4f46e5", accent2: "#6366f1", flatHeader: false },
    classic: { dark: false, accent: "#4f46e5", accent2: "#4f46e5", flatHeader: true },
    midnight: { dark: true, accent: "#4f46e5", accent2: "#6366f1", flatHeader: true },
    sunset: { dark: false, accent: "#ea580c", accent2: "#f97316", flatHeader: false },
    emerald: { dark: false, accent: "#059669", accent2: "#10b981", flatHeader: false },
  };

  /** WCAG relative luminance of a #rrggbb color. */
  private luminance(hex: string): number {
    const channel = (index: number): number => {
      const value = parseInt(hex.slice(index, index + 2), 16) / 255;
      return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
    };
    return 0.2126 * channel(1) + 0.7152 * channel(3) + 0.0722 * channel(5);
  }

  /**
   * Apply the org's appearance: preset palette, custom accent (with a
   * contrast-safe text color for arbitrary brand colors), logo, and the
   * server-side branding flag.
   */
  private applyAppearance(
    appearance: import("./types.js").WidgetAppearance | undefined,
    showBranding: boolean | undefined,
  ): void {
    this.showBranding = showBranding !== false;
    if (appearance === undefined) return;
    this.appearance = appearance;

    const preset = MkEngageWidget.PRESETS[appearance.preset] ?? MkEngageWidget.PRESETS["gradient"]!;

    if (preset.dark || this.config?.theme === "dark") {
      this.setAttribute("data-theme", "dark");
    } else {
      this.removeAttribute("data-theme");
    }

    const accent = appearance.accent ?? preset.accent;
    const accent2 = appearance.accent !== null ? accent : preset.accent2;
    // AA-safe text on the accent: light brand colors get near-black text.
    const contrast = this.luminance(accent) > 0.45 ? "#18181b" : "#ffffff";

    this.style.setProperty("--mk-accent", accent);
    this.style.setProperty("--mk-accent-2", accent2);
    this.style.setProperty("--mk-accent-contrast", contrast);
    this.style.setProperty(
      "--mk-time",
      contrast === "#ffffff" ? "rgb(255 255 255 / 0.85)" : "rgb(24 24 27 / 0.75)",
    );
    if (preset.flatHeader && !preset.dark) {
      this.style.setProperty("--mk-header-bg", accent);
      this.style.setProperty("--mk-header-edge", accent);
    } else if (!preset.dark) {
      this.style.setProperty("--mk-header-bg", `linear-gradient(135deg, ${accent}, ${accent2})`);
      this.style.setProperty("--mk-header-edge", accent);
    }
  }

  /** Effective branding: org appearance > embed config > i18n default. */
  private get widgetTitle(): string {
    return this.appearance?.title ?? this.config?.title ?? this.t("title");
  }

  private get widgetSubtitle(): string {
    return this.appearance?.subtitle ?? this.config?.subtitle ?? this.t("subtitle_default");
  }

  private get widgetAvatarUrl(): string | undefined {
    return this.appearance?.logo_url ?? this.config?.avatarUrl;
  }

  /** Grow the composer with its content (40px..96px, then inner scroll). */
  private autosizeComposer(textarea: HTMLTextAreaElement): void {
    textarea.style.blockSize = "40px";
    const grown = Math.min(96, textarea.scrollHeight);
    textarea.style.blockSize = `${grown}px`;
    textarea.style.overflowY = textarea.scrollHeight > 96 ? "auto" : "hidden";
  }

  /** True for short all-emoji bodies (rendered larger, chat convention). */
  private isEmojiOnly(body: string): boolean {
    const stripped = body.replace(/\s/gu, "");
    if (stripped === "" || [...stripped].length > 8) return false;
    // ‍ (ZWJ) and ️ (variation selector) hold sequences together.
    return /^(?:\p{Extended_Pictographic}|\p{Emoji_Component}|‍|️)+$/u.test(stripped);
  }

  private renderBody(body: string): unknown {
    return html`<span class="body ${this.isEmojiOnly(body) ? "emoji-only" : ""}">${body}</span>`;
  }

  /** Locale-aware "06:38 PM"-style time for in-bubble timestamps. */
  private formatTime(iso: string | null): string {
    try {
      return new Intl.DateTimeFormat(this.config?.locale ?? "en", {
        hour: "numeric",
        minute: "2-digit",
      }).format(iso === null ? new Date() : new Date(iso));
    } catch {
      return "";
    }
  }

  override connectedCallback(): void {
    super.connectedCallback();
    // Phase 24: presence tracking + proactive triggers start at page load,
    // not first open — that's what makes the live visitor board live.
    void this.backgroundBootstrap();
  }

  override disconnectedCallback(): void {
    super.disconnectedCallback();
    if (this.typingIdleTimer !== null) clearTimeout(this.typingIdleTimer);
    if (this.remoteTypingClearTimer !== null) clearTimeout(this.remoteTypingClearTimer);
    this.stopStatusTimer();
    if (this.heartbeatTimer !== null) clearInterval(this.heartbeatTimer);
    for (const timer of this.triggerTimers) clearTimeout(timer);
    this.transport?.stop();
  }

  private async backgroundBootstrap(): Promise<void> {
    if (this.config === null) return;
    try {
      await this.ensureSession();
      this.startHeartbeat();
      this.evaluateTriggers();
    } catch {
      // Offline or API down: the next open retries via toggle().
    }
  }

  private startHeartbeat(): void {
    if (this.heartbeatTimer !== null) return;
    void this.beat();
    this.heartbeatTimer = setInterval(() => void this.beat(), 30_000);
  }

  /** One presence beat; may hand back an agent-initiated conversation. */
  private async beat(): Promise<void> {
    try {
      const granted = (this.config?.consentState ?? "unknown") === "granted";
      const result = await this.api.heartbeat(
        granted && typeof location !== "undefined" ? location.href : null,
        granted && typeof document !== "undefined" ? document.title.slice(0, 200) : null,
      );

      // Adoption (proactive chat): an agent started a thread for this
      // visitor from the live board — pick it up and open the panel.
      if (result.conversation_id !== null && this.conversationId === null) {
        this.conversationId = result.conversation_id;
        const stored = await this.sessionStore.load();
        if (stored !== null) {
          await this.sessionStore.save({ ...stored, conversationId: this.conversationId });
        }
        if (!this.open) {
          await this.toggle();
        } else {
          await this.ensureTransport();
        }
      }
    } catch {
      // Presence is best-effort; the next beat retries.
    }
  }

  /** Client-side proactive rules (Phase 24) — each fires at most once ever. */
  private evaluateTriggers(): void {
    if (this.triggers.length === 0 || typeof localStorage === "undefined") return;

    const firedKey = `mkengage-fired-triggers:${this.config?.siteKey ?? ""}`;
    let fired: string[] = [];
    try {
      const raw = localStorage.getItem(firedKey);
      const parsed: unknown = raw === null ? [] : JSON.parse(raw);
      fired = Array.isArray(parsed) ? parsed.filter((id): id is string => typeof id === "string") : [];
    } catch {
      fired = [];
    }

    const fire = (id: string, message: string): void => {
      if (fired.includes(id)) return;
      fired.push(id);
      try {
        localStorage.setItem(firedKey, JSON.stringify(fired));
      } catch {
        // Storage full/blocked: fire anyway, may re-fire next visit.
      }
      this.triggerMessage = message;
      if (!this.open) void this.toggle();
    };

    for (const trigger of this.triggers) {
      if (fired.includes(trigger.id)) continue;

      if (trigger.type === "url_match") {
        const pattern = trigger.url_pattern ?? "";
        if (pattern !== "" && typeof location !== "undefined" && location.href.includes(pattern)) {
          fire(trigger.id, trigger.message);
        }
      } else {
        this.triggerTimers.push(
          setTimeout(() => fire(trigger.id, trigger.message), (trigger.seconds ?? 0) * 1000),
        );
      }
    }
  }

  private async toggle(): Promise<void> {
    this.open = !this.open;

    if (this.open) {
      await this.ensureSession();
      await this.ensureTransport();
      await this.updateComplete;
      this.renderRoot.querySelector("textarea")?.focus();
    } else {
      this.stopTypingSignal();
      this.setRemoteTyping(false);
      this.agentPresent = false;
      this.stopStatusTimer();
      this.transport?.stop();
      this.transport = null;
      this.transportKind = "none";
    }
  }

  /**
   * Prefer the WebSocket gateway (ADR-002); fall back to REST polling when
   * unavailable (§4 REST fallback). Upgrade happens once a conversation
   * exists (the channel topic needs its id).
   */
  private async ensureTransport(): Promise<void> {
    if (this.conversationId !== null && this.transportKind !== "ws") {
      const gateway = new GatewayTransport({
        fetchToken: () => this.api.gatewayToken(),
        conversationId: () => this.conversationId,
        lastSeenSequence: () => this.messages.lastSeenSequence,
        onMessages: (incoming) => {
          if (this.messages.ingestAll(incoming) > 0) {
            this.revision += 1;
            void this.updateComplete.then(() => this.scrollLogToEnd());
          }
        },
        onStateChange: (connectionState) => {
          this.connection = connectionState;
        },
        onTyping: (event) => {
          if (event.sender_type === "visitor") return;
          this.setRemoteTyping(event.is_typing);
        },
        onPresence: (subs) => {
          this.agentPresent = subs.some((sub) => sub.startsWith("user:"));
        },
      });

      try {
        await gateway.startAndConfirm();
        this.transport?.stop();
        this.transport = gateway;
        this.transportKind = "ws";
        // WS pushes messages but not closure; a slow status check fills the
        // gap so the CSAT prompt still appears (Phase 23).
        this.startStatusTimer();
        return;
      } catch {
        gateway.stop(); // fall through to polling
      }
    }

    if (this.transportKind === "none") {
      this.transport = new PollingTransport(
        () => this.poll(),
        (connectionState) => {
          this.connection = connectionState;
        },
      );
      this.transport.start();
      this.transportKind = "poll";
    }
  }

  private statusTimer: ReturnType<typeof setInterval> | null = null;

  private startStatusTimer(): void {
    this.stopStatusTimer();
    this.statusTimer = setInterval(() => {
      if (this.conversationId === null || this.conversationStatus === "closed") return;
      void this.api
        .getConversation(this.conversationId)
        .then((conversation) => {
          this.conversationStatus = conversation.status;
        })
        .catch(() => {
          /* transient; next tick retries */
        });
    }, 15_000);
  }

  private stopStatusTimer(): void {
    if (this.statusTimer !== null) {
      clearInterval(this.statusTimer);
      this.statusTimer = null;
    }
  }

  private sessionPromise: Promise<void> | null = null;

  /**
   * Restore a persisted session or bootstrap a new one (§4 restore state).
   * Memoized: the page-load background bootstrap (Phase 24) and the first
   * panel open would otherwise race two session creations — and the second
   * run's "restored" path would clobber the fresh bootstrap's widget config.
   */
  private ensureSession(): Promise<void> {
    this.sessionPromise ??= this.doEnsureSession().catch((error: unknown) => {
      this.sessionPromise = null; // allow a retry on the next open
      throw error;
    });

    return this.sessionPromise;
  }

  private async doEnsureSession(): Promise<void> {
    const stored = await this.sessionStore.load();

    if (stored !== null) {
      this.api.setToken(stored.token);
      this.conversationId = stored.conversationId;
      this.messages.lastSeenSequence = 0; // full replay fills the cacheless store
      // Returning visitor: never re-ask the pre-chat form (Phase 23).
      this.profiled = stored.profiled === true;
      this.prechat = null;
      // Cached appearance/branding keep returning visitors on-brand (Phase 26).
      this.applyAppearance(stored.appearance, stored.showBranding);
      await this.applyIdentity(stored);
      return;
    }

    const session = await this.api.createSession(
      this.config.siteKey,
      this.config.consentState ?? "unknown",
    );

    this.prechat =
      session.prechat !== undefined
        ? { enabled: session.prechat.enabled, requireEmail: session.prechat.require_email }
        : null;
    this.orgOpen = session.open !== false;
    this.triggers = session.triggers ?? [];
    this.applyAppearance(session.appearance, session.show_branding);

    const fresh = {
      visitorId: session.visitor_id,
      token: session.token,
      conversationId: null,
      lastSeenSequence: 0,
      ...(session.appearance !== undefined ? { appearance: session.appearance } : {}),
      ...(session.show_branding !== undefined ? { showBranding: session.show_branding } : {}),
    };
    await this.sessionStore.save(fresh);
    await this.applyIdentity(fresh);
  }

  /**
   * Send the host page's signed identity once per external id (§4 signed
   * authenticated identity). Verification failures leave the visitor
   * anonymous — never fatal for the chat itself.
   */
  private async applyIdentity(stored: import("./storage.js").StoredSession): Promise<void> {
    const identity = this.config.identity;
    if (identity === undefined) return;
    if (stored.identifiedExternalId === identity.externalId) return;

    try {
      await this.api.identify(identity);
      await this.sessionStore.save({ ...stored, identifiedExternalId: identity.externalId });
    } catch {
      // Invalid signature or unconfigured identity: chat continues anonymously.
    }
  }

  private async ensureConversation(): Promise<string> {
    if (this.conversationId !== null) return this.conversationId;

    const conversation = await this.api.createConversation(
      typeof location === "undefined" ? null : location.href,
    );
    this.conversationId = conversation.conversation_id;

    const stored = await this.sessionStore.load();
    if (stored !== null) {
      await this.sessionStore.save({ ...stored, conversationId: this.conversationId });
    }

    return this.conversationId;
  }

  private async poll(): Promise<void> {
    if (this.conversationId === null) return;

    try {
      const result = await this.api.listMessages(
        this.conversationId,
        this.messages.lastSeenSequence,
      );

      if (this.messages.ingestAll(result.data) > 0) {
        this.revision += 1;
        await this.updateComplete;
        this.scrollLogToEnd();
      }

      // Phase 23: closure arrives on the same poll — triggers the CSAT prompt.
      if (result.status !== undefined) this.conversationStatus = result.status;
    } catch (error) {
      if (error instanceof ApiError && error.isAuthFailure) {
        // Token revoked/expired: reset the persisted session; next open
        // bootstraps a fresh visitor (§4 handle expired sessions).
        await this.sessionStore.clear();
        this.conversationId = null;
      }
      throw error;
    }
  }

  /** Upload immediately on pick; the id is linked at send time (§14). */
  private async onFilePicked(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = ""; // allow re-picking the same file
    if (file === undefined || this.uploading) return;

    this.uploading = true;
    this.attachmentError = false;

    try {
      const conversationId = await this.ensureConversation();
      this.pendingAttachment = await this.api.uploadAttachment(conversationId, file);
      void this.ensureTransport(); // the upload may have just created the conversation
    } catch {
      this.attachmentError = true;
    } finally {
      this.uploading = false;
    }
  }

  private async openAttachment(attachment: AttachmentMeta): Promise<void> {
    if (attachment.scan_status !== "clean" || this.conversationId === null) return;

    try {
      const url = await this.api.attachmentDownloadUrl(this.conversationId, attachment.attachment_id);
      window.open(url, "_blank", "noopener");
    } catch {
      // Expired/pending: the next poll or replay refreshes scan status.
    }
  }

  // ---- Emoji picker (§12) ----

  /** Load recently-used emoji + skin-tone preference (best-effort). */
  private loadEmojiPrefs(): void {
    try {
      const recent = localStorage.getItem("mk-emoji-recent");
      if (recent !== null) this.recentEmojis = JSON.parse(recent) as string[];
      const skin = localStorage.getItem("mk-emoji-skin");
      if (skin !== null) this.emojiSkin = Math.min(5, Math.max(0, Number(skin) || 0));
    } catch {
      // storage blocked / private mode — the picker still works, just no memory
    }
  }

  private toggleEmojiPicker(): void {
    this.emojiOpen = !this.emojiOpen;
    this.emojiSearch = "";
    if (this.emojiOpen) {
      void this.updateComplete.then(() =>
        this.renderRoot.querySelector<HTMLInputElement>(".emoji-search")?.focus(),
      );
    }
  }

  private setSkinTone(tone: number): void {
    this.emojiSkin = tone;
    try {
      localStorage.setItem("mk-emoji-skin", String(tone));
    } catch {
      /* ignore */
    }
  }

  private insertEmoji(char: string): void {
    this.draft = `${this.draft}${char}`;
    this.recordRecent(char);
    this.notifyTyping();
    void this.updateComplete.then(() => {
      const ta = this.renderRoot.querySelector("textarea");
      if (ta !== null) {
        ta.focus();
        ta.selectionStart = ta.selectionEnd = ta.value.length;
      }
    });
  }

  private recordRecent(char: string): void {
    this.recentEmojis = [char, ...this.recentEmojis.filter((c) => c !== char)].slice(0, 24);
    try {
      localStorage.setItem("mk-emoji-recent", JSON.stringify(this.recentEmojis));
    } catch {
      /* ignore */
    }
  }

  /** Send a welcome quick-reply chip as the visitor's message. */
  private sendQuickReply(text: string): void {
    if (this.sending) return;
    this.draft = text;
    void this.submit(new Event("submit"));
  }

  /** True while the greeting + quick replies should show (nothing sent yet). */
  private get showWelcome(): boolean {
    return this.messages.messages.length === 0 && this.messages.pendingMessages.length === 0;
  }

  /** Pre-chat gate (Phase 23): form first, chat after — never re-shown. */
  private get showPrechat(): boolean {
    return this.prechat?.enabled === true && !this.profiled && this.showWelcome;
  }

  private async submitPrechat(event: Event): Promise<void> {
    event.preventDefault();
    const name = this.prechatName.trim();
    if (name === "" || this.prechatSubmitting) return;
    const email = this.prechatEmail.trim();
    if (this.prechat?.requireEmail === true && email === "") return;

    this.prechatSubmitting = true;
    try {
      await this.api.submitProfile(name, email === "" ? null : email);
      this.profiled = true;
      const stored = await this.sessionStore.load();
      if (stored !== null) await this.sessionStore.save({ ...stored, profiled: true });
      await this.updateComplete;
      this.renderRoot.querySelector("textarea")?.focus();
    } catch {
      // Capture is best-effort lead data: never block the chat on it.
      this.profiled = true;
    } finally {
      this.prechatSubmitting = false;
    }
  }

  private async submitCsat(): Promise<void> {
    if (this.csatSelected < 1 || this.conversationId === null || this.csatSubmitting) return;

    this.csatSubmitting = true;
    try {
      await this.api.submitRating(this.conversationId, this.csatSelected, this.csatComment);
      this.csatDone = true;
    } catch {
      // Leave the form up; the visitor can retry.
    } finally {
      this.csatSubmitting = false;
    }
  }

  private async submit(event: Event): Promise<void> {
    event.preventDefault();
    this.emojiOpen = false;
    const body = this.draft.trim() || this.pendingAttachment?.file_name.trim() || "";
    if (body.length === 0 || this.sending) return;

    this.sending = true;
    this.draft = "";
    this.stopTypingSignal();

    // Collapse the auto-grown composer back to a single line.
    const composer = this.renderRoot.querySelector("textarea");
    if (composer !== null) {
      composer.style.blockSize = "40px";
      composer.style.overflowY = "hidden";
    }
    const attachment = this.pendingAttachment;
    this.pendingAttachment = null;
    const idempotencyKey = crypto.randomUUID();
    this.messages.addPending(idempotencyKey, body);
    this.revision += 1;

    try {
      const conversationId = await this.ensureConversation();
      const message = await this.api.sendMessage(
        conversationId,
        idempotencyKey,
        body,
        attachment === null ? [] : [attachment.attachment_id],
      );
      this.messages.confirmPending(idempotencyKey, message);
      this.transport?.poke();
      void this.ensureTransport(); // upgrade to WS once the conversation exists
    } catch {
      // Pending entry stays visible; polling backoff handles connectivity.
      this.connection = "reconnecting";
    } finally {
      this.sending = false;
      this.revision += 1;
      await this.updateComplete;
      this.scrollLogToEnd();
      this.renderRoot.querySelector("textarea")?.focus();
    }
  }

  /** Show/hide the remote typing bubble; a lost "false" self-heals in 6 s. */
  private setRemoteTyping(isTyping: boolean): void {
    if (this.remoteTypingClearTimer !== null) {
      clearTimeout(this.remoteTypingClearTimer);
      this.remoteTypingClearTimer = null;
    }

    this.remoteTyping = isTyping;

    if (isTyping) {
      this.remoteTypingClearTimer = setTimeout(() => {
        this.remoteTyping = false;
      }, 6000);
      void this.updateComplete.then(() => this.scrollLogToEnd());
    }
  }

  /** Throttle: "typing" at most every 3 s, "stopped" after 2.5 s idle. */
  private notifyTyping(): void {
    if (this.transport?.sendTyping === undefined) return;

    const now = Date.now();
    if (now - this.lastTypingSentAt > 3000) {
      this.transport.sendTyping(true);
      this.lastTypingSentAt = now;
    }

    if (this.typingIdleTimer !== null) clearTimeout(this.typingIdleTimer);
    this.typingIdleTimer = setTimeout(() => this.stopTypingSignal(), 2500);
  }

  private stopTypingSignal(): void {
    if (this.typingIdleTimer !== null) {
      clearTimeout(this.typingIdleTimer);
      this.typingIdleTimer = null;
    }
    if (this.lastTypingSentAt !== 0) {
      this.transport?.sendTyping?.(false);
      this.lastTypingSentAt = 0;
    }
  }

  private scrollLogToEnd(): void {
    const log = this.renderRoot.querySelector(".log");
    if (log !== null) log.scrollTop = log.scrollHeight;
  }

  private formatSize(bytes: number): string {
    if (bytes >= 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    if (bytes >= 1024) return `${Math.round(bytes / 1024)} KB`;
    return `${bytes} B`;
  }

  private onKeydown(event: KeyboardEvent): void {
    if (event.key === "Escape" && this.open) {
      void this.toggle();
    }
  }

  protected override render(): unknown {
    const t = this.t;

    return html`
      <div @keydown=${(event: KeyboardEvent) => this.onKeydown(event)}>
        ${this.open ? this.renderPanel() : nothing}
        <button
          class="launcher"
          aria-label=${this.open ? t("close_label") : t("launcher_label")}
          aria-expanded=${this.open}
          @click=${() => void this.toggle()}
        >
          ${this.open ? "×" : "💬"}
        </button>
      </div>
    `;
  }

  private renderPanel(): unknown {
    const t = this.t;

    return html`
      <section class="panel" role="dialog" aria-label=${this.widgetTitle}>
        <header>
          <button class="close" aria-label=${t("close_label")} @click=${() => void this.toggle()}>
            ‹
          </button>
          <span class="avatar" aria-hidden>
            ${this.widgetAvatarUrl
              ? html`<img src=${this.widgetAvatarUrl} alt="" />`
              : html`<span class="avatar-fallback">💬</span>`}
            ${this.agentPresent || this.connection === "connected"
              ? html`<span class="avatar-dot"></span>`
              : nothing}
          </span>
          <div class="header-text">
            <h2>${this.widgetTitle}</h2>
            <span class="status" role="status">
              ${this.connection !== "connected"
                ? t(this.connection === "offline" ? "offline" : "reconnecting")
                : this.agentPresent
                  ? html`<span class="online-dot"></span>${t("online")}`
                  : this.widgetSubtitle}
            </span>
          </div>
        </header>

        <div class="log" role="log" aria-label=${t("log_label")} aria-live="polite" data-revision=${this.revision}>
          ${!this.orgOpen
            ? html`<div class="notice offline-hours" role="status">${t("offline_hours")}</div>`
            : nothing}
          ${this.showWelcome && (this.config?.greeting || (this.config?.quickReplies?.length ?? 0) > 0)
            ? html`
                ${this.config?.greeting
                  ? html`
                      <div class="sender-label">${this.widgetTitle}</div>
                      ${this.config.greeting
                        .split(/\n+/)
                        .filter((line) => line.trim() !== "")
                        .map(
                          (line, index) => html`
                            <div class="row remote">
                              ${index === 0 ? this.renderMsgAvatar() : html`<span class="msg-avatar-spacer"></span>`}
                              <div class="msg remote">
                                ${this.renderBody(line)}
                                <span class="time">${this.formatTime(null)}</span>
                              </div>
                            </div>
                          `,
                        )}
                    `
                  : nothing}
                ${(this.config?.quickReplies?.length ?? 0) > 0 && !this.showPrechat
                  ? html`<div class="quick-replies">
                      ${this.config?.quickReplies?.map(
                        (reply) => html`
                          <button
                            type="button"
                            class="quick-reply"
                            @click=${() => this.sendQuickReply(reply)}
                          >
                            ${reply}
                          </button>
                        `,
                      )}
                    </div>`
                  : nothing}
              `
            : nothing}
          ${this.triggerMessage !== null && this.showWelcome
            ? html`
                <div class="row remote">
                  ${this.renderMsgAvatar()}
                  <div class="msg remote">
                    ${this.renderBody(this.triggerMessage)}
                    <span class="time">${this.formatTime(null)}</span>
                  </div>
                </div>
              `
            : nothing}
          ${this.showPrechat ? this.renderPrechat() : nothing}
          ${this.messages.messages.map((message, index) => {
            const isVisitor = message.sender_type === "visitor";
            const previous = this.messages.messages[index - 1];
            const startsRemoteGroup =
              !isVisitor && (previous === undefined || previous.sender_type === "visitor");
            const bubble = html`
              <div class="msg ${isVisitor ? "visitor" : "remote"}">
                ${this.renderBody(message.body)}
                ${(message.attachments ?? []).map(
                  (attachment) => html`
                    <button
                      class="att"
                      ?disabled=${attachment.scan_status !== "clean"}
                      title=${attachment.file_name}
                      @click=${() => void this.openAttachment(attachment)}
                    >
                      📄 ${attachment.file_name}
                      <span class="meta">
                        ${attachment.scan_status === "clean"
                          ? this.formatSize(attachment.size_bytes)
                          : t(
                              attachment.scan_status === "pending"
                                ? "attachment_scanning"
                                : "attachment_blocked",
                            )}
                      </span>
                    </button>
                  `,
                )}
                <span class="time">${this.formatTime(message.sent_at)}</span>
              </div>
            `;
            return isVisitor
              ? html`<div class="row visitor">${bubble}</div>`
              : html`
                  ${startsRemoteGroup
                    ? html`<div class="sender-label">${this.widgetTitle}</div>`
                    : nothing}
                  <div class="row remote">${this.renderMsgAvatar()}${bubble}</div>
                `;
          })}
          ${this.messages.pendingMessages.map(
            (pending) => html`
              <div class="row visitor">
                <div class="msg visitor pending">
                  ${this.renderBody(pending.body)}
                  <span class="meta">${t("pending")}</span>
                </div>
              </div>
            `,
          )}
          ${this.remoteTyping
            ? html`
                <div class="row remote" aria-hidden="true">
                  ${this.renderMsgAvatar()}
                  <div class="msg remote typing-bubble">
                    <span class="typing-dot"></span><span class="typing-dot"></span
                    ><span class="typing-dot"></span>
                  </div>
                </div>
              `
            : nothing}
        </div>

        ${this.pendingAttachment !== null || this.uploading || this.attachmentError
          ? html`
              <div class="chip-row">
                ${this.uploading
                  ? html`<span class="notice">${t("uploading")}</span>`
                  : this.attachmentError
                    ? html`<span class="notice" role="alert">${t("attachment_error")}</span>`
                    : html`
                        <span class="chip">
                          📎 ${this.pendingAttachment?.file_name}
                          <button
                            class="chip-remove"
                            aria-label=${t("attachment_remove")}
                            @click=${() => {
                              this.pendingAttachment = null;
                            }}
                          >
                            ✕
                          </button>
                        </span>
                      `}
              </div>
            `
          : nothing}

        ${this.emojiOpen ? this.renderEmojiPicker() : nothing}
        ${this.conversationStatus === "closed" ? this.renderCsat() : nothing}

        <form
          class=${this.showPrechat || this.conversationStatus === "closed" ? "composer-hidden" : ""}
          @submit=${(event: Event) => void this.submit(event)}
        >
          <input
            type="file"
            class="file-input"
            aria-hidden="true"
            tabindex="-1"
            @change=${(event: Event) => void this.onFilePicked(event)}
          />
          <button
            class="attach"
            type="button"
            aria-label=${t("emoji_label")}
            aria-expanded=${this.emojiOpen}
            @click=${() => this.toggleEmojiPicker()}
          >
            😊
          </button>
          <button
            class="attach"
            type="button"
            aria-label=${t("attach_label")}
            ?disabled=${this.uploading}
            @click=${() => {
              this.renderRoot.querySelector<HTMLInputElement>(".file-input")?.click();
            }}
          >
            <!-- SVG (not the 📎 glyph): emoji paperclips render as jarring
                 monochrome outlines on Windows next to the colored smiley. -->
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48" />
            </svg>
          </button>
          <textarea
            rows="1"
            aria-label=${t("input_label")}
            placeholder=${this.config?.inputPlaceholder ?? t("input_placeholder")}
            maxlength="16000"
            .value=${this.draft}
            @input=${(event: Event) => {
              const target = event.target as HTMLTextAreaElement;
              this.draft = target.value;
              this.autosizeComposer(target);
              this.notifyTyping();
            }}
            @keydown=${(event: KeyboardEvent) => {
              if (event.key === "Enter" && !event.shiftKey) {
                event.preventDefault();
                void this.submit(event);
              }
            }}
          ></textarea>
          <button class="send" type="submit" ?disabled=${this.sending} aria-label=${t("send")}>
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
              <path
                fill="currentColor"
                d="M3.4 20.4l17.45-7.48a1 1 0 0 0 0-1.84L3.4 3.6a.993.993 0 0 0-1.39.91L2 9.12c0 .5.37.93.87.99L17 12 2.87 13.88c-.5.07-.87.5-.87 1l.01 4.61c0 .71.73 1.2 1.39.91z"
              />
            </svg>
          </button>
        </form>

        ${this.showBranding
          ? html`<div class="branding">${t("powered_by")} <strong>mkEngage</strong></div>`
          : nothing}
      </section>
    `;
  }

  /** Pre-chat lead form rendered inside the log, BotPenguin-style (Phase 23). */
  private renderPrechat(): unknown {
    const t = this.t;
    const requireEmail = this.prechat?.requireEmail === true;

    return html`
      <form class="prechat" @submit=${(event: Event) => void this.submitPrechat(event)}>
        <p class="prechat-title">${t("prechat_title")}</p>
        <input
          type="text"
          name="name"
          autocomplete="name"
          maxlength="100"
          required
          aria-label=${t("prechat_name")}
          placeholder=${t("prechat_name")}
          .value=${this.prechatName}
          @input=${(event: Event) => {
            this.prechatName = (event.target as HTMLInputElement).value;
          }}
        />
        <input
          type="email"
          name="email"
          autocomplete="email"
          maxlength="255"
          ?required=${requireEmail}
          aria-label=${requireEmail ? t("prechat_email") : t("prechat_email_optional")}
          placeholder=${requireEmail ? t("prechat_email") : t("prechat_email_optional")}
          .value=${this.prechatEmail}
          @input=${(event: Event) => {
            this.prechatEmail = (event.target as HTMLInputElement).value;
          }}
        />
        <button
          class="prechat-start"
          type="submit"
          ?disabled=${this.prechatSubmitting ||
          this.prechatName.trim() === "" ||
          (requireEmail && this.prechatEmail.trim() === "")}
        >
          ${t("prechat_start")}
        </button>
      </form>
    `;
  }

  /** Post-close CSAT rating card shown in place of the composer (Phase 23). */
  private renderCsat(): unknown {
    const t = this.t;

    if (this.csatDone) {
      return html`<div class="csat"><p class="csat-thanks" role="status">${t("csat_thanks")}</p></div>`;
    }

    return html`
      <div class="csat">
        <p class="csat-closed">${t("conversation_closed")}</p>
        <p class="csat-title">${t("csat_title")}</p>
        <div class="stars" role="radiogroup" aria-label=${t("csat_title")}>
          ${[1, 2, 3, 4, 5].map(
            (value) => html`
              <button
                type="button"
                class="star ${value <= this.csatSelected ? "active" : ""}"
                role="radio"
                aria-checked=${value === this.csatSelected}
                aria-label="${value} / 5"
                @click=${() => {
                  this.csatSelected = value;
                }}
              >
                ${value <= this.csatSelected ? "★" : "☆"}
              </button>
            `,
          )}
        </div>
        ${this.csatSelected > 0
          ? html`
              <textarea
                class="csat-comment"
                rows="2"
                maxlength="1000"
                placeholder=${t("csat_comment_placeholder")}
                aria-label=${t("csat_comment_placeholder")}
                .value=${this.csatComment}
                @input=${(event: Event) => {
                  this.csatComment = (event.target as HTMLTextAreaElement).value;
                }}
              ></textarea>
              <button
                class="csat-submit"
                type="button"
                ?disabled=${this.csatSubmitting}
                @click=${() => void this.submitCsat()}
              >
                ${t("csat_submit")}
              </button>
            `
          : nothing}
      </div>
    `;
  }

  /** Small circular bot/agent avatar shown beside remote messages. */
  private renderMsgAvatar(): unknown {
    return html`<span class="msg-avatar" aria-hidden>
      ${this.widgetAvatarUrl
        ? html`<img src=${this.widgetAvatarUrl} alt="" />`
        : html`<span>💬</span>`}
    </span>`;
  }

  private renderEmojiPicker(): unknown {
    const t = this.t;
    const results = this.emojiSearch.trim() !== "" ? searchEmoji(this.emojiSearch) : null;

    const cell = (entry: EmojiEntry) => {
      const char = withSkin(entry, this.emojiSkin);
      return html`
        <button
          type="button"
          class="emoji-cell"
          title=${entry.k.split(" ")[0] ?? ""}
          @click=${() => this.insertEmoji(char)}
        >
          ${char}
        </button>
      `;
    };

    return html`
      <div class="emoji-picker" role="dialog" aria-label=${t("emoji_label")}>
        <div class="emoji-top">
          <input
            class="emoji-search"
            type="text"
            placeholder=${t("emoji_search")}
            aria-label=${t("emoji_search")}
            .value=${this.emojiSearch}
            @input=${(e: Event) => {
              this.emojiSearch = (e.target as HTMLInputElement).value;
            }}
          />
          <div class="skin-row" role="group" aria-label=${t("emoji_skin")}>
            ${SKIN_SWATCHES.map(
              (swatch, i) => html`
                <button
                  type="button"
                  class="skin-swatch ${this.emojiSkin === i ? "active" : ""}"
                  aria-pressed=${this.emojiSkin === i}
                  @click=${() => this.setSkinTone(i)}
                >
                  ${swatch}
                </button>
              `,
            )}
          </div>
        </div>

        <div class="emoji-scroll">
          ${results !== null
            ? results.length > 0
              ? html`<div class="emoji-grid">${results.map(cell)}</div>`
              : html`<p class="notice">${t("emoji_none")}</p>`
            : html`
                ${this.recentEmojis.length > 0
                  ? html`
                      <div class="emoji-section-label">${t("emoji_recent")}</div>
                      <div class="emoji-grid">
                        ${this.recentEmojis.map(
                          (char) => html`
                            <button
                              type="button"
                              class="emoji-cell"
                              @click=${() => this.insertEmoji(char)}
                            >
                              ${char}
                            </button>
                          `,
                        )}
                      </div>
                    `
                  : nothing}
                ${EMOJI_CATEGORIES.map(
                  (category) => html`
                    <div class="emoji-section-label">${category.label}</div>
                    <div class="emoji-grid">${category.emojis.map(cell)}</div>
                  `,
                )}
              `}
        </div>
      </div>
    `;
  }
}
