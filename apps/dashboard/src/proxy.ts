import { NextResponse, type NextRequest } from "next/server";

import { TOKEN_COOKIE } from "@/lib/auth/session";

/**
 * Route guard: the authenticated app areas require a session; the marketing
 * site ("/", "/features", "/pricing", "/about", "/contact", "/resources",
 * "/legal/*") and "/login" are public. Presence-only check — token VALIDITY is
 * enforced by the control plane on every API call (ADR-009: every boundary
 * re-verifies).
 */
const PROTECTED_PREFIXES = [
  "/dashboard",
  "/conversations",
  "/visitors",
  "/contacts",
  "/chatbots",
  "/knowledge",
  "/settings",
];

export default function proxy(request: NextRequest) {
  const { pathname } = request.nextUrl;

  const needsAuth = PROTECTED_PREFIXES.some(
    (prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`),
  );

  if (needsAuth && request.cookies.get(TOKEN_COOKIE) === undefined) {
    const login = request.nextUrl.clone();
    login.pathname = "/login";
    login.search = "";
    return NextResponse.redirect(login);
  }

  return NextResponse.next();
}

export const config = {
  matcher: ["/((?!_next/static|_next/image|favicon.ico|icon.svg).*)"],
};
