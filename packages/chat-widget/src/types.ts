/** Contract-shaped types mirroring the widget REST API (control plane). */

export interface WidgetIdentity {
  /** Customer-system user id (signed identity subject). */
  externalId: string;
  /** HMAC-SHA256(externalId, org signing secret) — computed by the CUSTOMER'S BACKEND (§4: never in the widget). */
  signature: string;
  email?: string;
  name?: string;
}

export interface WidgetConfig {
  /** Public site key — NOT a secret (§4). */
  siteKey: string;
  /** Control-plane API origin, e.g. https://api.mkengage.example */
  apiUrl: string;
  /** BCP-47 locale; falls back to en. */
  locale?: string;
  /** Visitor-tracking consent; analytics events are suppressed unless granted. */
  consentState?: "granted" | "denied" | "unknown";
  /** Widget title shown in the panel header. */
  title?: string;
  /** Verified identity payload from the host page (optional). */
  identity?: WidgetIdentity;
}

export interface WidgetSession {
  visitor_id: string;
  token: string;
}

export interface ConversationSummary {
  conversation_id: string;
  status: "open" | "pending" | "closed";
  last_sequence: number;
}

export interface AttachmentMeta {
  attachment_id: string;
  file_name: string;
  content_type_header: string;
  size_bytes: number;
  scan_status: "pending" | "clean" | "quarantined";
}

export interface ChatMessage {
  message_id: string;
  conversation_id: string;
  channel_id: string | null;
  sender_type: "visitor" | "contact" | "agent" | "chatbot" | "system";
  sender_id: string;
  sequence_number: number;
  content_type: string;
  body: string;
  lifecycle_state: string;
  sent_at: string;
  attachments?: AttachmentMeta[];
}

/** Local-only optimistic message awaiting durable ack (§27: rendered as pending, never confirmed). */
export interface PendingMessage {
  idempotency_key: string;
  body: string;
  created_at: number;
}
