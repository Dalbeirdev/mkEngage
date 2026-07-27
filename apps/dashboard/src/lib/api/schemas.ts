import { z } from "zod";

/**
 * Zod schemas mirroring contracts/openapi/control-plane.v1.yaml. Until client
 * generation from the OpenAPI contract lands (packages/api-client), these are
 * maintained by hand and MUST track the contract — the contract is the source
 * of truth, never this file.
 */

export const userSchema = z.object({
  id: z.uuid(),
  organization_id: z.uuid(),
  name: z.string(),
  email: z.email(),
  email_verified_at: z.string().nullable(),
  status: z.enum(["active", "suspended", "deprovisioned"]),
  created_at: z.string(),
  updated_at: z.string(),
});

export type User = z.infer<typeof userSchema>;

export const tokenResponseSchema = z.object({
  token: z.string().min(1),
});

export const availabilitySchema = z.object({
  availability: z.enum(["available", "away"]),
  max_open_conversations: z.number().int().positive().nullable(),
});

export type Availability = z.infer<typeof availabilitySchema>;

export const noteSchema = z.object({
  note_id: z.uuid(),
  conversation_id: z.uuid(),
  author_id: z.uuid(),
  author_name: z.string().nullable(),
  body: z.string(),
  created_at: z.string().nullable(),
});

export type Note = z.infer<typeof noteSchema>;

export const noteListSchema = z.object({ data: z.array(noteSchema) });

export const insightsOverviewSchema = z.object({
  range: z.object({ from: z.string(), to: z.string() }),
  conversations: z.object({
    total: z.number().int(),
    open: z.number().int(),
    pending: z.number().int(),
    closed: z.number().int(),
    resolution_rate: z.number(),
  }),
  messages: z.object({
    total: z.number().int(),
    by_sender: z.record(z.string(), z.number().int()),
    automation_rate: z.number(),
  }),
  csat: z.object({
    responses: z.number().int(),
    average: z.number().nullable(),
  }),
  by_department: z.array(
    z.object({ department_name: z.string(), conversations: z.number().int() }),
  ),
  daily: z.array(
    z.object({
      date: z.string(),
      conversations: z.number().int(),
      messages: z.number().int(),
    }),
  ),
});

export type InsightsOverview = z.infer<typeof insightsOverviewSchema>;

export const conversationSchema = z.object({
  conversation_id: z.uuid(),
  status: z.enum(["open", "pending", "closed"]),
  visitor_id: z.uuid().nullable(),
  visitor_name: z.string().nullable(),
  contact_id: z.uuid().nullable(),
  contact_name: z.string().nullable(),
  contact_email: z.string().nullable(),
  department_id: z.uuid().nullable(),
  department_name: z.string().nullable(),
  assigned_agent_id: z.uuid().nullable(),
  assigned_agent_name: z.string().nullable().optional(),
  last_sequence: z.number().int().nonnegative(),
  source_url: z.string().nullable(),
  csat_rating: z.number().int().min(1).max(5).nullable().optional(),
  csat_comment: z.string().nullable().optional(),
  tags: z.array(z.string()).optional(),
  created_at: z.string().nullable(),
  updated_at: z.string().nullable(),
});

export type Conversation = z.infer<typeof conversationSchema>;

export const conversationListSchema = z.object({
  data: z.array(conversationSchema),
});

export const attachmentSchema = z.object({
  attachment_id: z.uuid(),
  file_name: z.string(),
  content_type_header: z.string(),
  size_bytes: z.number().int().nonnegative(),
  scan_status: z.enum(["pending", "clean", "quarantined"]),
});

export type Attachment = z.infer<typeof attachmentSchema>;

export const chatMessageSchema = z.object({
  message_id: z.uuid(),
  conversation_id: z.uuid(),
  channel_id: z.uuid().nullable(),
  sender_type: z.enum(["visitor", "contact", "agent", "chatbot", "system"]),
  sender_id: z.uuid(),
  sequence_number: z.number().int().positive(),
  content_type: z.string(),
  body: z.string(),
  lifecycle_state: z.string(),
  sent_at: z.string().nullable(),
  attachments: z.array(attachmentSchema).default([]),
});

export type ChatMessage = z.infer<typeof chatMessageSchema>;

export const messageListSchema = z.object({
  data: z.array(chatMessageSchema),
  last_sequence: z.number().int().nonnegative(),
});

export const contactSchema = z.object({
  contact_id: z.uuid(),
  organization_id: z.uuid(),
  external_id: z.string().nullable(),
  email: z.string().nullable(),
  name: z.string().nullable(),
  phone: z.string().nullable(),
  attributes: z.record(z.string(), z.unknown()),
  created_at: z.string().nullable(),
});

export type Contact = z.infer<typeof contactSchema>;

