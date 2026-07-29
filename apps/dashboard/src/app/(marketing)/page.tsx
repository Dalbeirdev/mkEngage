import Link from "next/link";

import { BrandMark } from "@/components/marketing/brand";

const Check = () => (
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round"><path d="M20 6 9 17l-5-5" /></svg>
);
const Arrow = () => (
  <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
);

const FEATURES = [
  { t: "AI Chatbot", d: "Smart AI that understands and talks like a human.", bg: "#f0ebfe", c: "#8b3dff" },
  { t: "Live Chat", d: "Chat in real-time with your customers.", bg: "#e7f8ee", c: "#16a34a" },
  { t: "Omnichannel", d: "Connect across 10+ popular channels.", bg: "#fef1f6", c: "#ec3f94" },
  { t: "CRM", d: "Manage contacts, leads and conversations.", bg: "#e8f1fe", c: "#3b7bf6" },
  { t: "Automation", d: "Automate repetitive tasks and save time.", bg: "#fdeef0", c: "#e5484d" },
  { t: "Knowledge Base", d: "Train your bot with your content and docs.", bg: "#f0ebfe", c: "#8b3dff" },
  { t: "Analytics", d: "Track performance and make data-driven decisions.", bg: "#fff2e6", c: "#f5a623" },
  { t: "Team Inbox", d: "Collaborate with your team in a shared inbox.", bg: "#e8f1fe", c: "#3b7bf6" },
  { t: "Integrations", d: "Connect with 100+ tools you already use.", bg: "#e6faf7", c: "#0ea5a3" },
  { t: "Security", d: "Enterprise-grade security and data protection.", bg: "#fff2e6", c: "#f5a623" },
];

const INTEGRATIONS = [
  ["WhatsApp", "#25d366"], ["Facebook Messenger", "#0084ff"], ["Instagram", "#e1306c"],
  ["Telegram", "#29b6f6"], ["Slack", "#4a154b"], ["HubSpot", "#ff7a59"],
  ["Salesforce", "#00a1e0"], ["Google Calendar", "#4285f4"], ["Zapier", "#ff4a00"],
];

const QUOTES = [
  { q: "mkEngage has transformed the way we support our customers. Our response time dropped by 90%!", n: "Anita Patel", r: "Co-founder, BrightMart", c: "#6d3bf5", i: "AP" },
  { q: "The AI chatbot is super smart and easy to set up. We saw a 35% increase in leads within the first month.", n: "Rohit Verma", r: "CEO, TechNova", c: "#3b7bf6", i: "RV" },
  { q: "Finally, a platform that brings everything together — chat, automation, and CRM. Amazing support team too!", n: "Sarah Johnson", r: "Marketing Head, ShopEase", c: "#ec3f94", i: "SJ" },
];

