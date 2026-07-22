import "server-only";

import { getSessionToken } from "@/lib/auth/session";

/**
 * Server-side API client for the control plane (§3 server-side
 * authorization: tokens never reach the browser). Base URL is server-env
 * only — the browser talks to Next.js, Next.js talks to the API.
 */
const API_URL = process.env.CONTROL_PLANE_API_URL ?? "http://127.0.0.1:8000";

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly problem: unknown,
  ) {
    super(`API request failed with status ${status}`);
  }
}

export async function apiFetch(
  path: string,
  init: RequestInit = {},
  token?: string | null,
): Promise<Response> {
  const bearer = token ?? (await getSessionToken());

  const headers = new Headers(init.headers);
  headers.set("Accept", "application/json");
  if (init.body !== undefined) headers.set("Content-Type", "application/json");
  if (bearer) headers.set("Authorization", `Bearer ${bearer}`);

  return fetch(`${API_URL}${path}`, {
    ...init,
    headers,
    cache: "no-store", // authenticated data is never edge/data-cached (ADR-009)
  });
}

export async function apiJson<T>(
  path: string,
  parse: (data: unknown) => T,
  init: RequestInit = {},
): Promise<T> {
  const response = await apiFetch(path, init);

  if (!response.ok) {
    throw new ApiError(response.status, await response.json().catch(() => null));
  }

  return parse(await response.json());
}
