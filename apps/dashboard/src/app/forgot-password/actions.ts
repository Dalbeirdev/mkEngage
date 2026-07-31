"use server";

import { z } from "zod";

import { apiFetch } from "@/lib/api/server";

const forgotInputSchema = z.object({
  organization: z.string().min(1).max(100),
  email: z.email().max(255),
});

export type ForgotState = {
  error: string | null;
  sent: boolean;
};

export async function forgotPassword(
  _previous: ForgotState,
  formData: FormData,
): Promise<ForgotState> {
  const parsed = forgotInputSchema.safeParse({
    organization: formData.get("organization"),
    email: formData.get("email"),
  });

  if (!parsed.success) {
    return { error: "Enter your organization and email address.", sent: false };
  }

  const response = await apiFetch(
    "/api/auth/forgot-password",
    { method: "POST", body: JSON.stringify(parsed.data) },
    null,
  );

  if (response.status === 429) {
    return { error: "Too many attempts. Try again in a few minutes.", sent: false };
  }
  if (!response.ok) {
    return { error: "Something went wrong. Try again.", sent: false };
  }

  // Always "sent" — the control plane never reveals whether the account exists.
  return { error: null, sent: true };
}
