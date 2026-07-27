import type {
  AttachmentMeta,
  ChatMessage,
  ConversationSummary,
  WidgetIdentity,
  WidgetSession,
} from "./types.js";

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

  async gatewayToken(): Promise<{ token: string; url: string }> {
    return (await this.request("POST", "/api/widget/gateway-token")) as {
      token: string;
      url: string;
    };
  }

  /** Pre-chat profile capture (Phase 23): unverified lead data. */
  async submitProfile(
    name: string,
    email: string | null,
  ): Promise<{ display_name: string; contact_id: string | null }> {
    return (await this.request("POST", "/api/widget/profile", {
      name,
      ...(email !== null && email !== "" ? { email } : {}),
    })) as { display_name: string; contact_id: string | null };
  }

  /** CSAT rating on a closed conversation (Phase 23). */
  async submitRating(conversationId: string, rating: number, comment: string | null): Promise<void> {
    await this.request("POST", `/api/widget/conversations/${conversationId}/rating`, {
      rating,
      ...(comment !== null && comment.trim() !== "" ? { comment: comment.trim() } : {}),
    });
  }

  /** Conversation status check (WS transport has no message poll to piggyback on). */
  async getConversation(conversationId: string): Promise<ConversationSummary> {
    return (await this.request(
      "GET",
      `/api/widget/conversations/${conversationId}`,
    )) as ConversationSummary;
  }

  async createConversation(sourceUrl: string | null): Promise<ConversationSummary> {
    return (await this.request("POST", "/api/widget/conversations", {
      source_url: sourceUrl,
    })) as ConversationSummary;
  }

  async listMessages(
    conversationId: string,
    afterSequence: number,
  ): Promise<{ data: ChatMessage[]; last_sequence: number; status?: "open" | "pending" | "closed" }> {
    return (await this.request(
      "GET",
      `/api/widget/conversations/${conversationId}/messages?after_sequence=${afterSequence}`,
    )) as { data: ChatMessage[]; last_sequence: number };
  }

  async sendMessage(
    conversationId: string,
    idempotencyKey: string,
    body: string,
    attachmentIds: string[] = [],
  ): Promise<ChatMessage> {
    return (await this.request(
      "POST",
      `/api/widget/conversations/${conversationId}/messages`,
      {
        idempotency_key: idempotencyKey,
        content_type: "text",
        body,
        ...(attachmentIds.length > 0 ? { attachment_ids: attachmentIds } : {}),
      },
    )) as ChatMessage;
  }

  /** Multipart upload (§14): the file is scanned async — starts `pending`. */
  async uploadAttachment(conversationId: string, file: File): Promise<AttachmentMeta> {
    const form = new FormData();
    form.append("file", file, file.name);

    const headers: Record<string, string> = { Accept: "application/json" };
    if (this.token !== null) headers["Authorization"] = `Bearer ${this.token}`;

    const response = await fetch(
      `${this.apiUrl}/api/widget/conversations/${conversationId}/attachments`,
      { method: "POST", headers, body: form },
    );

    if (!response.ok) throw new ApiError(response.status);
    return (await response.json()) as AttachmentMeta;
  }

  /** Short-lived download URL for a clean attachment (§14 pre-signed). */
  async attachmentDownloadUrl(conversationId: string, attachmentId: string): Promise<string> {
    const result = (await this.request(
      "GET",
      `/api/widget/conversations/${conversationId}/attachments/${attachmentId}/download`,
    )) as { url: string };

    return result.url;
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
