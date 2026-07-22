import { describe, expect, it, vi } from "vitest";

import { PollingTransport } from "../src/transport.js";

describe("PollingTransport", () => {
  it("polls immediately on start and repeats on the base interval", async () => {
    vi.useFakeTimers();
    const poll = vi.fn().mockResolvedValue(undefined);
    const transport = new PollingTransport(poll, () => {}, { intervalMs: 1000 });

    transport.start();
    await vi.advanceTimersByTimeAsync(0);
    expect(poll).toHaveBeenCalledTimes(1);

    await vi.advanceTimersByTimeAsync(1000);
    expect(poll).toHaveBeenCalledTimes(2);

    transport.stop();
    vi.useRealTimers();
  });

  it("backs off exponentially on failures and recovers (§4 reconnection)", async () => {
    vi.useFakeTimers();
    const poll = vi.fn().mockRejectedValue(new Error("down"));
    const states: string[] = [];
    const transport = new PollingTransport(poll, (state) => states.push(state), {
      intervalMs: 1000,
      maxBackoffMs: 8000,
    });

    transport.start();
    await vi.advanceTimersByTimeAsync(0);
    expect(transport.currentDelay).toBe(2000);

    await vi.advanceTimersByTimeAsync(2000);
    expect(transport.currentDelay).toBe(4000);

    await vi.advanceTimersByTimeAsync(4000);
    expect(transport.currentDelay).toBe(8000);

    // Cap holds.
    await vi.advanceTimersByTimeAsync(8000);
    expect(transport.currentDelay).toBe(8000);
    expect(states).toContain("reconnecting");

    // Recovery resets the delay.
    poll.mockResolvedValue(undefined);
    await vi.advanceTimersByTimeAsync(8000);
    expect(transport.currentDelay).toBe(1000);
    expect(states.at(-1)).toBe("connected");

    transport.stop();
    vi.useRealTimers();
  });

  it("stops cleanly and ignores pokes after stop", async () => {
    vi.useFakeTimers();
    const poll = vi.fn().mockResolvedValue(undefined);
    const transport = new PollingTransport(poll, () => {}, { intervalMs: 1000 });

    transport.start();
    await vi.advanceTimersByTimeAsync(0);
    transport.stop();
    transport.poke();
    await vi.advanceTimersByTimeAsync(5000);

    expect(poll).toHaveBeenCalledTimes(1);
    vi.useRealTimers();
  });
});
