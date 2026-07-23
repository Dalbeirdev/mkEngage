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
  last_sequence: z.number().int().nonnegative(),
  source_url: z.string().nullable(),
  created_at: z.string().nullable(),
  updated_at: z.string().nullable(),
});

export type Conversation = z.infer<typeof conversationSchema>;

export const conversationListSchema = z.object({
  data: z.array(conversationSchema),
});

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
  provider: z.enum(["fake", "openai", "anthropic"]),
  model: z.string().nullable(),
  created_at: z.string().nullable(),
  updated_at: z.string().nullable(),
});

export type ChatbotConfig = z.infer<typeof chatbotSchema>;

export const chatbotListSchema = z.object({
  data: z.array(chatbotSchema),
});

export const widgetSettingsSchema = z.object({
  site_key: z.string().nullable(),
  signing_configured: z.boolean(),
});

export const rotatedSecretSchema = z.object({
  signing_secret: z.string().min(1),
});

export const departmentSchema = z.object({
  department_id: z.uuid(),
  name: z.string(),
  is_default: z.boolean(),
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
