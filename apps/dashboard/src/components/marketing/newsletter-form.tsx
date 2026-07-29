"use client";

import { useActionState } from "react";

import { subscribeNewsletter, type LeadState } from "@/app/(marketing)/lead-actions";

/** Footer newsletter opt-in — posts to the public /api/newsletter endpoint. */
export function NewsletterForm() {
  const [state, formAction, pending] = useActionState<LeadState, FormData>(subscribeNewsletter, {
    status: "idle",
  });

  if (state.status === "ok") {
    return (
      <p role="status" style={{ fontSize: "13.5px", color: "#4ade80", fontWeight: 600 }}>
        {state.message}
      </p>
    );
  }

  return (
    <>
      <form className="news" action={formAction} aria-label="Newsletter signup">
        <input type="email" name="email" required placeholder="Enter your email" aria-label="Email" />
        <button className="btn btn-primary" type="submit" disabled={pending}>
          {pending ? "…" : "Subscribe"}
        </button>
      </form>
      {state.status === "error" && (
        <p role="alert" style={{ fontSize: "12.5px", color: "#f87171", marginTop: 6 }}>
          {state.message}
        </p>
      )}
    </>
  );
}
