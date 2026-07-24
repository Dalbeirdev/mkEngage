"use client";

/**
 * Minimal gateway subscription for the agent console: fetch a socket token
 * via the BFF, open a Phoenix V1 WebSocket, join the conversation topic,
 * and invoke onEvent for message:new pushes. Best-effort — polling remains
 * the safety net; callers ignore a rejected connect.
 */

interface PhoenixFrame {
  topic: string;
  event: string;
  payload: unknown;
  ref: string | null;
}

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

export interface GatewaySubscription {
  close(): void;
  /** Ephemeral typing signal to the conversation topic (no-op while down). */
  sendTyping(isTyping: boolean): void;
}

export interface TypingEvent {
  sender_type: "visitor" | "contact" | "agent";
  sender_id: string;
  is_typing: boolean;
}

export interface ConversationHandlers {
  onMessage: () => void;
  onTyping?: (event: TypingEvent) => void;
  /** Current participants as subs ("visitor:{id}" / "user:{id}"). */
  onPresence?: (subs: string[]) => void;
}

/**
 * Reconnecting wrapper: the raw socket dies on gateway restarts/network
 * blips; this retries with capped backoff until close() is called.
 */
export function subscribeToConversation(
  conversationId: string,
  handlers: ConversationHandlers,
): GatewaySubscription {
  let closed = false;
  let current: GatewaySubscription | null = null;
  let attempts = 0;

  const connect = () => {
    if (closed) return;
    void openConversationSocket(conversationId, handlers, () => {
      // onDrop: presence is stale while down; schedule a reconnect.
      if (closed) return;
      current = null;
      handlers.onPresence?.([]);
      attempts += 1;
      setTimeout(connect, Math.min(1000 * 2 ** attempts, 30_000));
    })
      .then((sub) => {
        attempts = 0;
        if (closed) sub.close();
        else current = sub;
      })
      .catch(() => {
        if (closed) return;
        attempts += 1;
        setTimeout(connect, Math.min(1000 * 2 ** attempts, 30_000));
      });
  };

  connect();

  return {
    close() {
      closed = true;
      current?.close();
    },
    sendTyping(isTyping: boolean) {
      current?.sendTyping(isTyping);
    },
  };
}

type PresencePayload = Record<string, { metas?: unknown[] }>;

async function openConversationSocket(
  conversationId: string,
  handlers: ConversationHandlers,
  onDrop: () => void,
): Promise<GatewaySubscription> {
  const response = await fetch("/api/cp/gateway-token", { method: "POST" });
  if (!response.ok) throw new Error(`gateway token ${response.status}`);
  const { token, url } = (await response.json()) as { token: string; url: string };

  const org = orgFromGatewayToken(token);
  if (org === null) throw new Error("bad gateway token");

  const ws = new WebSocket(
    `${url.replace(/\/$/, "")}/websocket?token=${encodeURIComponent(token)}&vsn=1.0.0`,
  );
  const topic = `conv:${org}:${conversationId}`;
  let refCounter = 0;
  let heartbeat: ReturnType<typeof setInterval> | null = null;

  const send = (frameTopic: string, event: string, payload: unknown) => {
    if (ws.readyState === WebSocket.OPEN) {
      refCounter += 1;
      ws.send(
        JSON.stringify({ topic: frameTopic, event, payload, ref: String(refCounter) }),
      );
    }
  };

  await new Promise<void>((resolve, reject) => {
    ws.onopen = () => {
      send(topic, "phx_join", {});
      heartbeat = setInterval(() => send("phoenix", "heartbeat", {}), 30_000);
      resolve();
    };
    ws.onerror = () => reject(new Error("gateway socket error"));
  });

  // Simplified Phoenix Presence sync: sub → live meta count.
  const presence = new Map<string, number>();
  const emitPresence = () => handlers.onPresence?.([...presence.keys()]);

  ws.onmessage = (event) => {
    try {
      const frame = JSON.parse(String(event.data)) as PhoenixFrame;

      if (frame.event === "message:new") handlers.onMessage();

      if (frame.event === "typing") {
        handlers.onTyping?.(frame.payload as TypingEvent);
      }

      if (frame.event === "presence_state") {
        presence.clear();
        for (const [sub, entry] of Object.entries(frame.payload as PresencePayload)) {
          presence.set(sub, entry.metas?.length ?? 1);
        }
        emitPresence();
      }

      if (frame.event === "presence_diff") {
        const diff = frame.payload as { joins?: PresencePayload; leaves?: PresencePayload };
        for (const [sub, entry] of Object.entries(diff.joins ?? {})) {
          presence.set(sub, (presence.get(sub) ?? 0) + (entry.metas?.length ?? 1));
        }
        for (const [sub, entry] of Object.entries(diff.leaves ?? {})) {
          const remaining = (presence.get(sub) ?? 0) - (entry.metas?.length ?? 1);
          if (remaining <= 0) presence.delete(sub);
          else presence.set(sub, remaining);
        }
        emitPresence();
      }
    } catch {
      // ignore malformed frames
    }
  };

  let intentional = false;
  ws.onclose = () => {
    if (heartbeat !== null) clearInterval(heartbeat);
    if (!intentional) onDrop();
  };

  return {
    close() {
      intentional = true;
      if (heartbeat !== null) clearInterval(heartbeat);
      ws.onmessage = null;
      ws.close();
    },
    sendTyping(isTyping: boolean) {
      send(topic, "typing", { is_typing: isTyping });
    },
  };
}
