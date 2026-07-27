import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page } from "@playwright/test";

/**
 * Deterministic API mock: an in-memory conversation store served via
 * page.route. Sequence/idempotency semantics mirror the control plane
 * (RULES-message-ordering) so the widget is tested against contract
 * behavior, not implementation coincidence.
 */
interface MockMessage {
  message_id: string;
  conversation_id: string;
  channel_id: null;
  sender_type: string;
  sender_id: string;
  sequence_number: number;
  content_type: string;
  body: string;
  lifecycle_state: string;
  sent_at: string;
}

interface MockState {
  messages: MockMessage[];
  byIdempotency: Map<string, MockMessage>;
  sessionCalls: number;
  identifyCalls: Array<Record<string, unknown>>;
  failSends: boolean;
  failLists: boolean;
  /** Phase 23 knobs: session config + conversation status + captured posts. */
  prechat: { enabled: boolean; require_email: boolean } | null;
  open: boolean;
  conversationStatus: "open" | "pending" | "closed";
  profileCalls: Array<Record<string, unknown>>;
  ratingCalls: Array<Record<string, unknown>>;
  /** Phase 24 knobs: proactive triggers + heartbeat-driven adoption. */
  triggers: Array<Record<string, unknown>>;
  adoptConversation: string | null;
  heartbeatCalls: Array<Record<string, unknown>>;
}

function makeMessage(state: MockState, senderType: string, body: string): MockMessage {
  const message: MockMessage = {
    message_id: crypto.randomUUID(),
    conversation_id: "c-1",
    channel_id: null,
    sender_type: senderType,
    sender_id: senderType === "visitor" ? "v-1" : "a-1",
    sequence_number: state.messages.length + 1,
    content_type: "text",
    body,
    lifecycle_state: "persisted",
    sent_at: new Date().toISOString(),
  };
  state.messages.push(message);
  return message;
}

async function mockApi(page: Page): Promise<MockState> {
  const state: MockState = {
    messages: [],
    byIdempotency: new Map(),
    sessionCalls: 0,
    identifyCalls: [],
    failSends: false,
    failLists: false,
    prechat: null,
    open: true,
    conversationStatus: "open",
    profileCalls: [],
    ratingCalls: [],
    triggers: [],
    adoptConversation: null,
    heartbeatCalls: [],
  };

  await page.route("http://127.0.0.1:8000/**", async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const json = (status: number, body: unknown) =>
      route.fulfill({
        status,
        contentType: "application/json",
        headers: { "Access-Control-Allow-Origin": "*", "Access-Control-Allow-Headers": "*" },
        body: JSON.stringify(body),
      });

    if (request.method() === "OPTIONS") {
      return route.fulfill({
        status: 204,
        headers: {
          "Access-Control-Allow-Origin": "*",
          "Access-Control-Allow-Headers": "*",
          "Access-Control-Allow-Methods": "*",
        },
      });
    }

    if (url.pathname === "/api/widget/session") {
      state.sessionCalls += 1;
      return json(201, {
        visitor_id: "v-1",
        token: "1|mock-token",
        ...(state.prechat !== null ? { prechat: state.prechat } : {}),
        open: state.open,
        triggers: state.triggers,
      });
    }

    if (url.pathname === "/api/widget/heartbeat") {
      state.heartbeatCalls.push((request.postDataJSON() ?? {}) as Record<string, unknown>);
      return json(200, { conversation_id: state.adoptConversation, last_sequence: 0 });
    }

    if (url.pathname === "/api/widget/profile") {
      state.profileCalls.push(request.postDataJSON() as Record<string, unknown>);
      return json(200, { display_name: "Lead", contact_id: "ct-2" });
    }

    if (url.pathname.endsWith("/rating") && request.method() === "POST") {
      state.ratingCalls.push(request.postDataJSON() as Record<string, unknown>);
      return json(201, { conversation_id: "c-1", csat_rating: 5 });
    }

    if (url.pathname === "/api/widget/identify") {
      state.identifyCalls.push(request.postDataJSON() as Record<string, unknown>);
      return json(200, { contact_id: "ct-1", display_name: "Mock Person" });
    }

    if (url.pathname === "/api/widget/conversations" && request.method() === "POST") {
      return json(201, { conversation_id: "c-1", status: "open", last_sequence: 0 });
    }

    if (url.pathname.endsWith("/messages") && request.method() === "GET") {
      if (state.failLists) return json(503, { title: "down" });
      const after = Number(url.searchParams.get("after_sequence") ?? "0");
      return json(200, {
        data: state.messages.filter((m) => m.sequence_number > after),
        last_sequence: state.messages.length,
        status: state.conversationStatus,
      });
    }

    if (url.pathname.endsWith("/messages") && request.method() === "POST") {
      if (state.failSends) return json(503, { title: "down" });
      const payload = request.postDataJSON() as { idempotency_key: string; body: string };
      const existing = state.byIdempotency.get(payload.idempotency_key);
      if (existing !== undefined) return json(200, existing);
      const message = makeMessage(state, "visitor", payload.body);
      state.byIdempotency.set(payload.idempotency_key, message);
      return json(201, message);
    }

    return json(404, { title: "not found" });
  });

  return state;
}

