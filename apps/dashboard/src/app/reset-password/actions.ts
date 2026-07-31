"use server";

import { z } from "zod";

import { apiFetch } from "@/lib/api/server";

const resetInputSchema = z.object({
  organization: z.string().min(1).max(100),
  email: z.email().max(255),
  token: z.string().min(1).max(128),
  password: z.string().min(8).max(1024),
});

export type ResetState = {
  error: string | null;
  done: boolean;
};

export async function resetPassword(
  _previous: ResetState,
  formData: FormData,
): Promise<ResetState> {
  const parsed = resetInputSchema.safeParse({
    organization: formData.get("organization"),
    email: formData.get("email"),
    token: formData.get("token"),
    password: formData.get("password"),
  });

  if (!parsed.success) {
    return { error: "Enter a new password of at least 8 characters.", done: false };
  }

  if (parsed.data.password !== formData.get("password_confirm")) {
    return { error: "The passwords don't match.", done: false };
  }

  const response = await apiFetch(
    "/api/auth/reset-password",
    { method: "POST", body: JSON.stringify(parsed.data) },
    null,
  );

  if (response.status === 429) {
    return { error: "Too many attempts. Try again in a few minutes.", done: false };
  }
  if (!response.ok) {
    return {
      error: "This reset link is invalid or has expired. Request a new one.",
      done: false,
    };
  }

  return { error: null, done: true };
}
