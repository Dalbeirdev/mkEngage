/**
 * Polling transport with exponential backoff (§4: survive temporary network
 * loss, handle reconnection). Interface-compatible slot for the future
 * WebSocket transport (ADR-002) — the widget consumes `Transport`, so the
 * gateway phase swaps implementations without touching UI code.
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
