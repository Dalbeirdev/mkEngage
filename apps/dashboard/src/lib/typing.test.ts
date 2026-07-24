import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { createTypingNotifier } from "./typing";

describe("createTypingNotifier", () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it("sends typing once per active window, not per keystroke", () => {
    const sent: boolean[] = [];
    const notifier = createTypingNotifier((isTyping) => sent.push(isTyping), {
      now: () => Date.now(),
    });

    notifier.input();
    notifier.input();
    vi.advanceTimersByTime(1000);
    notifier.input();

    expect(sent).toEqual([true]);
  });

  it("re-sends typing after the active window elapses under continued input", () => {
    const sent: boolean[] = [];
    const notifier = createTypingNotifier((isTyping) => sent.push(isTyping), {
      activeMs: 3000,
      idleMs: 2500,
      now: () => Date.now(),
    });

    notifier.input();
    vi.advanceTimersByTime(2000);
    notifier.input(); // keeps the idle timer alive, no re-send yet
    vi.advanceTimersByTime(1500);
    notifier.input(); // 3.5 s since first send → re-send

    expect(sent).toEqual([true, true]);
  });

  it("sends stopped after idle silence", () => {
    const sent: boolean[] = [];
    const notifier = createTypingNotifier((isTyping) => sent.push(isTyping), {
      idleMs: 2500,
      now: () => Date.now(),
    });

    notifier.input();
    vi.advanceTimersByTime(2500);

    expect(sent).toEqual([true, false]);
  });

  it("stop() sends stopped immediately and is idempotent", () => {
    const sent: boolean[] = [];
    const notifier = createTypingNotifier((isTyping) => sent.push(isTyping), {
      now: () => Date.now(),
    });

    notifier.input();
    notifier.stop();
    notifier.stop(); // nothing in flight — no duplicate false

    expect(sent).toEqual([true, false]);
    vi.runAllTimers();
    expect(sent).toEqual([true, false]);
  });

  it("does not send stopped when nothing was ever sent", () => {
    const sent: boolean[] = [];
    const notifier = createTypingNotifier((isTyping) => sent.push(isTyping));

    notifier.stop();
    expect(sent).toEqual([]);
  });
});
