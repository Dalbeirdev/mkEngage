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
});

export type LoginState = {
  error: string | null;
};

export async function login(
  _previous: LoginState,
  formData: FormData,
): Promise<LoginState> {
  const parsed = loginInputSchema.safeParse({
    organization: formData.get("organization"),
    email: formData.get("email"),
    password: formData.get("password"),
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

    // Identical message regardless of which credential was wrong — the
    // control plane already guarantees this; do not re-derive detail here.
    const problem = problemSchema.safeParse(await response.json().catch(() => ({})));
    return {
      error:
        problem.success && problem.data.message
          ? problem.data.message
          : "These credentials do not match our records.",
    };
  }

  const body = tokenResponseSchema.parse(await response.json());
  await setSessionToken(body.token);

  redirect("/");
}

export async function logout(): Promise<void> {
  await clearSessionToken();
  redirect("/login");
}
