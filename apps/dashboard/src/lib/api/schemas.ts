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

/** RFC 9457 Problem Details (§15) as emitted by the control plane. */
export const problemSchema = z.object({
  title: z.string().optional(),
  status: z.number().optional(),
  detail: z.string().optional(),
  errors: z.record(z.string(), z.array(z.string())).optional(),
  message: z.string().optional(), // Laravel validation envelope
});

export type Problem = z.infer<typeof problemSchema>;
