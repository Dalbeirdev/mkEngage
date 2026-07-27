import { type NextRequest, NextResponse } from "next/server";

import { getSessionToken } from "@/lib/auth/session";

/**
 * BFF proxy: client components call same-origin /api/cp/<path>; this handler
 * attaches the httpOnly-cookie token server-side and forwards to the control
 * plane. The browser never sees the token (ADR-009).
 *
 * Path allowlist: only the agent conversation surface is proxied — this is
 * NOT a general-purpose relay.
 */
const ALLOWED = [
  /^conversations(\/[0-9a-f-]{36})?(\/messages)?$/,
  /^conversations\/[0-9a-f-]{36}\/messages\/[0-9a-f-]{36}\/reaction$/,
  /^conversations\/[0-9a-f-]{36}\/assign$/,
  /^conversations\/[0-9a-f-]{36}\/notes$/,
  /^conversations\/[0-9a-f-]{36}\/attachments(\/[0-9a-f-]{36}\/download)?$/,
  /^me\/availability$/,
  /^insights\/overview$/,
  /^visitors\/live$/,
  /^canned-responses(\/[0-9a-f-]{36})?$/,
  /^channels(\/[0-9a-f-]{36})?$/,
  /^contacts(\/[0-9a-f-]{36})?$/,
  /^chatbots(\/[0-9a-f-]{36})?(\/flow)?$/,
  /^organization\/widget-settings(\/rotate-secret)?$/,
  /^departments(\/[0-9a-f-]{36})?(\/members)?$/,
  /^users$/,
  /^gateway-token$/,
  /^knowledge\/documents(\/[0-9a-f-]{36})?$/,
];

const API_URL = process.env.CONTROL_PLANE_API_URL ?? "http://127.0.0.1:8000";

async function proxy(request: NextRequest, path: string[]): Promise<NextResponse> {
  const joined = path.join("/");

  if (!ALLOWED.some((pattern) => pattern.test(joined))) {
    return NextResponse.json({ title: "Not found", status: 404 }, { status: 404 });
  }

  const token = await getSessionToken();
  if (token === null) {
    return NextResponse.json({ title: "Unauthenticated", status: 401 }, { status: 401 });
  }

  const headers: Record<string, string> = {
    Accept: "application/json",
    Authorization: `Bearer ${token}`,
  };

  let body: BodyInit | null = null;
  if (request.method !== "GET" && request.method !== "HEAD") {
    const contentType = request.headers.get("content-type") ?? "";
    if (contentType.startsWith("multipart/form-data")) {
      // File uploads: forward the raw bytes with the original boundary.
      body = Buffer.from(await request.arrayBuffer());
      headers["Content-Type"] = contentType;
    } else {
      body = await request.text();
      headers["Content-Type"] = "application/json";
    }
  }

  const upstream = await fetch(`${API_URL}/api/${joined}${request.nextUrl.search}`, {
    method: request.method,
    headers,
    body,
    cache: "no-store",
  });

  const payload = await upstream.text();
  return new NextResponse(payload, {
    status: upstream.status,
    headers: { "Content-Type": "application/json", "Cache-Control": "no-store" },
  });
}

export async function GET(
  request: NextRequest,
  context: { params: Promise<{ path: string[] }> },
): Promise<NextResponse> {
  return proxy(request, (await context.params).path);
}

export async function POST(
  request: NextRequest,
  context: { params: Promise<{ path: string[] }> },
): Promise<NextResponse> {
  return proxy(request, (await context.params).path);
}

export async function DELETE(
  request: NextRequest,
  context: { params: Promise<{ path: string[] }> },
): Promise<NextResponse> {
  return proxy(request, (await context.params).path);
}

export async function PUT(
  request: NextRequest,
  context: { params: Promise<{ path: string[] }> },
): Promise<NextResponse> {
  return proxy(request, (await context.params).path);
}

export async function PATCH(
  request: NextRequest,
  context: { params: Promise<{ path: string[] }> },
): Promise<NextResponse> {
  return proxy(request, (await context.params).path);
}
