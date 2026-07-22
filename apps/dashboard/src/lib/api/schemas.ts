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

/** RFC 9457 Problem Details (§15) as emitted by the control plane. */
export const problemSchema = z.object({
  title: z.string().optional(),
  status: z.number().optional(),
  detail: z.string().optional(),
  errors: z.record(z.string(), z.array(z.string())).optional(),
  message: z.string().optional(), // Laravel validation envelope
});

export type Problem = z.infer<typeof problemSchema>;
