"use server";

import { redirect } from "next/navigation";
import { z } from "zod";

import { apiFetch } from "@/lib/api/server";
import { problemSchema, tokenResponseSchema } from "@/lib/api/schemas";
import { setSessionToken } from "@/lib/auth/session";

const signupInputSchema = z.object({
  organization_name: z.string().min(2).max(100),
  name: z.string().min(1).max(255),
  email: z.email().max(255),
  password: z.string().min(8).max(1024),
});

export type SignupState = {
  error: string | null;
};

export async function signup(
  _previous: SignupState,
  formData: FormData,
): Promise<SignupState> {
  const parsed = signupInputSchema.safeParse({
    organization_name: formData.get("organization_name"),
    name: formData.get("name"),
    email: formData.get("email"),
    password: formData.get("password"),
  });

  if (!parsed.success) {
    return { error: "Enter your company, name, email, and a password of at least 8 characters." };
  }

  const response = await apiFetch(
    "/api/auth/register",
    { method: "POST", body: JSON.stringify(parsed.data) },
    null,
  );

  if (!response.ok) {
    if (response.status === 429) {
      return { error: "Too many signups from your network. Try again in a bit." };
    }
    const problem = problemSchema.safeParse(await response.json().catch(() => ({})));
    return {
      error:
        problem.success && problem.data.message
          ? problem.data.message
          : "We couldn’t create your account. Please try again.",
    };
  }

  const body = tokenResponseSchema.parse(await response.json());
  await setSessionToken(body.token);

  redirect("/dashboard");
}