const widget = (page: Page) => page.locator("mkengage-widget");
const launcher = (page: Page) => widget(page).locator(".launcher");
const panel = (page: Page) => widget(page).locator(".panel");
const textarea = (page: Page) => widget(page).locator("textarea");

async function openWidget(page: Page): Promise<void> {
  await page.goto("/demo/index.html?site_key=sk_mock");
  await launcher(page).click();
  await expect(panel(page)).toBeVisible();
}

test.describe("widget on a hostile host page", () => {
  test("mounts and resists hostile page CSS (§4 Shadow DOM isolation)", async ({ page }) => {
    await mockApi(page);
    await page.goto("/demo/index.html?site_key=sk_mock");

    await expect(launcher(page)).toBeVisible();

    const styles = await launcher(page).evaluate((el) => {
      const computed = getComputedStyle(el);
      return { font: computed.fontFamily, background: computed.backgroundColor };
    });

    expect(styles.font).not.toContain("Comic Sans");
    expect(styles.background).toBe("rgb(79, 70, 229)"); // widget accent, not the page's red !important
  });

  test("opens, bootstraps a session, sends and confirms a message", async ({ page }) => {
    const state = await mockApi(page);
    await openWidget(page);

    await expect(textarea(page)).toBeFocused();
    await textarea(page).fill("Hello from e2e");
    await textarea(page).press("Enter");

    const bubble = widget(page).locator(".msg.visitor");
    await expect(bubble).toHaveCount(1);
    await expect(bubble).toContainText("Hello from e2e");
    await expect(widget(page).locator(".msg.pending")).toHaveCount(0); // durable ack received

    expect(state.sessionCalls).toBe(1);
    expect(state.messages).toHaveLength(1);
  });

  test("renders agent replies arriving via polling, in sequence order", async ({ page }) => {
    const state = await mockApi(page);
    await openWidget(page);

    await textarea(page).fill("Question?");
    await textarea(page).press("Enter");
    // Wait for the DURABLE ack (pending gone), not just the optimistic
    // bubble — otherwise the mock could assign the agent message sequence 1
    // before the visitor's POST lands (a race this test once had).
    await expect(widget(page).locator(".msg")).toHaveCount(1);
    await expect(widget(page).locator(".msg.pending")).toHaveCount(0);
    await expect.poll(() => state.messages.length).toBe(1);

    makeMessage(state, "agent", "Answer!");

    const messages = widget(page).locator(".msg");
    await expect(messages).toHaveCount(2, { timeout: 10_000 }); // poll interval is 3s
    await expect(messages.nth(1)).toHaveClass(/remote/);
    await expect(messages.nth(1)).toContainText("Answer!");
  });

  test("shows reconnecting state on failures and recovers (§4)", async ({ page }) => {
    const state = await mockApi(page);
    await openWidget(page);

    await textarea(page).fill("first");
    await textarea(page).press("Enter");
    await expect(widget(page).locator(".msg")).toHaveCount(1);

    state.failLists = true;
    await expect(widget(page).locator(".status")).toContainText("Reconnecting", {
      timeout: 10_000,
    });

    state.failLists = false;
    // The header status line is always present now (it shows the subtitle when
    // connected); recovery means it no longer reads "Reconnecting".
    await expect(widget(page).locator(".status")).not.toContainText("Reconnecting", {
      timeout: 15_000,
    });
  });

  test("keeps failed sends visible as pending (retry-safe optimistic UI)", async ({ page }) => {
    const state = await mockApi(page);
    await openWidget(page);

    state.failSends = true;
    await textarea(page).fill("will fail");
    await textarea(page).press("Enter");

    await expect(widget(page).locator(".msg.pending")).toHaveCount(1);
    await expect(widget(page).locator(".msg.pending")).toContainText("will fail");
  });

  test("sends the signed identity payload once (§4 verified identity)", async ({ page }) => {
    const state = await mockApi(page);
    const signature = "a".repeat(64);
    await page.goto(
      `/demo/index.html?site_key=sk_mock&external_id=cust-9&sig=${signature}&name=E2E%20Person`,
    );
    await launcher(page).click();
    await expect(panel(page)).toBeVisible();

    await expect
      .poll(() => state.identifyCalls.length, { timeout: 5_000 })
      .toBe(1);
    expect(state.identifyCalls[0]).toMatchObject({
      external_id: "cust-9",
      signature,
      name: "E2E Person",
    });
  });

  test("closes on Escape and returns focus flow to the launcher", async ({ page }) => {
    await mockApi(page);
    await openWidget(page);

    await textarea(page).press("Escape");
    await expect(panel(page)).toHaveCount(0);
    await expect(launcher(page)).toBeVisible();
  });

  test("open panel has no serious accessibility violations (Axe, §4)", async ({ page }) => {
    await mockApi(page);
    await openWidget(page);

    await textarea(page).fill("a11y check");
    await textarea(page).press("Enter");
    await expect(widget(page).locator(".msg")).toHaveCount(1);
    // Wait for the durable ack so Axe never scans a still-pending bubble
    // (a pending bubble is a legitimate UI state, but its contrast is now
    //  covered by the "no serious violations" guarantee regardless).
    await expect(widget(page).locator(".msg.pending")).toHaveCount(0);

    // Scope to the widget (axe pierces its shadow tree). The demo is a
    // DELIBERATELY hostile host page — scanning the whole document would
    // audit that fixture's intentional low-contrast decoration, not the
    // widget. This test asserts the WIDGET panel's a11y (§4).
    const results = await new AxeBuilder({ page })
      .include("mkengage-widget")
      .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
      .analyze();

    const serious = results.violations.filter((v) =>
      ["serious", "critical"].includes(v.impact ?? ""),
    );
    expect(serious, serious.map((v) => v.id).join(", ")).toEqual([]);
  });

  test("emoji picker: opens, searches, and inserts into the composer (§12)", async ({ page }) => {
    await mockApi(page);
    await openWidget(page);

    const picker = widget(page).locator(".emoji-picker");
    await expect(picker).toBeHidden();

    // Open via the 😊 button (first .attach button).
    await widget(page).locator(".attach").first().click();
    await expect(picker).toBeVisible();

    // Search narrows the grid; picking inserts the emoji into the textarea.
    await widget(page).locator(".emoji-search").fill("thumbs up");
    const firstHit = widget(page).locator(".emoji-cell").first();
    await expect(firstHit).toContainText("👍");
    await firstHit.click();
    await expect(textarea(page)).toHaveValue("👍");

    // Skin tone applies to skin-tone-able emoji: reopen, choose a tone, insert.
    await widget(page).locator(".emoji-search").fill("wave");
    await widget(page).locator(".skin-swatch").nth(3).click();
    await widget(page).locator(".emoji-cell").first().click();
    // Draft now contains the wave with a skin-tone modifier appended.
    const value = await textarea(page).inputValue();
    expect(value).toContain("👋");
    expect(value).toContain("\u{1F3FD}"); // medium skin tone modifier
  });

  test("shows the configured greeting, quick replies, avatar, and branding", async ({ page }) => {
    await mockApi(page);
    await openWidget(page);

    // Header: avatar + title + subtitle.
    await expect(widget(page).locator(".avatar")).toBeVisible();
    await expect(widget(page).locator("header h2")).toHaveText("Acme Support");
    await expect(widget(page).locator("header .status")).toContainText(/replies/i);

    // Greeting bubble (with a bot avatar beside it) + welcome quick replies.
    await expect(widget(page).locator(".row.remote .msg").first()).toContainText("Welcome to Acme");
    const chips = widget(page).locator(".quick-reply");
    await expect(chips).toHaveCount(4);

    // Circular send button carries a paper-plane icon.
    await expect(widget(page).locator(".send svg")).toBeVisible();
    // Branding footer.
    await expect(widget(page).locator(".branding")).toContainText("mkEngage");
  });

  test("clicking a quick reply sends it and hides the welcome chips", async ({ page }) => {
    await mockApi(page);
    await openWidget(page);

    await widget(page).locator(".quick-reply", { hasText: "Shipping info" }).click();

    // It becomes the visitor's message; the welcome chips disappear.
    await expect(widget(page).locator(".msg.visitor").first()).toContainText("Shipping info");
    await expect(widget(page).locator(".quick-reply")).toHaveCount(0);
  });

  test("emoji picker: recently-used row remembers picks across reopen (§12)", async ({ page }) => {
    await mockApi(page);
    await openWidget(page);

    await widget(page).locator(".attach").first().click();
    await widget(page).locator(".emoji-search").fill("tada");
    await widget(page).locator(".emoji-cell").first().click(); // 🎉

    // Reopen with an empty search — the recently-used section shows the pick.
    await widget(page).locator(".attach").first().click(); // close
    await widget(page).locator(".attach").first().click(); // reopen
    const recentLabel = widget(page).locator(".emoji-section-label").first();
    await expect(recentLabel).toHaveText(/Recently used/i);
    await expect(widget(page).locator(".emoji-grid").first().locator(".emoji-cell").first()).toContainText(
      "🎉",
    );
  });

  test("proactive trigger auto-opens the panel with its message, once (Phase 24)", async ({ page }) => {
    const state = await mockApi(page);
    state.triggers = [
      { id: "t-welcome", enabled: true, type: "time_on_page", seconds: 0, message: "Need any help deciding?" },
    ];

    // No launcher click: the trigger opens the panel by itself.
    await page.goto("/demo/index.html?site_key=sk_mock");
    await expect(panel(page)).toBeVisible({ timeout: 5000 });
    await expect(widget(page).locator(".msg.remote").last()).toContainText("Need any help deciding?");

    // Fire-once: a reload must NOT auto-open again (persisted fired-set).
    await page.goto("/demo/index.html?site_key=sk_mock");
    await page.waitForTimeout(1200);
    await expect(panel(page)).toHaveCount(0);
  });

  test("heartbeat tracks presence and adopts an agent-initiated conversation (Phase 24)", async ({ page }) => {
    const state = await mockApi(page);
    state.adoptConversation = "c-agent-1";
    makeMessage(state, "agent", "Hi! I noticed you browsing — can I help?");

    // No interaction at all: load → session → heartbeat → adoption → panel opens.
    await page.goto("/demo/index.html?site_key=sk_mock");
    await expect(panel(page)).toBeVisible({ timeout: 5000 });
    await expect(widget(page).locator(".msg.remote").last()).toContainText("noticed you browsing", {
      timeout: 10_000,
    });
    expect(state.heartbeatCalls.length).toBeGreaterThanOrEqual(1);
  });

  test("dark theme applies the premium palette and passes Axe", async ({ page }) => {
    await mockApi(page);
    await page.goto("/demo/index.html?site_key=sk_mock&theme=dark");
    await launcher(page).click();
    await expect(panel(page)).toBeVisible();

    // Palette + reference structure: chevron, sender label, split greeting
    // with in-bubble timestamps, right-aligned filled chips.
    await expect(widget(page)).toHaveAttribute("data-theme", "dark");
    await expect(panel(page)).toHaveCSS("background-color", "rgb(12, 12, 14)");
    await expect(widget(page).locator("header")).toHaveCSS("background-color", "rgb(39, 39, 42)");
    await expect(widget(page).locator(".close")).toHaveText("‹");
    await expect(widget(page).locator(".sender-label").first()).toHaveText("TechPIO");
    await expect(widget(page).locator(".msg.remote")).toHaveCount(2);
    await expect(widget(page).locator(".msg.remote .time").first()).not.toBeEmpty();
    const chip = widget(page).locator(".quick-reply").first();
    await expect(chip).toHaveCSS("background-color", "rgb(212, 212, 216)");
    await expect(widget(page).locator(".quick-replies")).toHaveCSS("justify-content", "flex-end");
    await expect(widget(page).locator("textarea")).toHaveAttribute(
      "placeholder",
      "We are here to help you",
    );

    const results = await new AxeBuilder({ page })
      .include("mkengage-widget")
      .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
      .analyze();
    expect(
      results.violations.filter((v) => v.impact === "serious" || v.impact === "critical"),
    ).toEqual([]);
  });

  test("pre-chat form gates the chat and posts the lead profile (Phase 23)", async ({ page }) => {
    const state = await mockApi(page);
    state.prechat = { enabled: true, require_email: true };
    await openWidget(page);

    // Form visible, composer hidden, quick replies suppressed.
    const prechat = widget(page).locator(".prechat");
    await expect(prechat).toBeVisible();
    await expect(widget(page).locator("form.composer-hidden")).toHaveCount(1);
    await expect(widget(page).locator(".quick-reply")).toHaveCount(0);

    // Start stays disabled until both required fields are filled.
    const start = widget(page).locator(".prechat-start");
    await expect(start).toBeDisabled();
    await prechat.locator("input[name=name]").fill("Ada Lovelace");
    await expect(start).toBeDisabled();
    await prechat.locator("input[name=email]").fill("ada@example.com");
    await expect(start).toBeEnabled();
    await start.click();

    // Chat unlocks; the profile was posted with the lead data.
    await expect(widget(page).locator(".prechat")).toHaveCount(0);
    await expect(widget(page).locator("form.composer-hidden")).toHaveCount(0);
    await expect.poll(() => state.profileCalls.length).toBe(1);
    expect(state.profileCalls[0]).toMatchObject({ name: "Ada Lovelace", email: "ada@example.com" });
  });

  test("shows the offline notice when outside business hours (Phase 23)", async ({ page }) => {
    const state = await mockApi(page);
    state.open = false;
    await openWidget(page);

    await expect(widget(page).locator(".offline-hours")).toContainText("currently away");
    // Chat still works while away — offline capture, not a hard gate.
    await textarea(page).fill("Please call me back");
    await textarea(page).press("Enter");
    await expect(widget(page).locator(".msg.visitor")).toContainText("Please call me back");
  });

  test("CSAT stars appear on close and submit the rating (Phase 23)", async ({ page }) => {
    const state = await mockApi(page);
    await openWidget(page);

    await textarea(page).fill("Hi!");
    await textarea(page).press("Enter");
    await expect(widget(page).locator(".msg.visitor")).toContainText("Hi!");

    // Agent closes the conversation; the next poll delivers the status.
    state.conversationStatus = "closed";

    const csat = widget(page).locator(".csat");
    await expect(csat).toBeVisible({ timeout: 10_000 }); // poll interval is 3s
    await expect(widget(page).locator("form.composer-hidden")).toHaveCount(1);

    await csat.locator(".star").nth(3).click(); // 4 stars
    await csat.locator(".csat-comment").fill("Great help!");
    await csat.locator(".csat-submit").click();

    await expect(csat.locator(".csat-thanks")).toContainText("Thanks");
    await expect.poll(() => state.ratingCalls.length).toBe(1);
    expect(state.ratingCalls[0]).toMatchObject({ rating: 4, comment: "Great help!" });
  });
});
