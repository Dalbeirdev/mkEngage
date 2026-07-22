import type { ChatMessage, ConversationSummary, WidgetIdentity, WidgetSession } from "./types.js";

/**
 * REST transport to the control-plane widget API (§4 REST fallback — the
 * default until the Phoenix gateway lands, then demoted to fallback behind
 * the WebSocket transport, ADR-002).
 */
export class WidgetApi {
  constructor(
    private readonly apiUrl: string,
    private token: string | null = null,
  ) {}

  setToken(token: string): void {
    this.token = token;
  }

  async createSession(
    siteKey: string,
    consentState: "granted" | "denied" | "unknown",
  ): Promise<WidgetSession> {
    const session = (await this.request("POST", "/api/widget/session", {
      site_key: siteKey,
      consent_state: consentState,
    })) as WidgetSession;
    this.token = session.token;
    return session;
  }

  async identify(identity: WidgetIdentity): Promise<{ contact_id: string; display_name: string | null }> {
    return (await this.request("POST", "/api/widget/identify", {
      external_id: identity.externalId,
      signature: identity.signature,
      email: identity.email ?? null,
      name: identity.name ?? null,
    })) as { contact_id: string; display_name: string | null };
  }

  async createConversation(sourceUrl: string | null): Promise<ConversationSummary> {
    return (await this.request("POST", "/api/widget/conversations", {
      source_url: sourceUrl,
    })) as ConversationSummary;
  }

  async listMessages(
    conversationId: string,
    afterSequence: number,
  ): Promise<{ data: ChatMessage[]; last_sequence: number }> {
    return (await this.request(
      "GET",
      `/api/widget/conversations/${conversationId}/messages?after_sequence=${afterSequence}`,
    )) as { data: ChatMessage[]; last_sequence: number };
  }

  async sendMessage(
    conversationId: string,
    idempotencyKey: string,
    body: string,
  ): Promise<ChatMessage> {
    return (await this.request(
      "POST",
      `/api/widget/conversations/${conversationId}/messages`,
      {
        idempotency_key: idempotencyKey,
        content_type: "text",
        body,
      },
    )) as ChatMessage;
  }

  private async request(method: string, path: string, body?: unknown): Promise<unknown> {
    const headers: Record<string, string> = { Accept: "application/json" };
    if (body !== undefined) headers["Content-Type"] = "application/json";
    if (this.token !== null) headers["Authorization"] = `Bearer ${this.token}`;

    const response = await fetch(`${this.apiUrl}${path}`, {
      method,
      headers,
      body: body === undefined ? null : JSON.stringify(body),
    });

    if (!response.ok) {
      throw new ApiError(response.status);
    }

    return response.json();
  }
}

export class ApiError extends Error {
  constructor(public readonly status: number) {
    super(`Widget API request failed: ${status}`);
  }

  /** 401 ⇒ token revoked/expired ⇒ new session bootstrap required. */
  get isAuthFailure(): boolean {
    return this.status === 401;
  }
}
