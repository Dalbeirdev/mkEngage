/**
 * Throttled typing signal for a compose box: "typing" is sent at most once
 * per activeMs while input keeps arriving; "stopped" fires after idleMs of
 * silence or an explicit stop() (message sent / focus lost).
 */

export interface TypingNotifier {
  input(): void;
  stop(): void;
}

export interface TypingNotifierOptions {
  activeMs?: number;
  idleMs?: number;
  now?: () => number;
}

export function createTypingNotifier(
  send: (isTyping: boolean) => void,
  options: TypingNotifierOptions = {},
): TypingNotifier {
  const activeMs = options.activeMs ?? 3000;
  const idleMs = options.idleMs ?? 2500;
  const now = options.now ?? Date.now;

  let lastSentAt = 0;
  let idleTimer: ReturnType<typeof setTimeout> | null = null;

  const stop = () => {
    if (idleTimer !== null) {
      clearTimeout(idleTimer);
      idleTimer = null;
    }
    if (lastSentAt !== 0) {
      send(false);
      lastSentAt = 0;
    }
  };

  return {
    input() {
      const at = now();
      if (at - lastSentAt > activeMs) {
        send(true);
        lastSentAt = at;
      }
      if (idleTimer !== null) clearTimeout(idleTimer);
      idleTimer = setTimeout(stop, idleMs);
    },
    stop,
  };
}
