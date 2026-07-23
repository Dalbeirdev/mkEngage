import { PhoenixSocket, type SocketFactory } from "./phoenix.js";
import type { ChatMessage } from "./types.js";

/**
 * Transports (§4): GatewayTransport (WebSocket, ADR-002) is the primary;
 * PollingTransport is the REST fallback and the safety net whenever the
 * gateway is unreachable. The widget consumes `Transport` only.
 */

export interface Transport {
  start(): void;
  stop(): void;
  /** Nudge an immediate poll (after sending a message). */
  poke(): void;
}

export interface PollingOptions {
  intervalMs?: number;
  maxBackoffMs?: number;
}

export class PollingTransport implements Transport {
  private timer: ReturnType<typeof setTimeout> | null = null;

  private stopped = true;

  private failures = 0;

  private readonly intervalMs: number;

  private readonly maxBackoffMs: number;

  constructor(
    private readonly pollFn: () => Promise<void>,
    private readonly onStateChange: (state: "connected" | "reconnecting" | "offline") => void,
    options: PollingOptions = {},
  ) {
    this.intervalMs = options.intervalMs ?? 3000;
    this.maxBackoffMs = options.maxBackoffMs ?? 30_000;
  }

  start(): void {
    this.stopped = false;
    this.schedule(0);
  }

  stop(): void {
    this.stopped = true;
    if (this.timer !== null) {
      clearTimeout(this.timer);
      this.timer = null;
    }
  }

  poke(): void {
    if (!this.stopped) this.schedule(0);
  }

  /** Current delay under backoff — exposed for tests. */
  get currentDelay(): number {
    if (this.failures === 0) return this.intervalMs;
    return Math.min(this.intervalMs * 2 ** this.failures, this.maxBackoffMs);
  }

  private schedule(delay: number): void {
    if (this.timer !== null) clearTimeout(this.timer);
    this.timer = setTimeout(() => void this.tick(), delay);
  }

  private async tick(): Promise<void> {
    if (this.stopped) return;

    if (typeof navigator !== "undefined" && navigator.onLine === false) {
      this.onStateChange("offline");
      this.schedule(this.intervalMs);
      return;
    }

    try {
      await this.pollFn();
      if (this.failures > 0) this.failures = 0;
      this.onStateChange("connected");
      this.schedule(this.intervalMs);
    } catch {
      this.failures += 1;
      this.onStateChange("reconnecting");
      this.schedule(this.currentDelay);
    }
  }
}

/** Decode the org id from a gateway token (payload is public base64url JSON). */
export function orgFromGatewayToken(token: string): string | null {
  try {
    const [payload] = token.split(".");
    if (payload === undefined) return null;
    const decoded = JSON.parse(atob(payload.replace(/-/g, "+").replace(/_/g, "/"))) as {
      org?: unknown;
    };
    return typeof decoded.org === "string" ? decoded.org : null;
  } catch {
    return null;
  }
}

export interface GatewayDeps {
  fetchToken(): Promise<{ token: string; url: string }>;
  conversationId(): string | null;
  lastSeenSequence(): number;
  onMessages(messages: ChatMessage[]): void;
  onStateChange(state: "connected" | "reconnecting" | "offline"): void;
  socketFactory?: SocketFactory;
}

/**
 * WebSocket transport: join conv:{org}:{conv}, replay the gap since
 * last-seen (RULES-message-ordering #11), then live message:new pushes.
 * On close/error: notifies and retries with backoff; the WIDGET decides
 * when to give up and fall back to polling (start() rejects on first
 * connect failure so the caller can fall back immediately).
 */
export class GatewayTransport implements Transport {
  private socket: PhoenixSocket | null = null;

  private stopped = true;

  private retries = 0;

  constructor(private readonly deps: GatewayDeps) {}

  start(): void {
    this.stopped = false;
    void this.connect();
  }

  /** Like start(), but surfaces the FIRST connection outcome to the caller. */
  async startAndConfirm(): Promise<void> {
    this.stopped = false;
    await this.connect();
  }

  stop(): void {
    this.stopped = true;
    this.socket?.close();
    this.socket = null;
  }

  poke(): void {
    // Push transport: nothing to poke. (REST sends fan back in via broadcast.)
  }

  private async connect(): Promise<void> {
    const conversationId = this.deps.conversationId();
    if (this.stopped || conversationId === null) throw new Error("no conversation");

    const { token, url } = await this.deps.fetchToken();
    const org = orgFromGatewayToken(token);
    if (org === null) throw new Error("bad token");

    const socket = new PhoenixSocket(
      `${url.replace(/\/$/, "")}/websocket?token=${encodeURIComponent(token)}&vsn=1.0.0`,
      this.deps.socketFactory,
    );

    await socket.connect();
    this.socket = socket;

    socket.on("message:new", (payload) => {
      this.deps.onMessages([payload as ChatMessage]);
    });

    socket.onClose = () => {
      if (this.stopped) return;
      this.deps.onStateChange("reconnecting");
      this.retries += 1;
      const delay = Math.min(1000 * 2 ** this.retries, 30_000);
      setTimeout(() => {
        if (!this.stopped) {
          this.connect().catch(() => this.deps.onStateChange("reconnecting"));
        }
      }, delay);
    };

    await socket.join(`conv:${org}:${conversationId}`);

    // Replay the reconnect gap, sequence-ordered.
    const replay = (await socket.push(`conv:${org}:${conversationId}`, "replay:request", {
      last_seen_seq: this.deps.lastSeenSequence(),
    })) as { messages: ChatMessage[] };
    this.deps.onMessages(replay.messages);

    this.retries = 0;
    this.deps.onStateChange("connected");
  }
}
