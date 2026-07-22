import { describe, expect, it } from "vitest";

import { problemSchema, tokenResponseSchema, userSchema } from "./schemas";

const validUser = {
  id: "0198c5a0-1111-7000-8000-000000000001",
  organization_id: "0198c5a0-2222-7000-8000-000000000002",
  name: "Agent",
  email: "agent@example.com",
  email_verified_at: null,
  status: "active",
  created_at: "2026-07-23T00:00:00Z",
  updated_at: "2026-07-23T00:00:00Z",
};

describe("userSchema", () => {
  it("accepts a contract-shaped user", () => {
    expect(userSchema.parse(validUser)).toMatchObject({ email: "agent@example.com" });
  });

  it("rejects non-uuid ids and unknown statuses", () => {
    expect(() => userSchema.parse({ ...validUser, id: "not-a-uuid" })).toThrow();
    expect(() => userSchema.parse({ ...validUser, status: "ghost" })).toThrow();
  });

  it("never exposes secret fields even if the API leaked them", () => {
    const parsed = userSchema.parse({ ...validUser, password: "hash", two_factor_secret: "s" });
    expect(parsed).not.toHaveProperty("password");
    expect(parsed).not.toHaveProperty("two_factor_secret");
  });
});

describe("tokenResponseSchema", () => {
  it("requires a non-empty token", () => {
    expect(tokenResponseSchema.parse({ token: "1|abc" }).token).toBe("1|abc");
    expect(() => tokenResponseSchema.parse({ token: "" })).toThrow();
  });
});

describe("problemSchema", () => {
  it("parses Laravel validation envelopes", () => {
    const parsed = problemSchema.parse({
      message: "These credentials do not match our records.",
      errors: { email: ["These credentials do not match our records."] },
    });
    expect(parsed.message).toContain("credentials");
  });
});
