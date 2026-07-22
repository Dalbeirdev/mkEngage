import { describe, expect, it } from "vitest";

import { SessionStorage } from "../src/storage.js";

describe("SessionStorage (IndexedDB)", () => {
  it("round-trips a session and clears it", async () => {
    const storage = new SessionStorage("sk_test");

    expect(await storage.load()).toBeNull();

    await storage.save({
      visitorId: "v1",
      token: "1|abc",
      conversationId: null,
      lastSeenSequence: 0,
    });

    const restored = await storage.load();
    expect(restored?.visitorId).toBe("v1");
    expect(restored?.token).toBe("1|abc");

    await storage.clear();
    expect(await storage.load()).toBeNull();
  });

  it("isolates sessions per site key", async () => {
    const a = new SessionStorage("sk_a");
    const b = new SessionStorage("sk_b");

    await a.save({ visitorId: "va", token: "ta", conversationId: null, lastSeenSequence: 0 });

    expect(await b.load()).toBeNull();
    expect((await a.load())?.visitorId).toBe("va");
  });
});