export default function HomePage() {
  return (
    <>
      {/* HERO */}
      <section className="hero soft-bg">
        <div className="wrap hero-grid">
          <div>
            <span className="eyebrow">AI-Powered Customer Engagement Platform</span>
            <h1>AI Chatbots.<br />Happy Customers.<br /><span className="grad-text">More Sales.</span></h1>
            <p className="sub">mkEngage helps you engage, support and convert customers across every channel with AI chatbots, live chat, and smart automation.</p>
            <div className="hero-actions">
              <Link className="btn btn-primary" href="/signup">Start 14-Day Free Trial →</Link>
              <Link className="btn btn-ghost" href="/contact">▷ Book a Demo</Link>
            </div>
            <div className="ticks">
              <span className="tick"><Check /> No credit card required</span>
              <span className="tick"><Check /> Setup in 2 minutes</span>
              <span className="tick"><Check /> Cancel anytime</span>
            </div>
            <div className="trust">
              <div className="avatars">
                <span style={{ background: "#6d3bf5" }}>A</span><span style={{ background: "#3b7bf6" }}>R</span><span style={{ background: "#ec3f94" }}>S</span><span style={{ background: "#0ea5e9" }}>J</span>
              </div>
              <div><div className="stars">★★★★★</div><small>Trusted by 5,000+ businesses worldwide</small></div>
            </div>
          </div>

          <div className="mock">
            <div className="mock-card">
              <div className="mock-top">
                <div className="mock-rail">
                  <div className="rlogo" />
                  <div className="mock-chan on"><span className="lab"><i style={{ background: "#eef" }} />All</span><span className="n">23</span></div>
                  <div className="mock-chan"><span className="lab"><i style={{ background: "#6d3bf5" }} />Live Chat</span><span className="n">8</span></div>
                  <div className="mock-chan"><span className="lab"><i style={{ background: "#25d366" }} />WhatsApp</span><span className="n">6</span></div>
                  <div className="mock-chan"><span className="lab"><i style={{ background: "#0084ff" }} />Messenger</span><span className="n">4</span></div>
                  <div className="mock-chan"><span className="lab"><i style={{ background: "#e1306c" }} />Instagram</span><span className="n">2</span></div>
                  <div className="mock-chan"><span className="lab"><i style={{ background: "#29b6f6" }} />Telegram</span><span className="n">1</span></div>
                </div>
                <div className="mock-conv">
                  <div className="head">Conversations <span className="pill">Open · 12</span></div>
                  <div className="bubble in">Hi! I need help with my order.</div>
                  <div className="bubble bot">Hello! 👋 I can help you with that. Can you share your order ID?</div>
                  <div className="bubble out">Sure, it&apos;s #ACME2567</div>
                  <div className="bubble bot">Thank you! Let me check that for you…</div>
                  <div className="bubble in" style={{ opacity: 0.6 }}>● ● ●</div>
                </div>
                <div className="mock-info">
                  <div className="who"><span />Rahul Sharma</div>
                  <span className="ret">Returning visitor</span>
                  <dl>
                    <div><dt>Location</dt><dd>Bangalore, India</dd></div>
                    <div><dt>Device</dt><dd>Windows · Chrome</dd></div>
                    <div><dt>Time on site</dt><dd>4m 32s</dd></div>
                  </dl>
                </div>
              </div>
            </div>
            <svg className="mascot" viewBox="-6 -18 86 88" aria-hidden="true"><path d="M14 4h44a14 14 0 0 1 14 14v28a14 14 0 0 1-14 14H37l-11 11v-11H14A14 14 0 0 1 0 46V18A14 14 0 0 1 14 4z" fill="#fff" stroke="url(#mkg)" strokeWidth="5" strokeLinejoin="round" /><line x1="36" y1="-6" x2="36" y2="5" stroke="url(#mkg)" strokeWidth="4" strokeLinecap="round" /><circle cx="36" cy="-10" r="5" fill="url(#mkg)" /><rect x="16" y="13" width="40" height="30" rx="13" fill="#141a2e" /><circle cx="28" cy="27" r="5.5" fill="#fff" /><circle cx="44" cy="27" r="5.5" fill="#fff" /><circle cx="29" cy="28" r="2.6" fill="#141a2e" /><circle cx="45" cy="28" r="2.6" fill="#141a2e" /><ellipse cx="21" cy="34" rx="2.4" ry="1.5" fill="#ec3f94" opacity="0.5" /><ellipse cx="51" cy="34" rx="2.4" ry="1.5" fill="#ec3f94" opacity="0.5" /><path d="M31 35q5 6 10 0z" fill="#ec3f94" /></svg>
          </div>
        </div>
      </section>

      {/* LOGOS */}
      <section className="logos">
        <div className="wrap">
          <p className="lead">Trusted by amazing companies</p>
          <div className="logo-row">
            {["AcmeCorp", "Brainwave", "TechNova", "Sparkle", "AlphaTech", "Idealab", "NextGen"].map((n) => (
              <span key={n}><b />{n}</span>
            ))}
          </div>
        </div>
      </section>

      {/* PROBLEM */}
      <section className="section">
        <div className="wrap">
          <div className="sec-head"><h2>Are you losing customers while they wait?</h2><p>Every unanswered question is a lost opportunity.</p></div>
          <div className="flow">
            <div className="flow-step"><div className="flow-ic"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><circle cx="12" cy="8" r="4" /><path d="M4 20a8 8 0 0 1 16 0" /></svg></div><h4>Visitor has a question</h4></div>
            <div className="flow-arrow"><Arrow /></div>
            <div className="flow-step"><div className="flow-ic"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg></div><h4>No immediate response</h4></div>
            <div className="flow-arrow"><Arrow /></div>
            <div className="flow-step"><div className="flow-ic"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><path d="M9 21V3h11v18M9 12H3M6 9l-3 3 3 3" /></svg></div><h4>Visitor leaves your site</h4></div>
            <div className="flow-arrow"><Arrow /></div>
            <div className="flow-step"><div className="flow-ic"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><path d="M3 9h18l-1.5 11H4.5zM3 9l2-5h14l2 5" /></svg></div><h4>They buy from competitor</h4></div>
            <div className="flow-arrow"><Arrow /></div>
            <div className="flow-step bad"><div className="flow-ic"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d="M22 17 13.5 8.5l-5 5L2 7" /><path d="M16 17h6v-6" /></svg></div><h4>You lose revenue</h4></div>
          </div>
        </div>
      </section>

      {/* SOLUTION */}
      <section className="section soft">
        <div className="wrap">
          <div className="sec-head"><h2>How <span className="grad-text">mkEngage</span> Solves It</h2><p>mkEngage works 24/7 to engage, support and convert your visitors.</p></div>
          <div className="steps">
            <div className="step"><div className="step-ic"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><rect x="4" y="8" width="16" height="12" rx="3" /><path d="M12 8V4" /><circle cx="12" cy="3" r="1" /><path d="M9 14h.01M15 14h.01" /></svg></div><h4>AI engages instantly</h4><p>Answers questions in real-time</p></div>
            <div className="step"><div className="step-ic"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z" /></svg></div><h4>Captures leads</h4><p>Collects visitor info automatically</p></div>
            <div className="step"><div className="step-ic"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><circle cx="12" cy="12" r="3" /><path d="M12 2v4M12 18v4M2 12h4M18 12h4M5 5l2.5 2.5M16.5 16.5 19 19M19 5l-2.5 2.5M7.5 16.5 5 19" /></svg></div><h4>Qualifies &amp; routes</h4><p>AI qualifies and routes to the right agent</p></div>
            <div className="step"><div className="step-ic"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" /></svg></div><h4>Agent takes over</h4><p>Seamless handoff to human agents</p></div>
            <div className="step"><div className="step-ic"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d="M22 7 13.5 15.5l-5-5L2 17" /><path d="M16 7h6v6" /></svg></div><h4>Happy customers</h4><p>More sales and loyal customers</p></div>
          </div>
        </div>
      </section>

      {/* STATS */}
      <section style={{ padding: "8px 0 40px" }}>
        <div className="wrap">
          <div className="band">
            <div className="stat-row">
              <div className="stat"><div className="n">5,000+</div><div className="l">Businesses Trust Us</div></div>
              <div className="stat"><div className="n">2M+</div><div className="l">Conversations Handled</div></div>
              <div className="stat"><div className="n">92%</div><div className="l">Faster Response Time</div></div>
              <div className="stat"><div className="n">38%</div><div className="l">Increase in Conversions</div></div>
              <div className="stat"><div className="n">24/7</div><div className="l">AI Availability</div></div>
            </div>
          </div>
        </div>
      </section>

      {/* FEATURES */}
      <section className="section" id="features">
        <div className="wrap">
          <div className="sec-head"><h2>Everything You Need. All in One Place.</h2></div>
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

      {/* INTEGRATIONS */}
      <section className="section soft">
        <div className="wrap">
          <div className="sec-head"><h2>Seamlessly Integrates With Your Favorite Tools</h2></div>
          <div className="chips">
            {INTEGRATIONS.map(([name, color]) => (
              <span className="chip" key={name}><i style={{ background: color }} />{name}</span>
            ))}
            <span className="chip" style={{ color: "#8b3dff", fontWeight: 700 }}>+ Many More</span>
          </div>
        </div>
      </section>

      {/* TESTIMONIALS */}
      <section className="section">
        <div className="wrap">
          <div className="sec-head"><h2>Loved by Businesses of All Sizes</h2></div>
          <div className="quotes">
            {QUOTES.map((q) => (
              <div className="quote" key={q.n}>
                <div className="stars">★★★★★</div>
                <blockquote>&ldquo;{q.q}&rdquo;</blockquote>
                <div className="by"><span style={{ background: q.c }}>{q.i}</span><div><b>{q.n}</b><small>{q.r}</small></div></div>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* CTA */}
      <section style={{ padding: "20px 0 80px" }}>
        <div className="wrap">
          <div className="cta-band">
            <div className="mascot2"><BrandMark size={120} faceLight /></div>
            <div>
              <h2>Ready to Delight Your Customers?</h2>
              <p>Join thousands of businesses using mkEngage to build better customer relationships.</p>
            </div>
            <div className="acts">
              <Link className="btn btn-white" href="/signup">Start 14-Day Free Trial</Link>
              <Link className="btn btn-outline-white" href="/contact">Book a Demo →</Link>
            </div>
          </div>
        </div>
      </section>
    </>
  );
}
