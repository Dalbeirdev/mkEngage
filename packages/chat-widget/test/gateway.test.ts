import { describe, expect, it, vi } from "vitest";

import { decodeFrame, encodeFrame } from "../src/phoenix.js";
import { GatewayTransport, orgFromGatewayToken } from "../src/transport.js";
import type { ChatMessage } from "../src/types.js";

describe("phoenix frame codec", () => {
  it("round-trips frames", () => {
    const frame = { topic: "conv:o:c", event: "message:send", payload: { a: 1 }, ref: "3" };
    expect(decodeFrame(encodeFrame(frame))).toEqual(frame);
  });

  it("rejects malformed frames", () => {
    expect(decodeFrame("not json")).toBeNull();
    expect(decodeFrame(JSON.stringify({ event: "x" }))).toBeNull();
  });
});

describe("orgFromGatewayToken", () => {
  it("decodes the org claim from the public payload", () => {
    const payload = btoa(JSON.stringify({ org: "org-123", sub: "visitor:v", exp: 1 }))
      .replace(/\+/g, "-")
      .replace(/\//g, "_")
      .replace(/=+$/, "");
    expect(orgFromGatewayToken(`${payload}.signature`)).toBe("org-123");
  });

  it("returns null for garbage", () => {
    expect(orgFromGatewayToken("nope")).toBeNull();
    expect(orgFromGatewayToken("")).toBeNull();
  });
});

/** Scripted fake WebSocket implementing the Phoenix handshake server-side. */
class FakeSocket {
  static instances: FakeSocket[] = [];

  onopen: (() => void) | null = null;

  onclose: (() => void) | null = null;

  onerror: (() => void) | null = null;

  onmessage: ((event: { data: string }) => void) | null = null;

  readyState = 1; // OPEN

  sent: Array<{ topic: string; event: string; payload: unknown; ref: string | null }> = [];

  constructor(public url: string) {
    FakeSocket.instances.push(this);
    queueMicrotask(() => this.onopen?.());
  }

  send(raw: string): void {
    const frame = JSON.parse(raw);
    this.sent.push(frame);

    // Auto-reply ok to joins and replay requests.
    if (frame.event === "phx_join") {
      this.reply(frame.ref, { status: "ok", response: {} });
    }
    if (frame.event === "replay:request") {
      this.reply(frame.ref, {
        status: "ok",
        response: { messages: [message(2, "replayed")], has_more: false },
      });
    }
  }

  close(): void {
    this.readyState = 3;
    this.onclose?.();
  }

  reply(ref: string, payload: unknown): void {
    this.onmessage?.({
      data: JSON.stringify({ topic: "any", event: "phx_reply", payload, ref }),
    });
  }

  broadcast(event: string, payload: unknown): void {
    this.onmessage?.({ data: JSON.stringify({ topic: "any", event, payload, ref: null }) });
  }
}

function message(seq: number, body: string): ChatMessage {
  return {
    message_id: `m-${seq}`,
    conversation_id: "c-1",
    channel_id: null,
    sender_type: "agent",
    sender_id: "a-1",
    sequence_number: seq,
    content_type: "text",
    body,
    lifecycle_state: "persisted",
    sent_at: "2026-07-24T00:00:00Z",
  };
}

const validToken = () => {
  const payload = btoa(JSON.stringify({ org: "org-1", sub: "visitor:v", exp: 99 }))
    .replace(/\+/g, "-")
    .replace(/\//g, "_")
    .replace(/=+$/, "");
  return `${payload}.sig`;
};

describe("GatewayTransport", () => {
  it("connects, joins the conv topic, replays the gap, then receives live pushes", async () => {
    FakeSocket.instances = [];
    const received: ChatMessage[][] = [];
    const states: string[] = [];

    const transport = new GatewayTransport({
      fetchToken: async () => ({ token: validToken(), url: "ws://gw/socket" }),
      conversationId: () => "c-1",
      lastSeenSequence: () => 1,
      onMessages: (m) => received.push(m),
      onStateChange: (s) => states.push(s),
      socketFactory: (url) => new FakeSocket(url) as unknown as WebSocket,
    });

    await transport.startAndConfirm();

    const socket = FakeSocket.instances[0]!;
    expect(socket.url).toContain("token=");
    expect(socket.sent.find((f) => f.event === "phx_join")?.topic).toBe("conv:org-1:c-1");
    expect(socket.sent.find((f) => f.event === "replay:request")?.payload).toEqual({
      last_seen_seq: 1,
    });
    expect(received[0]![0]!.body).toBe("replayed");
    expect(states.at(-1)).toBe("connected");

    socket.broadcast("message:new", message(3, "live push"));
    expect(received[1]![0]!.body).toBe("live push");

    transport.stop();
  });

  it("rejects startAndConfirm when the token fetch fails (caller falls back to polling)", async () => {
    const transport = new GatewayTransport({
      fetchToken: async () => {
        throw new Error("404");
      },
      conversationId: () => "c-1",
      lastSeenSequence: () => 0,
      onMessages: () => {},
      onStateChange: () => {},
    });

    await expect(transport.startAndConfirm()).rejects.toThrow();
  });

  it("signals reconnecting when the socket drops unexpectedly", async () => {
    FakeSocket.instances = [];
    const states: string[] = [];

    const transport = new GatewayTransport({
      fetchToken: async () => ({ token: validToken(), url: "ws://gw/socket" }),
      conversationId: () => "c-1",
      lastSeenSequence: () => 0,
      onMessages: () => {},
      onStateChange: (s) => states.push(s),
      socketFactory: (url) => new FakeSocket(url) as unknown as WebSocket,
    });

    await transport.startAndConfirm();
    FakeSocket.instances[0]!.close();

    expect(states.at(-1)).toBe("reconnecting");
    transport.stop();
  });
});
