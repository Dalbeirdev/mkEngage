"use server";

import { z } from "zod";

import { apiFetch } from "@/lib/api/server";

export type LeadState = { status: "idle" | "ok" | "error"; message?: string };

const contactSchema = z.object({
  name: z.string().min(1).max(255),
  email: z.email().max(255),
  company: z.string().max(255).optional(),
  subject: z.string().max(100).optional(),
  message: z.string().min(1).max(5000),
});

const newsletterSchema = z.object({ email: z.email().max(255) });

function value(form: FormData, key: string): string | undefined {
  const raw = form.get(key);
  return typeof raw === "string" && raw.trim() !== "" ? raw.trim() : undefined;
}

/** Public contact form → control-plane /api/contact (no auth token). */
export async function submitContact(_prev: LeadState, formData: FormData): Promise<LeadState> {
  const parsed = contactSchema.safeParse({
    name: value(formData, "name"),
    email: value(formData, "email"),
    company: value(formData, "company"),
    subject: value(formData, "subject"),
    message: value(formData, "message"),
  });

  if (!parsed.success) {
    return { status: "error", message: "Please enter your name, a valid email, and a message." };
  }

  const response = await apiFetch(
    "/api/contact",
    { method: "POST", body: JSON.stringify(parsed.data) },
    null,
  );

  if (!response.ok) {
    return {
      status: "error",
      message:
        response.status === 429
          ? "You’ve sent a few messages already — please try again a little later."
          : "Something went wrong sending your message. Please try again.",
    };
  }

  return { status: "ok", message: "Thanks! Your message is in — we’ll reply within 24 hours." };
}

/** Public newsletter opt-in → control-plane /api/newsletter (no auth token). */
export async function subscribeNewsletter(_prev: LeadState, formData: FormData): Promise<LeadState> {
  const parsed = newsletterSchema.safeParse({ email: value(formData, "email") });

  if (!parsed.success) {
    return { status: "error", message: "Enter a valid email address." };
  }

  const response = await apiFetch(
    "/api/newsletter",
    { method: "POST", body: JSON.stringify({ ...parsed.data, source: "website" }) },
    null,
  );

  if (!response.ok) {
    return {
      status: "error",
      message:
        response.status === 429
          ? "Too many requests — please try again shortly."
          : "Couldn’t subscribe you just now. Please try again.",
    };
  }

  return { status: "ok", message: "You’re subscribed — watch your inbox for product updates." };
}
