import type { Metadata } from "next";
import Link from "next/link";

export const metadata: Metadata = { title: "Features — mkEngage" };

const FEATURES = [
  { t: "AI Chatbot", d: "A no-code AI bot that understands intent, answers from your knowledge base, and hands off to humans when it matters.", bg: "#f0ebfe", c: "#8b3dff" },
  { t: "Live Chat", d: "Real-time conversations with typing indicators, presence, canned replies and quoted replies for fast, human support.", bg: "#e7f8ee", c: "#16a34a" },
  { t: "Omnichannel Inbox", d: "One shared inbox for Website, WhatsApp, Telegram, Messenger and Instagram — every conversation in a single place.", bg: "#fef1f6", c: "#ec3f94" },
  { t: "CRM & Contacts", d: "Every visitor becomes a contact with history, tags, lead score and the channel they came from.", bg: "#e8f1fe", c: "#3b7bf6" },
  { t: "Automation & Flows", d: "Visual flows that qualify, route and reply automatically — trigger campaigns on time-on-page or URL.", bg: "#fdeef0", c: "#e5484d" },
  { t: "Knowledge Base & RAG", d: "Train the bot on your docs; grounded answers cite your own content, never make things up.", bg: "#f0ebfe", c: "#8b3dff" },
  { t: "Insights & Analytics", d: "Response times, automation rate, CSAT, by-channel and by-agent breakdowns — decisions backed by data.", bg: "#fff2e6", c: "#f5a623" },
  { t: "Assignment & Routing", d: "Round-robin or least-busy routing with availability and per-agent caps, plus proactive agent-initiated chats.", bg: "#e8f1fe", c: "#3b7bf6" },
  { t: "Developer Platform", d: "Scoped API keys, signed webhooks and a read API to build mkEngage into the rest of your stack.", bg: "#e6faf7", c: "#0ea5a3" },
  { t: "Enterprise Security", d: "Tenant isolation enforced at the database (row-level security), encrypted credentials and full audit logs.", bg: "#fff2e6", c: "#f5a623" },
];

export default function FeaturesPage() {
  return (
    <>
      <section className="hero soft-bg">
        <div className="wrap" style={{ textAlign: "center", maxWidth: "44em", margin: "0 auto" }}>
          <span className="eyebrow">Features</span>
          <h1 style={{ marginTop: 18 }}>One platform to <span className="grad-text">engage, support and convert.</span></h1>
          <p className="sub" style={{ margin: "20px auto 0", maxWidth: "34em" }}>Everything your team needs to talk to customers across every channel — AI, live chat, automation, CRM and analytics, built to work together.</p>
          <div className="hero-actions" style={{ justifyContent: "center", marginTop: 26 }}>
            <Link className="btn btn-primary" href="/signup">Start 14-Day Free Trial →</Link>
            <Link className="btn btn-ghost" href="/pricing">View Pricing</Link>
          </div>
        </div>
      </section>

      <section className="section">
        <div className="wrap">
          <div className="feat-grid">
            {FEATURES.map((f) => (
              <div className="feat" key={f.t}>
                <div className="feat-ic" style={{ background: f.bg, color: f.c }}>
                  <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><rect x="4" y="6" width="16" height="12" rx="2" /><path d="M8 10h8M8 14h5" /></svg>
                </div>
                <h4>{f.t}</h4><p>{f.d}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      <section style={{ padding: "8px 0 44px" }}>
        <div className="wrap">
          <div className="band">
            <div className="stat-row">
              <div className="stat"><div className="n">10+</div><div className="l">Channels Supported</div></div>
              <div className="stat"><div className="n">100+</div><div className="l">Integrations</div></div>
              <div className="stat"><div className="n">92%</div><div className="l">Faster Response Time</div></div>
              <div className="stat"><div className="n">38%</div><div className="l">More Conversions</div></div>
              <div className="stat"><div className="n">24/7</div><div className="l">AI Availability</div></div>
            </div>
          </div>
        </div>
      </section>

      <section style={{ padding: "20px 0 80px" }}>
        <div className="wrap">
          <div className="cta-band">
            <div><h2>See every feature in action.</h2><p>Spin up a free workspace in two minutes — no credit card required.</p></div>
            <div className="acts"><Link className="btn btn-white" href="/signup">Start Free Trial</Link><Link className="btn btn-outline-white" href="/contact">Book a Demo →</Link></div>
          </div>
        </div>
      </section>
    </>
  );
}
