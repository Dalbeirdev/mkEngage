import { describe, expect, it } from "vitest";

import { MessageStore } from "../src/store.js";
import type { ChatMessage } from "../src/types.js";

function msg(sequence: number, id = `id-${sequence}`, body = `m${sequence}`): ChatMessage {
  return {
    message_id: id,
    conversation_id: "c1",
    channel_id: null,
    sender_type: "visitor",
    sender_id: "v1",
    sequence_number: sequence,
    content_type: "text",
    body,
    lifecycle_state: "persisted",
    sent_at: "2026-07-23T00:00:00Z",
  };
}

describe("MessageStore", () => {
  it("renders in sequence order regardless of arrival order (RULES #12)", () => {
    const store = new MessageStore();
    store.ingestAll([msg(3), msg(1), msg(2)]);

    expect(store.messages.map((m) => m.sequence_number)).toEqual([1, 2, 3]);
  });

  it("dedupes by message id (RULES #9)", () => {
    const store = new MessageStore();
    store.ingest(msg(1, "same-id"));
    const addedAgain = store.ingest(msg(1, "same-id"));

    expect(addedAgain).toBe(false);
    expect(store.messages).toHaveLength(1);
  });

  it("tracks last seen sequence for replay-gap polling (RULES #11)", () => {
    const store = new MessageStore();
    store.ingestAll([msg(1), msg(5), msg(2)]);

    expect(store.lastSeenSequence).toBe(5);
  });

  it("confirms pending sends into ordered messages without duplicates", () => {
    const store = new MessageStore();
    store.addPending("key-1", "hello");
    expect(store.pendingMessages).toHaveLength(1);

    store.confirmPending("key-1", msg(1, "server-id", "hello"));

    expect(store.pendingMessages).toHaveLength(0);
    expect(store.messages).toHaveLength(1);

    // Poll may deliver the same message again — still exactly one copy.
    store.ingest(msg(1, "server-id", "hello"));
    expect(store.messages).toHaveLength(1);
  });

  it("keeps pending messages in send order", () => {
    const store = new MessageStore();
    store.addPending("a", "first");
    store.addPending("b", "second");

    expect(store.pendingMessages.map((p) => p.idempotency_key)).toEqual(["a", "b"]);
  });
});
