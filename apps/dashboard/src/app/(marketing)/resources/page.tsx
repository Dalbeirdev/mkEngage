import type { Metadata } from "next";
import Link from "next/link";

export const metadata: Metadata = { title: "Resources — mkEngage" };

const RESOURCES = [
  { kind: "Blog", t: "5 ways AI chatbots cut response time by 90%", d: "Practical playbooks from teams who automated their front line without losing the human touch.", cover: "linear-gradient(135deg,#6d3bf5,#a855f7)", link: "Read article" },
  { kind: "Guide", t: "The omnichannel support starter kit", d: "Connect WhatsApp, Telegram, Messenger and your website into one shared inbox — step by step.", cover: "linear-gradient(135deg,#3b7bf6,#8b3dff)", link: "Get the guide" },
  { kind: "Docs", t: "Developer documentation", d: "API keys, signed webhooks, the read API and the embeddable widget — everything to build on mkEngage.", cover: "linear-gradient(135deg,#8b3dff,#ec3f94)", link: "Open docs" },
  { kind: "Webinar", t: "Building your first AI flow", d: "A 30-minute walkthrough of the visual flow builder, from greeting to human handoff.", cover: "linear-gradient(135deg,#ec3f94,#f5a623)", link: "Watch now" },
  { kind: "Help Center", t: "Setting up business hours & CSAT", d: "Configure availability, offline notices and post-chat satisfaction ratings in minutes.", cover: "linear-gradient(135deg,#0ea5e9,#6d3bf5)", link: "Read article" },
  { kind: "Changelog", t: "What's new in mkEngage", d: "Reactions sync, outbound media, geo-location, the new dashboard — see everything we ship.", cover: "linear-gradient(135deg,#16a34a,#0ea5a3)", link: "See updates" },
];

export default function ResourcesPage() {
  return (
    <>
      <section className="hero soft-bg">
        <div className="wrap" style={{ textAlign: "center", maxWidth: "42em", margin: "0 auto" }}>
          <span className="eyebrow">Resources</span>
          <h1 style={{ marginTop: 18 }}>Guides, docs and <span className="grad-text">ideas to grow.</span></h1>
          <p className="sub" style={{ margin: "20px auto 0", maxWidth: "32em" }}>Everything you need to get the most out of mkEngage — from quick-start guides to deep developer docs.</p>
        </div>
      </section>

      <section className="section">
        <div className="wrap">
          <div className="res-grid">
            {RESOURCES.map((r) => (
              <article className="res" key={r.t}>
                <div className="cover" style={{ background: r.cover }}>
                  <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#fff" strokeWidth="1.5"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" /><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" /></svg>
                </div>
                <div className="rbody">
                  <span className="kind">{r.kind}</span>
                  <h4>{r.t}</h4>
                  <p>{r.d}</p>
                  <span className="rlink">{r.link} →</span>
                </div>
              </article>
            ))}
          </div>
        </div>
      </section>

      <section style={{ padding: "8px 0 80px" }}>
        <div className="wrap">
          <div className="cta-band">
            <div><h2>Can&apos;t find what you need?</h2><p>Our team is happy to help you get set up and answer any question.</p></div>
            <div className="acts"><Link className="btn btn-white" href="/contact">Contact Us</Link><Link className="btn btn-outline-white" href="/login">Start Free Trial →</Link></div>
          </div>
        </div>
      </section>
    </>
  );
}
