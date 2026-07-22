import type { ChatMessage, PendingMessage } from "./types.js";

/**
 * Client-side message state (RULES-message-ordering):
 *  - render order is ALWAYS sequence order, never arrival order (#12)
 *  - dedup by message_id (#9)
 *  - optimistic sends are "pending" until the durable ack arrives; retries
 *    reuse the same idempotency key (#8) so duplicates are impossible
 */
export class MessageStore {
  private readonly bySequence = new Map<number, ChatMessage>();

  private readonly seenIds = new Set<string>();

  private readonly pending = new Map<string, PendingMessage>();

  lastSeenSequence = 0;

  /** @returns true if the message was new (not a duplicate). */
  ingest(message: ChatMessage): boolean {
    if (this.seenIds.has(message.message_id)) {
      return false;
    }

    this.seenIds.add(message.message_id);
    this.bySequence.set(message.sequence_number, message);

    if (message.sequence_number > this.lastSeenSequence) {
      this.lastSeenSequence = message.sequence_number;
    }

    return true;
  }

  ingestAll(messages: readonly ChatMessage[]): number {
    let added = 0;
    for (const message of messages) {
      if (this.ingest(message)) added += 1;
    }
    return added;
  }

  addPending(idempotencyKey: string, body: string): PendingMessage {
    const entry: PendingMessage = {
      idempotency_key: idempotencyKey,
      body,
      created_at: Date.now(),
    };
    this.pending.set(idempotencyKey, entry);
    return entry;
  }

  /** Durable ack arrived: pending → confirmed (§27 lifecycle on the client). */
  confirmPending(idempotencyKey: string, message: ChatMessage): void {
    this.pending.delete(idempotencyKey);
    this.ingest(message);
  }

  get pendingMessages(): PendingMessage[] {
    return [...this.pending.values()].sort((a, b) => a.created_at - b.created_at);
  }

  /** Confirmed messages in sequence order. */
  get messages(): ChatMessage[] {
    return [...this.bySequence.entries()]
      .sort(([a], [b]) => a - b)
      .map(([, message]) => message);
  }
}
