/**
 * IndexedDB persistence (§4): survives reloads and temporary network loss so
 * the conversation restores. Holds the visitor token (a visitor-scoped,
 * revocable credential — deliberately NOT a secret API key, which the widget
 * must never hold), the active conversation, and the last seen sequence for
 * replay-gap polling (RULES-message-ordering #11).
 */

const DB_NAME = "mkengage-widget";
const STORE = "session";
const VERSION = 1;

export interface StoredSession {
  visitorId: string;
  token: string;
  conversationId: string | null;
  lastSeenSequence: number;
  /** external_id already linked to this visitor (skip repeat identify calls). */
  identifiedExternalId?: string | null;
  /** Pre-chat profile already submitted (never re-ask, Phase 23). */
  profiled?: boolean;
}

function openDb(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, VERSION);
    request.onupgradeneeded = () => {
      if (!request.result.objectStoreNames.contains(STORE)) {
        request.result.createObjectStore(STORE);
      }
    };
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error ?? new Error("IndexedDB open failed"));
  });
}

async function withStore<T>(
  mode: IDBTransactionMode,
  fn: (store: IDBObjectStore) => IDBRequest<T>,
): Promise<T> {
  const db = await openDb();
  try {
    return await new Promise<T>((resolve, reject) => {
      const tx = db.transaction(STORE, mode);
      const request = fn(tx.objectStore(STORE));
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error ?? new Error("IndexedDB request failed"));
    });
  } finally {
    db.close();
  }
}

export class SessionStorage {
  constructor(private readonly siteKey: string) {}

  private get key(): string {
    return `session:${this.siteKey}`;
  }

  async load(): Promise<StoredSession | null> {
    try {
      const value = await withStore("readonly", (store) => store.get(this.key));
      return (value as StoredSession | undefined) ?? null;
    } catch {
      return null; // Private-mode/blocked IndexedDB degrades to per-page sessions.
    }
  }

  async save(session: StoredSession): Promise<void> {
    try {
      await withStore("readwrite", (store) => store.put(session, this.key));
    } catch {
      // Non-fatal by design.
    }
  }

  async clear(): Promise<void> {
    try {
      await withStore("readwrite", (store) => store.delete(this.key));
    } catch {
      // Non-fatal.
    }
  }
}
