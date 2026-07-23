/**
 * Minimal Phoenix Channels V1 client (JSON serializer) — just what the
 * widget needs: connect, heartbeat, join one topic, push with reply,
 * event callbacks. ~1 KB instead of the full phoenix.js dependency.
 */

export interface PhoenixFrame {
  topic: string;
  event: string;
  payload: unknown;
  ref: string | null;
}

export const encodeFrame = (frame: PhoenixFrame): string => JSON.stringify(frame);

export const decodeFrame = (raw: string): PhoenixFrame | null => {
  try {
    const parsed = JSON.parse(raw) as Partial<PhoenixFrame>;
    if (typeof parsed.topic !== "string" || typeof parsed.event !== "string") return null;
    return {
      topic: parsed.topic,
      event: parsed.event,
      payload: parsed.payload,
      ref: typeof parsed.ref === "string" ? parsed.ref : null,
    };
  } catch {
    return null;
  }
};

interface Reply {
  status: string;
  response: unknown;
}

export type SocketFactory = (url: string) => WebSocket;

export class PhoenixSocket {
  private ws: WebSocket | null = null;

  private refCounter = 0;

  private readonly pending = new Map<string, (reply: Reply) => void>();

  private readonly listeners = new Map<string, (payload: unknown) => void>();

  private heartbeat: ReturnType<typeof setInterval> | null = null;

  private closedByUs = false;

  onClose: (() => void) | null = null;

  constructor(
    private readonly url: string,
    private readonly factory: SocketFactory = (u) => new WebSocket(u),
  ) {}

  connect(): Promise<void> {
    return new Promise((resolve, reject) => {
      const ws = this.factory(this.url);
      this.ws = ws;

      ws.onopen = () => {
        this.heartbeat = setInterval(() => {
          this.rawSend({ topic: "phoenix", event: "heartbeat", payload: {}, ref: this.nextRef() });
        }, 30_000);
        resolve();
      };
      ws.onerror = () => reject(new Error("socket error"));
      ws.onclose = () => {
        this.teardown();
        this.onClose?.();
      };
      ws.onmessage = (event) => this.handleFrame(String(event.data));
    });
  }

  close(): void {
    this.closedByUs = true;
    this.onClose = null;
    this.ws?.close();
    this.teardown();
  }

  get intentionallyClosed(): boolean {
    return this.closedByUs;
  }

  /** Push an event on a topic; resolves with the reply, rejects on error status/timeout. */
  push(topic: string, event: string, payload: unknown, timeoutMs = 10_000): Promise<unknown> {
    return new Promise((resolve, reject) => {
      const ref = this.nextRef();
      const timer = setTimeout(() => {
        this.pending.delete(ref);
        reject(new Error("reply timeout"));
      }, timeoutMs);

      this.pending.set(ref, (reply) => {
        clearTimeout(timer);
        if (reply.status === "ok") resolve(reply.response);
        else reject(new Error(`reply status ${reply.status}`));
      });

      this.rawSend({ topic, event, payload, ref });
    });
  }

  join(topic: string): Promise<unknown> {
    return this.push(topic, "phx_join", {});
  }

  /** Register a broadcast listener (one per event name — widget-sized). */
  on(event: string, callback: (payload: unknown) => void): void {
    this.listeners.set(event, callback);
  }

  private handleFrame(raw: string): void {
    const frame = decodeFrame(raw);
    if (frame === null) return;

    if (frame.event === "phx_reply" && frame.ref !== null) {
      const resolver = this.pending.get(frame.ref);
      if (resolver !== undefined) {
        this.pending.delete(frame.ref);
        resolver(frame.payload as Reply);
      }
      return;
    }

    this.listeners.get(frame.event)?.(frame.payload);
  }

  private rawSend(frame: PhoenixFrame): void {
    if (this.ws?.readyState === WebSocket.OPEN) {
      this.ws.send(encodeFrame(frame));
    }
  }

  private nextRef(): string {
    this.refCounter += 1;
    return String(this.refCounter);
  }

  private teardown(): void {
    if (this.heartbeat !== null) {
      clearInterval(this.heartbeat);
      this.heartbeat = null;
    }
    this.pending.clear();
  }
}
