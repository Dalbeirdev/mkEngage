import type { Metadata } from "next";
import Link from "next/link";

export const metadata: Metadata = { title: "Pricing — mkEngage" };

const Check = () => (
  <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round"><path d="M20 6 9 17l-5-5" /></svg>
);

const PLANS = [
  {
    name: "Starter", desc: "For small teams getting started.", price: "$0", per: "/month", popular: false,
    features: ["1 website widget", "Up to 2 agents", "AI chatbot (fair-use)", "Email support", "7-day analytics"],
    cta: "Start free",
  },
  {
    name: "Growth", desc: "For growing support & sales teams.", price: "$49", per: "/month", popular: true,
    features: ["Everything in Starter", "WhatsApp, Telegram & Messenger", "Up to 10 agents", "Automation & flows", "Knowledge base + RAG", "CSAT & full analytics"],
    cta: "Start 14-day trial",
  },
  {
    name: "Enterprise", desc: "For scale, security & control.", price: "Custom", per: "", popular: false,
    features: ["Everything in Growth", "Unlimited agents", "SSO & audit logs", "API + signed webhooks", "Priority 24/7 support", "Dedicated success manager"],
    cta: "Talk to sales",
  },
];

const FAQS = [
  { q: "Is there a free plan?", a: "Yes — Starter is free forever for small teams, no credit card required." },
  { q: "Can I change plans later?", a: "Anytime. Upgrades apply instantly and downgrades take effect at the next billing cycle." },
  { q: "Do you charge per conversation?", a: "No. Plans are priced per workspace and agent seats — conversations are not metered on paid plans." },
];

export default function PricingPage() {
  return (
    <>
      <section className="hero soft-bg">
        <div className="wrap" style={{ textAlign: "center", maxWidth: "42em", margin: "0 auto" }}>
          <span className="eyebrow">Pricing</span>
          <h1 style={{ marginTop: 18 }}>Simple, <span className="grad-text">transparent pricing.</span></h1>
          <p className="sub" style={{ margin: "20px auto 0", maxWidth: "32em" }}>Start free, upgrade when you grow. No hidden fees, cancel anytime.</p>
        </div>
      </section>

      <section className="section" style={{ paddingTop: 40 }}>
        <div className="wrap">
          <div className="prices">
            {PLANS.map((p) => (
              <div className={`price${p.popular ? " pop" : ""}`} key={p.name}>
                {p.popular && <span className="tag">Most Popular</span>}
                <h3>{p.name}</h3>
                <p className="desc2">{p.desc}</p>
                <div className="amt"><b>{p.price}</b><span>{p.per}</span></div>
                <ul>
                  {p.features.map((f) => (<li key={f}><Check />{f}</li>))}
                </ul>
                <Link className={`btn ${p.popular ? "btn-primary" : "btn-ghost"}`} href={p.name === "Enterprise" ? "/contact" : "/login"} style={{ justifyContent: "center" }}>{p.cta}</Link>
              </div>
            ))}
          </div>
        </div>
      </section>

      <section className="section soft">
        <div className="wrap" style={{ maxWidth: "46em" }}>
          <div className="sec-head"><h2>Pricing FAQ</h2></div>
          <div className="card faq">
            {FAQS.map((f, i) => (
              <details key={i} open={i === 0}>
                <summary>{f.q} <span className="cg">⌄</span></summary>
                <p>{f.a}</p>
              </details>
            ))}
          </div>
        </div>
      </section>

      <section style={{ padding: "20px 0 80px" }}>
        <div className="wrap">
          <div className="cta-band">
            <div><h2>Ready to get started?</h2><p>Try mkEngage free for 14 days — no credit card required.</p></div>
            <div className="acts"><Link className="btn btn-white" href="/login">Start Free Trial</Link><Link className="btn btn-outline-white" href="/contact">Talk to Sales →</Link></div>
          </div>
        </div>
      </section>
    </>
  );
}
