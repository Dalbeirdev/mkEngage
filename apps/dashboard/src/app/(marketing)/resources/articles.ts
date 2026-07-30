/**
 * Content for /resources/[slug]. Kept as data so the index cards and the
 * article pages stay in sync from a single list.
 */

export type ArticleSection = {
  h: string;
  ps?: string[];
  bullets?: string[];
};

export type Article = {
  slug: string;
  kind: string;
  t: string;
  d: string;
  cover: string;
  link: string;
  minutes: number;
  sections: ArticleSection[];
};

export const ARTICLES: Article[] = [
  {
    slug: "ai-chatbots-cut-response-time",
    kind: "Blog",
    t: "5 ways AI chatbots cut response time by 90%",
    d: "Practical playbooks from teams who automated their front line without losing the human touch.",
    cover: "linear-gradient(135deg,#6d3bf5,#a855f7)",
    link: "Read article",
    minutes: 6,
    sections: [
      {
        h: "1. Answer the repetitive 60% instantly",
        ps: [
          "In most support inboxes, more than half of incoming questions are variations of the same twenty topics: order status, pricing, refunds, password resets, business hours. A chatbot trained on your knowledge base answers these in under a second, around the clock — no queue, no \"we'll get back to you.\"",
          "Start by exporting your most common questions from past conversations, add them to the mkEngage knowledge base, and let the AI assistant handle first contact. Teams typically see first-response time drop from minutes to seconds on day one.",
        ],
      },
      {
        h: "2. Collect context before a human ever joins",
        ps: [
          "The slowest part of most support conversations isn't the answer — it's the back-and-forth to understand the question. Use a pre-chat form or an AI-led intake flow to capture the customer's name, email, order ID, and issue category up front.",
          "When the conversation does reach an agent, they open a thread that already has everything they need. No \"can you share your order number?\" round trips.",
        ],
      },
      {
        h: "3. Route to the right team automatically",
        ps: [
          "A question that bounces between departments is a question answered late. Assignment rules in mkEngage route conversations by channel, keyword, or department the moment they arrive — billing questions to billing, technical issues to engineering-facing agents.",
        ],
      },
      {
        h: "4. Give agents AI-suggested replies",
        ps: [
          "For the questions that do need a human, agents shouldn't type every answer from scratch. The AI assistant drafts a suggested reply from the conversation context and your knowledge base; the agent reviews, edits, and sends. Response drafting drops from minutes to seconds, and tone stays consistent.",
        ],
      },
      {
        h: "5. Measure it — then tighten the loop",
        ps: [
          "You can't improve what you don't measure. The Insights dashboard tracks first-response time, resolution time, automation rate, and CSAT per channel and per agent. Set an SLA target per priority, watch the breach indicator in the inbox, and review the trend weekly.",
          "Teams that follow this loop — automate the repetitive, route smartly, assist agents, measure — routinely report first-response time reductions of 90% within the first month.",
        ],
      },
    ],
  },
  {
    slug: "omnichannel-starter-kit",
    kind: "Guide",
    t: "The omnichannel support starter kit",
    d: "Connect WhatsApp, Telegram, Messenger and your website into one shared inbox — step by step.",
    cover: "linear-gradient(135deg,#3b7bf6,#8b3dff)",
    link: "Get the guide",
    minutes: 8,
    sections: [
      {
        h: "Why one inbox beats five apps",
        ps: [
          "Your customers already message you everywhere — your website, WhatsApp, Telegram, Facebook Messenger, Instagram, email. The problem isn't the channels; it's answering them in five different apps with five different histories. mkEngage brings every channel into one shared inbox with one customer record.",
        ],
      },
      {
        h: "Step 1 — Live chat on your website",
        ps: [
          "Go to Settings → Channels and your web widget is already there. Copy the embed snippet into your site's HTML, pick a design and accent color under Settings → Appearance, and you're live. Add a pre-chat form if you want name and email before the conversation starts.",
        ],
      },
      {
        h: "Step 2 — WhatsApp",
        ps: [
          "Create a WhatsApp Business app in Meta's developer console, then add a WhatsApp channel in Settings → Channels. Paste your access token and phone-number ID, and point Meta's webhook at the URL mkEngage shows you. Inbound messages, replies, and media all flow through the shared inbox.",
        ],
      },
      {
        h: "Step 3 — Telegram",
        ps: [
          "Create a bot with @BotFather, copy the token into a new Telegram channel in mkEngage, and the webhook is registered for you automatically. Buttons, media, and reactions are supported out of the box.",
        ],
      },
      {
        h: "Step 4 — Messenger & Instagram",
        ps: [
          "Both ride Meta's Messenger Platform: connect your Facebook Page (or Instagram professional account), add the channel with its page token, and set the webhook. DMs land in the same inbox as everything else, with the customer's profile name attached.",
        ],
      },
      {
        h: "Step 5 — Email",
        ps: [
          "Add an email channel to receive inbound mail through a forwarding webhook, and reply straight from the inbox — with proper subject threading. You can use your own SMTP server per channel for outbound delivery.",
        ],
      },
      {
        h: "What you get on day one",
        bullets: [
          "One inbox with channel badges, filters, and saved views",
          "One contact record per customer across all channels",
          "The same AI chatbot and flows on every channel",
          "Insights broken down by channel, so you know where your customers actually are",
        ],
      },
    ],
  },
  {
    slug: "developer-documentation",
    kind: "Docs",
    t: "Developer documentation",
    d: "API keys, signed webhooks, the read API and the embeddable widget — everything to build on mkEngage.",
    cover: "linear-gradient(135deg,#8b3dff,#ec3f94)",
    link: "Open docs",
    minutes: 5,
    sections: [
      {
        h: "API keys",
        ps: [
          "Create scoped API keys under Settings → Developers. Keys are shown once at creation and stored hashed — treat them like passwords. Send the key as a Bearer token in the Authorization header on every request.",
        ],
      },
      {
        h: "Read API",
        ps: [
          "The machine API exposes your organization's conversations, messages, and contacts as JSON, scoped to the key's organization. Use it to sync conversations into your data warehouse, build custom dashboards, or feed your own tooling.",
        ],
      },
      {
        h: "Signed webhooks",
        ps: [
          "Register webhook endpoints under Settings → Developers and subscribe to events: message.created, conversation.created, conversation.assigned, conversation.closed, and csat.received.",
          "Every delivery is signed with an HMAC-SHA256 signature header computed over the raw body with your endpoint secret. Verify the signature before trusting a payload, and respond with a 2xx quickly — retries with backoff cover transient failures.",
        ],
      },
      {
        h: "Embeddable widget",
        ps: [
          "The chat widget is a self-contained script tag pointing at your mkEngage API host. It inherits your appearance settings (design preset, accent color, logo), supports pre-chat forms, file attachments, reactions, and real-time delivery over WebSockets, and works on any site that can host a script tag.",
        ],
      },
      {
        h: "Good to know",
        bullets: [
          "All API traffic is tenant-isolated at the database layer (row-level security)",
          "Rate limits protect the API; design integrations to tolerate 429s with backoff",
          "Webhook redelivery is at-least-once — make your handlers idempotent",
        ],
      },
    ],
  },
  {
    slug: "building-your-first-ai-flow",
    kind: "Webinar",
    t: "Building your first AI flow",
    d: "A 30-minute walkthrough of the visual flow builder, from greeting to human handoff.",
    cover: "linear-gradient(135deg,#ec3f94,#f5a623)",
    link: "Watch now",
    minutes: 7,
    sections: [
      {
        h: "What a flow is",
        ps: [
          "A flow is the scripted part of your chatbot: the greeting, the menu of options, the guided paths that answer common questions before the AI or a human takes over. You build it visually — drag nodes onto a canvas, connect them, publish.",
        ],
      },
      {
        h: "Part 1 — The greeting",
        ps: [
          "Open Chatbots, pick your bot, and open the Flow tab. Start with a message node: a short, friendly greeting that sets expectations (\"Hi! I can help with orders, billing, or anything else.\"). Keep it to two sentences.",
        ],
      },
      {
        h: "Part 2 — Options that match your inbox",
        ps: [
          "Add an options node with the three or four things customers actually ask for — look at your inbox history, not your org chart. Each option connects to its own branch: a direct answer, a follow-up question, or a handoff.",
        ],
      },
      {
        h: "Part 3 — Let the AI handle the long tail",
        ps: [
          "Add an AI node for the \"something else\" path. The assistant answers from your knowledge base, keeps full conversation context, and stays inside the boundaries you set.",
        ],
      },
      {
        h: "Part 4 — The human handoff",
        ps: [
          "Every flow needs an exit to a person. Add a handoff node that assigns the conversation to a department; combine it with business hours so after-hours visitors see an offline notice and leave their email instead of waiting.",
        ],
      },
      {
        h: "Before you publish",
        bullets: [
          "Test the whole flow yourself from the widget preview",
          "Keep menus to 4 options or fewer — deeper trees lose people",
          "Review conversations weekly and move repeated AI answers into the flow",
        ],
      },
    ],
  },
  {
    slug: "business-hours-and-csat",
    kind: "Help Center",
    t: "Setting up business hours & CSAT",
    d: "Configure availability, offline notices and post-chat satisfaction ratings in minutes.",
    cover: "linear-gradient(135deg,#0ea5e9,#6d3bf5)",
    link: "Read article",
    minutes: 4,
    sections: [
      {
        h: "Business hours",
        ps: [
          "Under Settings → Business Hours, set the days and times your team is online, in your local time zone. Outside those hours the widget shows your offline notice instead of promising an instant reply — and your chatbot keeps answering what it can.",
        ],
      },
      {
        h: "Offline notices",
        ps: [
          "Write an offline message that tells visitors when to expect a reply and captures their email so you can follow up. A good pattern: \"We're offline right now — leave your email and we'll reply first thing tomorrow morning.\"",
        ],
      },
      {
        h: "CSAT ratings",
        ps: [
          "With CSAT enabled, visitors are asked to rate the conversation when it closes. Ratings appear on the conversation, feed the CSAT trend in Insights, and can trigger a webhook (csat.received) if you want them in your own tools.",
        ],
      },
      {
        h: "Reading the results",
        bullets: [
          "Insights shows CSAT per agent and over time — look for trends, not single scores",
          "Pair CSAT with first-response time: slow answers are the most common driver of low ratings",
          "Follow up on every low rating while the conversation is still fresh",
        ],
      },
    ],
  },
  {
    slug: "changelog",
    kind: "Changelog",
    t: "What's new in mkEngage",
    d: "Reactions sync, outbound media, geo-location, the new dashboard — see everything we ship.",
    cover: "linear-gradient(135deg,#16a34a,#0ea5a3)",
    link: "See updates",
    minutes: 3,
    sections: [
      {
        h: "Channels",
        bullets: [
          "Instagram DMs join web, WhatsApp, Telegram, Messenger, and email",
          "Email channel with subject threading and per-organization SMTP",
          "Outbound media (images and files) to Telegram, WhatsApp, and Messenger",
          "Inbound reaction sync from Telegram",
        ],
      },
      {
        h: "Inbox & ticketing",
        bullets: [
          "Conversation priority, spam handling, and saved views",
          "SLA targets per priority with breach indicators in the inbox",
          "Agent-to-agent transfer with a handoff note",
          "Search, channel filters, unread badges, and per-agent read state",
        ],
      },
      {
        h: "CRM & automation",
        bullets: [
          "Manual contacts, agent notes, and CSV import/export",
          "Assignment rules v2 with agent availability",
          "Canned responses, tags, and lead scoring",
          "Visual AI flow builder with templates",
        ],
      },
      {
        h: "Platform",
        bullets: [
          "Redesigned analytics dashboard (Insights v2)",
          "Two-factor authentication and content moderation (including CIDR IP bans)",
          "Scoped API keys, read API, and signed webhooks (5 events)",
          "Slack notifications for new conversations",
        ],
      },
    ],
  },
];

export function findArticle(slug: string): Article | undefined {
  return ARTICLES.find((a) => a.slug === slug);
}
