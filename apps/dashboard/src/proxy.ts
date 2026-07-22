import { NextResponse, type NextRequest } from "next/server";

import { TOKEN_COOKIE } from "@/lib/auth/session";

/**
 * Route guard: unauthenticated requests to app routes bounce to /login.
 * Presence-only check — token VALIDITY is enforced by the control plane on
 * every API call (server components hitting /api/user etc. redirect on 401).
 * Never trust this layer alone (ADR-009: every boundary re-verifies).
 */
export default function proxy(request: NextRequest) {
  const { pathname } = request.nextUrl;

  const isPublic =
    pathname.startsWith("/login") ||
    pathname.startsWith("/_next") ||
    pathname === "/favicon.ico";

  if (isPublic) {
    return NextResponse.next();
  }

  if (request.cookies.get(TOKEN_COOKIE) === undefined) {
    const login = request.nextUrl.clone();
    login.pathname = "/login";
    login.search = "";
    return NextResponse.redirect(login);
  }

  return NextResponse.next();
}

export const config = {
  matcher: ["/((?!_next/static|_next/image|favicon.ico).*)"],
};
