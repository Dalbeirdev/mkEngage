// mkEngage — k6 smoke test (release-gate scaffold, DEF: performance not executed).
//
// Covers the implemented hot paths against a running control plane:
//   - widget session bootstrap        (public)
//   - conversation creation           (visitor token)
//   - message acceptance              (the §27 persist-before-confirm path)
//   - message history                 (replay/list)
//
// Thresholds encode the assignment's gates: core API p95 < 400 ms,
// message acceptance p95 < 300 ms, error rate < 1%.
//
// Run (once k6 is installed):
//   BASE_URL=http://127.0.0.1:8000 SITE_KEY=sk_demo_acme_2026 k6 run perf/k6/smoke.js
//
// This is a SMOKE profile (1 VU, short). Average-load / stress / spike / soak
// profiles should reuse these request groups with different `stages`.

import http from "k6/http";
import { check, group } from "k6";
import { Trend, Rate } from "k6/metrics";

const BASE = __ENV.BASE_URL || "http://127.0.0.1:8000";
const SITE_KEY = __ENV.SITE_KEY || "sk_demo_acme_2026";

const acceptLatency = new Trend("message_accept_ms", true);
const apiErrors = new Rate("api_errors");

export const options = {
  vus: 1,
  duration: "30s",
  thresholds: {
    // Core API p95 < 400 ms (excludes external AI latency — no AI call here).
    http_req_duration: ["p(95)<400"],
    // Message acceptance p95 < 300 ms.
    "message_accept_ms": ["p(95)<300"],
    // Error rate < 1%.
    "api_errors": ["rate<0.01"],
    "http_req_failed": ["rate<0.01"],
  },
};

function jsonHeaders(token) {
  const h = { "Content-Type": "application/json", Accept: "application/json" };
  if (token) h["Authorization"] = `Bearer ${token}`;
  return h;
}

export default function () {
  let token = null;
  let conversationId = null;

  group("widget session bootstrap", () => {
    const res = http.post(
      `${BASE}/api/widget/session`,
      JSON.stringify({ site_key: SITE_KEY }),
      { headers: jsonHeaders() },
    );
    apiErrors.add(res.status !== 201);
    check(res, { "session 201": (r) => r.status === 201 });
    token = res.json("token");
  });

  group("conversation creation", () => {
    const res = http.post(`${BASE}/api/widget/conversations`, "{}", {
      headers: jsonHeaders(token),
    });
    apiErrors.add(res.status !== 201);
    check(res, { "conversation 201": (r) => r.status === 201 });
    conversationId = res.json("conversation_id");
  });

  group("message acceptance (persist-before-confirm)", () => {
    const key = `${Date.now()}-${Math.random().toString(16).slice(2)}`;
    // A UUIDv7-ish key is required by the API; a real run should import a uuid lib.
    const idempotencyKey = `019f0000-0000-7000-8000-${key.replace(/[^0-9a-f]/g, "0").slice(0, 12).padEnd(12, "0")}`;
    const res = http.post(
      `${BASE}/api/widget/conversations/${conversationId}/messages`,
      JSON.stringify({ idempotency_key: idempotencyKey, content_type: "text", body: "k6 smoke" }),
      { headers: jsonHeaders(token) },
    );
    acceptLatency.add(res.timings.duration);
    apiErrors.add(res.status !== 201 && res.status !== 200);
    check(res, { "message accepted": (r) => r.status === 201 || r.status === 200 });
  });

  group("message history", () => {
    const res = http.get(
      `${BASE}/api/widget/conversations/${conversationId}/messages?after_sequence=0`,
      { headers: jsonHeaders(token) },
    );
    apiErrors.add(res.status !== 200);
    check(res, { "history 200": (r) => r.status === 200 });
  });
}
