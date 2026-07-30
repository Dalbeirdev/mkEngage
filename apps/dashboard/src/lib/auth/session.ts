import "server-only";

import { cookies } from "next/headers";

/**
 * Token storage: httpOnly cookie, never readable by client JS (ADR-009 — no
 * tokens in frontend-accessible storage). The Sanctum token is scoped to the
 * dashboard "device" and revocable server-side.
 */
const TOKEN_COOKIE = "mk_token";

export async function setSessionToken(token: string): Promise<void> {
  const jar = await cookies();
  jar.set(TOKEN_COOKIE, token, {
    httpOnly: true,
    sameSite: "lax",
    // Secure in production — except LAN/internal deployments served over
    // plain HTTP (INSECURE_COOKIES=1 in deploy LAN mode), where a Secure
    // cookie would be silently dropped and login would fail.
    secure: process.env.NODE_ENV === "production" && process.env.INSECURE_COOKIES !== "1",
    path: "/",
    maxAge: 60 * 60 * 12, // 12h; control plane can revoke sooner
  });
}

export async function getSessionToken(): Promise<string | null> {
  const jar = await cookies();
  return jar.get(TOKEN_COOKIE)?.value ?? null;
}

export async function clearSessionToken(): Promise<void> {
  const jar = await cookies();
  jar.delete(TOKEN_COOKIE);
}

export { TOKEN_COOKIE };
