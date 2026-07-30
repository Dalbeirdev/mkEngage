import type { Metadata } from "next";
import Link from "next/link";

import { BrandMark } from "@/components/marketing/brand";

export const metadata: Metadata = { title: "About Us — mkEngage" };

const Check = () => (
  <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round"><path d="M20 6 9 17l-5-5" /></svg>
);

const TIMELINE = [
  { yr: "2022", t: "The Idea", d: "We saw how businesses struggled to respond to customers instantly.", bg: "linear-gradient(135deg,#8b5cf6,#7c3aed)" },
  { yr: "2023", t: "The Beginning", d: "Our small team built the first version of mkEngage from the ground up.", bg: "linear-gradient(135deg,#7c5cf6,#6d3bf5)" },
  { yr: "2023", t: "First Customers", d: "We onboarded our first customers and learned alongside them.", bg: "linear-gradient(135deg,#9b6cf0,#8b3dff)" },
  { yr: "2024", t: "Growing Together", d: "Crossed 1,000+ happy businesses and expanded our capabilities.", bg: "linear-gradient(135deg,#a85ce8,#9b3ff0)" },
  { yr: "2025 & Beyond", t: "Our Vision", d: "Empowering businesses worldwide with AI-driven customer engagement.", bg: "linear-gradient(135deg,#c14fc0,#ec3f94)" },
];

const TEAM = [
  { n: "David Anderson", r: "Co-founder & CEO", i: "DA", bg: "linear-gradient(140deg,#6d3bf5,#a855f7)" },
  { n: "Emily Carter", r: "Co-founder & COO", i: "EC", bg: "linear-gradient(140deg,#3b7bf6,#8b3dff)" },
  { n: "Michael Torres", r: "CTO", i: "MT", bg: "linear-gradient(140deg,#8b3dff,#ec3f94)" },
  { n: "Sarah Mitchell", r: "Head of Product", i: "SM", bg: "linear-gradient(140deg,#ec3f94,#f5a623)" },
  { n: "James Cooper", r: "Head of Growth", i: "JC", bg: "linear-gradient(140deg,#0ea5e9,#6d3bf5)" },
];

export default function AboutPage() {
  return (
    <>
      <section className="hero soft-bg">
        <div className="wrap hero-grid">
          <div>
            <span className="eyebrow">About Us</span>
            <h1>We built mkEngage to <span className="grad-text">help businesses never miss another customer.</span></h1>
            <p className="sub">Our mission is simple — empower every business with AI that talks, understands and helps them build better relationships, faster.</p>
            <div className="hero-actions">
              <Link className="btn btn-primary" href="/signup">▷ Start Free Trial</Link>
              <Link className="btn btn-ghost" href="/contact">📅 Book a Demo</Link>
            </div>
          </div>
          <div style={{ display: "grid", placeItems: "center", minHeight: 280 }}>
            <BrandMark size={220} />
          </div>
        </div>
      </section>

      <section className="section">
        <div className="wrap">
          <div className="sec-head"><span className="eyebrow">Our Story</span><h2>From a Simple Idea to a Global Platform</h2></div>
          <div className="timeline">
            {TIMELINE.map((s, i) => (
              <div className="tl" key={i}>
                <div className="tl-node" style={{ background: s.bg }}>
                  <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#fff" strokeWidth="1.8"><circle cx="12" cy="12" r="9" /><path d="M12 8v4l3 2" /></svg>
                </div>
                <div className="yr">{s.yr}</div><h4>{s.t}</h4><p>{s.d}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      <section className="section soft">
        <div className="wrap">
          <div className="mvv">
            <div>
              <div className="mvv-ic"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><circle cx="12" cy="12" r="9" /><circle cx="12" cy="12" r="5" /><circle cx="12" cy="12" r="1" /></svg></div>
              <h3>Our Mission</h3>
              <p>To help businesses build meaningful relationships with their customers through AI-powered conversations, automation and smart insights.</p>
            </div>
            <div>
              <div className="mvv-ic"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z" /><circle cx="12" cy="12" r="3" /></svg></div>
              <h3>Our Vision</h3>
              <p>A world where every business, no matter the size, has an AI employee working 24/7 to connect, support and grow their customers.</p>
            </div>
            <div>
              <div className="mvv-ic"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinejoin="round"><path d="M6 3h12l4 6-10 12L2 9z" /></svg></div>
              <h3>Our Values</h3>
              <ul className="vlist">
                {["Customer First", "Innovation in Everything", "Transparency & Trust", "Simplicity Matters", "Success Together"].map((v) => (
                  <li key={v}><Check />{v}</li>
                ))}
              </ul>
            </div>
          </div>
        </div>
      </section>

      <section style={{ padding: "14px 0 46px" }}>
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

      <section className="section">
        <div className="wrap">
          <div className="sec-head"><h2>Meet the People Behind mkEngage</h2><p>A passionate team of builders, dreamers and problem solvers.</p></div>
          <div className="team">
            {TEAM.map((m) => (
              <div className="member" key={m.n}>
                <div className="photo" style={{ background: m.bg }}>{m.i}</div>
                <div className="body"><b>{m.n}</b><small>{m.r}</small><div className="socials"><a href="#" aria-label="LinkedIn">in</a><a href="#" aria-label="X">✕</a></div></div>
              </div>
            ))}
          </div>
        </div>
      </section>

      <section style={{ padding: "8px 0 80px" }}>
        <div className="wrap">
          <div className="cta-band">
            <div className="mascot2"><BrandMark size={118} faceLight /></div>
            <div><h2>Let&apos;s build better customer relationships, together.</h2><p>Join thousands of businesses using mkEngage to engage, support and grow every day.</p></div>
            <div className="acts"><Link className="btn btn-white" href="/signup">Start 14-Day Free Trial</Link><Link className="btn btn-outline-white" href="/contact">Book a Demo →</Link></div>
          </div>
        </div>
      </section>
    </>
  );
}