export const contactListSchema = z.object({
  data: z.array(contactSchema),
});

export const chatbotSchema = z.object({
  chatbot_id: z.uuid(),
  name: z.string(),
  status: z.enum(["draft", "active", "paused"]),
  system_prompt: z.string().nullable(),
  provider: z.enum(["fake", "openai", "anthropic", "gemini"]),
  model: z.string().nullable(),
  created_at: z.string().nullable(),
  updated_at: z.string().nullable(),
});

export type ChatbotConfig = z.infer<typeof chatbotSchema>;

export const chatbotListSchema = z.object({
  data: z.array(chatbotSchema),
});

export const businessHoursSchema = z.object({
  enabled: z.boolean(),
  timezone: z.string(),
  // Per-day ranges: { mon: [["09:00","17:00"]], ... } — absent days = closed.
  schedule: z.record(z.string(), z.array(z.tuple([z.string(), z.string()]))),
});

export type BusinessHours = z.infer<typeof businessHoursSchema>;

export const triggerSchema = z.object({
  id: z.string(),
  enabled: z.boolean(),
  type: z.enum(["time_on_page", "url_match"]),
  seconds: z.number().int().optional(),
  url_pattern: z.string().optional(),
  message: z.string(),
});

export type Trigger = z.infer<typeof triggerSchema>;

export const appearanceSchema = z.object({
  preset: z.enum(["classic", "gradient", "midnight", "sunset", "emerald"]),
  accent: z.string().nullable(),
  logo_url: z.string().nullable(),
  title: z.string().nullable(),
  subtitle: z.string().nullable(),
});

export type Appearance = z.infer<typeof appearanceSchema>;

export const widgetSettingsSchema = z.object({
  site_key: z.string().nullable(),
  signing_configured: z.boolean(),
  prechat: z.object({ enabled: z.boolean(), require_email: z.boolean() }),
  business_hours: businessHoursSchema,
  triggers: z.array(triggerSchema),
  appearance: appearanceSchema,
  white_label: z.boolean(),
});

export type WidgetSettings = z.infer<typeof widgetSettingsSchema>;

export const cannedResponseSchema = z.object({
  canned_response_id: z.uuid(),
  title: z.string(),
  shortcut: z.string(),
  body: z.string(),
  created_at: z.string().nullable(),
});

export type CannedResponse = z.infer<typeof cannedResponseSchema>;

export const cannedResponseListSchema = z.object({
  data: z.array(cannedResponseSchema),
});

export const liveVisitorsSchema = z.object({
  data: z.array(
    z.object({
      lead_score: z.number().int(),
      lead_bucket: z.enum(["hot", "warm", "cold"]),
      visitor_id: z.uuid(),
      display_name: z.string().nullable(),
      contact_name: z.string().nullable(),
      contact_email: z.string().nullable(),
      consent_state: z.string(),
      current_url: z.string().nullable(),
      page_title: z.string().nullable(),
      first_seen_at: z.string().nullable(),
      last_seen_at: z.string().nullable(),
      conversation_id: z.uuid().nullable(),
    }),
  ),
});

export type LiveVisitor = z.infer<typeof liveVisitorsSchema>["data"][number];

export const rotatedSecretSchema = z.object({
  signing_secret: z.string().min(1),
});

export const departmentSchema = z.object({
  department_id: z.uuid(),
  name: z.string(),
  is_default: z.boolean(),
  assignment_strategy: z.enum(["round_robin", "least_busy", "manual"]).default("least_busy"),
  member_count: z.number().int().nonnegative(),
  member_ids: z.array(z.uuid()),
  created_at: z.string().nullable(),
});

export type Department = z.infer<typeof departmentSchema>;

export const departmentListSchema = z.object({
  data: z.array(departmentSchema),
});

export const userSummarySchema = z.object({
  user_id: z.uuid(),
  name: z.string(),
  email: z.string(),
});

export const userListSchema = z.object({
  data: z.array(userSummarySchema),
});

export const knowledgeDocumentSchema = z.object({
  document_id: z.uuid(),
  title: z.string(),
  status: z.enum(["pending", "ready", "failed"]),
  chunk_count: z.number().int().nonnegative(),
  created_at: z.string().nullable(),
});

export type KnowledgeDocument = z.infer<typeof knowledgeDocumentSchema>;

export const knowledgeListSchema = z.object({
  data: z.array(knowledgeDocumentSchema),
});

/** RFC 9457 Problem Details (§15) as emitted by the control plane. */
export const problemSchema = z.object({
  title: z.string().optional(),
  status: z.number().optional(),
  detail: z.string().optional(),
  errors: z.record(z.string(), z.array(z.string())).optional(),
  message: z.string().optional(), // Laravel validation envelope
});

export type Problem = z.infer<typeof problemSchema>;
