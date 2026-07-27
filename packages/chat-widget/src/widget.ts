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
      inline-size: min(360px, calc(100vw - 32px));
      block-size: min(520px, calc(100vh - 120px));
      background: var(--mk-surface);
      border: 1px solid var(--mk-border);
      border-radius: var(--mk-radius);
      box-shadow: 0 12px 40px rgb(0 0 0 / 0.22);
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }

    header {
      background: linear-gradient(135deg, var(--mk-accent), var(--mk-accent-2));
      color: var(--mk-accent-contrast);
      padding: 14px 14px;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .avatar {
      position: relative;
      inline-size: 40px;
      block-size: 40px;
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
      border: 2px solid var(--mk-accent);
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
      font-size: 18px;
      cursor: pointer;
      padding: 4px 8px;
      border-radius: 8px;
      opacity: 0.85;
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

    .quick-replies {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      padding-inline-start: 34px;
      margin-block-start: 2px;
    }

    .quick-reply {
      border: 1.5px solid var(--mk-accent);
      background: var(--mk-surface);
      color: var(--mk-accent);
      border-radius: 999px;
      padding: 7px 14px;
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
      padding: 9px 13px;
      border-radius: 16px;
      overflow-wrap: anywhere;
      white-space: pre-wrap;
    }

    .msg.visitor {
      background: var(--mk-accent);
      color: var(--mk-accent-contrast);
      border-end-end-radius: 5px;
    }

    .msg.remote {
      background: #f1f2f6;
      color: var(--mk-text);
      border-end-start-radius: 5px;
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
      /* Fixed dark slate (not --mk-muted) so "Powered by" clears WCAG AA (4.5:1)
         on both the white panel surface and any host page bleed-through. */
      color: #52525b;
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
      padding: 8px 10px;
      border-block-start: 1px solid var(--mk-border);
    }

    textarea {
      box-sizing: border-box;
      flex: 1;
      resize: none;
      border: 1px solid var(--mk-border);
      border-radius: 12px;
      padding: 10px 12px;
      font: inherit;
      block-size: 40px;
      min-block-size: 40px;
      max-block-size: 96px;
      color: var(--mk-text);
      background: var(--mk-surface);
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
    this.loadEmojiPrefs();
  }

  override disconnectedCallback(): void {
    super.disconnectedCallback();
    if (this.typingIdleTimer !== null) clearTimeout(this.typingIdleTimer);
    if (this.remoteTypingClearTimer !== null) clearTimeout(this.remoteTypingClearTimer);
    this.transport?.stop();
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

  /** Restore a persisted session or bootstrap a new one (§4 restore state). */
  private async ensureSession(): Promise<void> {
    const stored = await this.sessionStore.load();

    if (stored !== null) {
      this.api.setToken(stored.token);
      this.conversationId = stored.conversationId;
      this.messages.lastSeenSequence = 0; // full replay fills the cacheless store
      await this.applyIdentity(stored);
      return;
    }

    const session = await this.api.createSession(
      this.config.siteKey,
      this.config.consentState ?? "unknown",
    );

    const fresh = {
      visitorId: session.visitor_id,
      token: session.token,
      conversationId: null,
      lastSeenSequence: 0,
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

  private async submit(event: Event): Promise<void> {
    event.preventDefault();
    this.emojiOpen = false;
    const body = this.draft.trim() || this.pendingAttachment?.file_name.trim() || "";
    if (body.length === 0 || this.sending) return;

    this.sending = true;
    this.draft = "";
    this.stopTypingSignal();
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
      <section class="panel" role="dialog" aria-label=${this.config?.title ?? t("title")}>
        <header>
          <span class="avatar" aria-hidden>
            ${this.config?.avatarUrl
              ? html`<img src=${this.config.avatarUrl} alt="" />`
              : html`<span class="avatar-fallback">💬</span>`}
            ${this.agentPresent || this.connection === "connected"
              ? html`<span class="avatar-dot"></span>`
              : nothing}
          </span>
          <div class="header-text">
            <h2>${this.config?.title ?? t("title")}</h2>
            <span class="status" role="status">
              ${this.connection !== "connected"
                ? t(this.connection === "offline" ? "offline" : "reconnecting")
                : this.agentPresent
                  ? html`<span class="online-dot"></span>${t("online")}`
                  : (this.config?.subtitle ?? t("subtitle_default"))}
            </span>
          </div>
          <button class="close" aria-label=${t("close_label")} @click=${() => void this.toggle()}>
            ✕
          </button>
        </header>

        <div class="log" role="log" aria-label=${t("log_label")} aria-live="polite" data-revision=${this.revision}>
          ${this.showWelcome && (this.config?.greeting || (this.config?.quickReplies?.length ?? 0) > 0)
            ? html`
                ${this.config?.greeting
                  ? html`<div class="row remote">
                      ${this.renderMsgAvatar()}
                      <div class="msg remote">${this.config.greeting}</div>
                    </div>`
                  : nothing}
                ${(this.config?.quickReplies?.length ?? 0) > 0
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
          ${this.messages.messages.map((message) => {
            const isVisitor = message.sender_type === "visitor";
            const bubble = html`
              <div class="msg ${isVisitor ? "visitor" : "remote"}">
                ${message.body}
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
              </div>
            `;
            return isVisitor
              ? html`<div class="row visitor">${bubble}</div>`
              : html`<div class="row remote">${this.renderMsgAvatar()}${bubble}</div>`;
          })}
          ${this.messages.pendingMessages.map(
            (pending) => html`
              <div class="row visitor">
                <div class="msg visitor pending">
                  ${pending.body}
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

        <form @submit=${(event: Event) => void this.submit(event)}>
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
            📎
          </button>
          <textarea
            rows="1"
            aria-label=${t("input_label")}
            placeholder=${t("input_placeholder")}
            maxlength="16000"
            .value=${this.draft}
            @input=${(event: Event) => {
              this.draft = (event.target as HTMLTextAreaElement).value;
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

        ${this.config?.hideBranding
          ? nothing
          : html`<div class="branding">${t("powered_by")} <strong>mkEngage</strong></div>`}
      </section>
    `;
  }

  /** Small circular bot/agent avatar shown beside remote messages. */
  private renderMsgAvatar(): unknown {
    return html`<span class="msg-avatar" aria-hidden>
      ${this.config?.avatarUrl
        ? html`<img src=${this.config.avatarUrl} alt="" />`
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
