import { LitElement, css, html, nothing } from "lit";
import { state } from "lit/decorators.js";

import { ApiError, WidgetApi } from "./api.js";
import { RTL_LOCALES, createTranslator } from "./i18n.js";
import { SessionStorage } from "./storage.js";
import { MessageStore } from "./store.js";
import { GatewayTransport, PollingTransport, type Transport } from "./transport.js";
import type { WidgetConfig } from "./types.js";

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
      background: var(--mk-accent);
      color: var(--mk-accent-contrast);
      padding: 12px 16px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
    }

    header h2 {
      margin: 0;
      font-size: 15px;
      font-weight: 600;
    }

    header .status {
      font-size: 11px;
      opacity: 0.9;
    }

    .close {
      border: none;
      background: transparent;
      color: inherit;
      font-size: 18px;
      cursor: pointer;
      padding: 4px 8px;
      border-radius: 6px;
    }

    .log {
      flex: 1;
      overflow-y: auto;
      padding: 12px;
      display: flex;
      flex-direction: column;
      gap: 8px;
      margin: 0;
    }

    .msg {
      max-inline-size: 82%;
      padding: 8px 12px;
      border-radius: 12px;
      overflow-wrap: anywhere;
      white-space: pre-wrap;
    }

    .msg.visitor {
      align-self: flex-end;
      background: var(--mk-accent);
      color: var(--mk-accent-contrast);
      border-end-end-radius: 4px;
    }

    .msg.remote {
      align-self: flex-start;
      background: #f4f4f5;
      color: var(--mk-text);
      border-end-start-radius: 4px;
    }

    .msg.pending {
      opacity: 0.65;
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
      gap: 8px;
      padding: 10px;
      border-block-start: 1px solid var(--mk-border);
    }

    textarea {
      flex: 1;
      resize: none;
      border: 1px solid var(--mk-border);
      border-radius: 8px;
      padding: 8px 10px;
      font: inherit;
      max-block-size: 96px;
    }

    .send {
      border: none;
      border-radius: 8px;
      background: var(--mk-accent);
      color: var(--mk-accent-contrast);
      padding: 0 14px;
      font-weight: 600;
      cursor: pointer;
    }

    .send:disabled {
      opacity: 0.6;
      cursor: default;
    }

    @media (prefers-reduced-motion: no-preference) {
      .panel {
        animation: mk-pop 0.16s ease-out;
      }

      @keyframes mk-pop {
        from {
          transform: translateY(8px);
          opacity: 0;
        }
      }
    }
  `;

  @state() private open = false;

  @state() private draft = "";

  @state() private sending = false;

  @state() private connection: "connected" | "reconnecting" | "offline" = "connected";

  @state() private revision = 0;

  private config!: WidgetConfig;

  private api!: WidgetApi;

  private sessionStore!: SessionStorage;

  private transport: Transport | null = null;

  private transportKind: "none" | "poll" | "ws" = "none";

  private readonly messages = new MessageStore();

  private conversationId: string | null = null;

  private t: (key: string) => string = createTranslator("en");

  configure(config: WidgetConfig): void {
    this.config = config;
    this.t = createTranslator(config.locale);
    this.api = new WidgetApi(config.apiUrl);
    this.sessionStore = new SessionStorage(config.siteKey);
    if (RTL_LOCALES.has((config.locale ?? "en").split("-")[0] ?? "")) {
      this.setAttribute("dir", "rtl");
    }
  }

  override disconnectedCallback(): void {
    super.disconnectedCallback();
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

  private async submit(event: Event): Promise<void> {
    event.preventDefault();
    const body = this.draft.trim();
    if (body.length === 0 || this.sending) return;

    this.sending = true;
    this.draft = "";
    const idempotencyKey = crypto.randomUUID();
    this.messages.addPending(idempotencyKey, body);
    this.revision += 1;

    try {
      const conversationId = await this.ensureConversation();
      const message = await this.api.sendMessage(conversationId, idempotencyKey, body);
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

  private scrollLogToEnd(): void {
    const log = this.renderRoot.querySelector(".log");
    if (log !== null) log.scrollTop = log.scrollHeight;
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
          <div>
            <h2>${this.config?.title ?? t("title")}</h2>
            ${this.connection !== "connected"
              ? html`<span class="status" role="status">
                  ${t(this.connection === "offline" ? "offline" : "reconnecting")}
                </span>`
              : nothing}
          </div>
          <button class="close" aria-label=${t("close_label")} @click=${() => void this.toggle()}>
            ✕
          </button>
        </header>

        <div class="log" role="log" aria-label=${t("log_label")} aria-live="polite" data-revision=${this.revision}>
          ${this.messages.messages.map(
            (message) => html`
              <div class="msg ${message.sender_type === "visitor" ? "visitor" : "remote"}">
                ${message.body}
              </div>
            `,
          )}
          ${this.messages.pendingMessages.map(
            (pending) => html`
              <div class="msg visitor pending">
                ${pending.body}
                <span class="meta">${t("pending")}</span>
              </div>
            `,
          )}
        </div>

        <form @submit=${(event: Event) => void this.submit(event)}>
          <textarea
            rows="1"
            aria-label=${t("input_label")}
            placeholder=${t("input_placeholder")}
            maxlength="16000"
            .value=${this.draft}
            @input=${(event: Event) => {
              this.draft = (event.target as HTMLTextAreaElement).value;
            }}
            @keydown=${(event: KeyboardEvent) => {
              if (event.key === "Enter" && !event.shiftKey) {
                event.preventDefault();
                void this.submit(event);
              }
            }}
          ></textarea>
          <button class="send" type="submit" ?disabled=${this.sending}>
            ${this.sending ? t("sending") : t("send")}
          </button>
        </form>
      </section>
    `;
  }
}
