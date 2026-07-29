"use server";

import { redirect } from "next/navigation";
import { z } from "zod";

import { apiFetch } from "@/lib/api/server";
import { problemSchema, tokenResponseSchema } from "@/lib/api/schemas";
import { clearSessionToken, setSessionToken } from "@/lib/auth/session";

const loginInputSchema = z.object({
  organization: z.string().min(1).max(100),
  email: z.email().max(255),
  password: z.string().min(1).max(1024),
  code: z.string().max(10).optional(),
});

export type LoginState = {
  error: string | null;
  /** Set once the account requires a TOTP code — reveals the code field. */
  twoFactorRequired?: boolean;
};

export async function login(
  _previous: LoginState,
  formData: FormData,
): Promise<LoginState> {
  const codeRaw = formData.get("code");
  const code = typeof codeRaw === "string" && codeRaw.trim() !== "" ? codeRaw.trim() : undefined;

  const parsed = loginInputSchema.safeParse({
    organization: formData.get("organization"),
    email: formData.get("email"),
    password: formData.get("password"),
    code,
  });

  if (!parsed.success) {
    return { error: "Enter your organization, email, and password." };
  }

  const response = await apiFetch(
    "/api/auth/token",
    {
      method: "POST",
      body: JSON.stringify({ ...parsed.data, device_name: "dashboard" }),
    },
    null,
  );

  if (!response.ok) {
    if (response.status === 429) {
      return { error: "Too many attempts. Try again in a minute." };
    }

    const json: unknown = await response.json().catch(() => ({}));

    // 2FA-enabled account: reveal the code field and prompt (the password was
    // correct, so this is not a credential error).
    if (
      response.status === 422 &&
      typeof json === "object" &&
      json !== null &&
      (json as { two_factor_required?: unknown }).two_factor_required === true
    ) {
      const message =
        typeof (json as { message?: unknown }).message === "string"
          ? (json as { message: string }).message
          : "Enter the code from your authenticator app.";
      return { error: code !== undefined ? message : null, twoFactorRequired: true };
    }

    // Identical message regardless of which credential was wrong — the
    // control plane already guarantees this; do not re-derive detail here.
    const problem = problemSchema.safeParse(json);
    return {
      error:
        problem.success && problem.data.message
          ? problem.data.message
          : "These credentials do not match our records.",
    };
  }

  const body = tokenResponseSchema.parse(await response.json());
  await setSessionToken(body.token);

  redirect("/dashboard");
}

export async function logout(): Promise<void> {
  await clearSessionToken();
  redirect("/login");
}
