// mkEngage smoke load test (k6). Run against a disposable/staging stack —
// it registers its own throwaway organization, then simulates 25 widget
// visitors chatting (session → conversation → message + thread poll loop)
// alongside 3 agents polling the inbox.
//
//   docker run --rm --network mkengage_default \
//     -v /path/to/tools/loadtest:/scripts grafana/k6 run \
//     -e BASE_URL=http://caddy-lan:80 /scripts/k6-smoke.js
//
// Sized under the per-IP widget-session limit (30/min) so results measure
// capacity, not the rate limiter. Clean up afterwards:
//   DELETE FROM organizations WHERE name LIKE 'Load Test %';
import http from "k6/http";
import { check, sleep } from "k6";
import { uuidv4 } from "https://jslib.k6.io/k6-utils/1.4.0/index.js";

const BASE = __ENV.BASE_URL || "http://caddy-lan:80";
const JSON_HEADERS = { "Content-Type": "application/json", Accept: "application/json" };

export const options = {
  scenarios: {
    visitors: {
      executor: "ramping-vus",
      exec: "visitor",
      startVUs: 0,
      stages: [
        { duration: "30s", target: 25 },
        { duration: "60s", target: 25 },
        { duration: "10s", target: 0 },
      ],
    },
    agents: {
      executor: "constant-vus",
      exec: "agent",
      vus: 3,
      duration: "100s",
    },
  },
  thresholds: {
    http_req_failed: ["rate<0.02"],
    http_req_duration: ["p(95)<800"],
  },
};

export function setup() {
  const suffix = `${Date.now()}`;
  const reg = http.post(
    `${BASE}/api/auth/register`,
    JSON.stringify({
      organization_name: `Load Test ${suffix}`,
      name: "Load Owner",
      email: `load-${suffix}@mkengage.test`,
      password: `Load-pass-${suffix}`,
    }),
    { headers: JSON_HEADERS },
  );
  check(reg, { "org registered": (r) => r.status === 201 });
  const token = reg.json("token");

  const settings = http.get(`${BASE}/api/organization/widget-settings`, {
    headers: { ...JSON_HEADERS, Authorization: `Bearer ${token}` },
  });
  check(settings, { "site key fetched": (r) => r.status === 200 });

  return { agentToken: token, siteKey: settings.json("site_key") };
}

// Per-VU state (each VU runs its own JS VM).
let visitorToken = null;
let conversationId = null;

function authHeaders(token) {
  return { ...JSON_HEADERS, Authorization: `Bearer ${token}` };
}

export function visitor(data) {
  if (visitorToken === null) {
    const session = http.post(
      `${BASE}/api/widget/session`,
      JSON.stringify({ site_key: data.siteKey }),
      { headers: JSON_HEADERS, tags: { name: "widget-session" } },
    );
    if (!check(session, { "session ok": (r) => r.status === 200 || r.status === 201 })) {
      sleep(5);
      return;
    }
    visitorToken = session.json("token");

    const conversation = http.post(`${BASE}/api/widget/conversations`, "{}", {
      headers: authHeaders(visitorToken),
      tags: { name: "widget-conversation" },
    });
    check(conversation, { "conversation ok": (r) => r.status === 200 || r.status === 201 });
    conversationId = conversation.json("conversation_id") || conversation.json("id");
  }

  const message = http.post(
    `${BASE}/api/widget/conversations/${conversationId}/messages`,
    JSON.stringify({
      body: `load message ${__VU}-${__ITER}`,
      content_type: "text",
      idempotency_key: uuidv4(),
    }),
    { headers: authHeaders(visitorToken), tags: { name: "widget-message" } },
  );
  check(message, { "message created": (r) => r.status === 201 });

  const thread = http.get(`${BASE}/api/widget/conversations/${conversationId}/messages`, {
    headers: authHeaders(visitorToken),
    tags: { name: "widget-thread" },
  });
  check(thread, { "thread listed": (r) => r.status === 200 });

  sleep(1 + Math.random() * 2);
}

export function agent(data) {
  const inbox = http.get(`${BASE}/api/conversations`, {
    headers: authHeaders(data.agentToken),
    tags: { name: "agent-inbox" },
  });
  check(inbox, { "inbox listed": (r) => r.status === 200 });

  sleep(2);
}
