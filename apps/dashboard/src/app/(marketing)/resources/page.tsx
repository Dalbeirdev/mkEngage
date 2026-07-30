import type { Metadata } from "next";
import Link from "next/link";

import { ARTICLES } from "./articles";

export const metadata: Metadata = { title: "Resources — mkEngage" };

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
            {ARTICLES.map((r) => (
              <Link className="res" key={r.slug} href={`/resources/${r.slug}`} style={{ textDecoration: "none", color: "inherit", display: "block" }}>
                <div className="cover" style={{ background: r.cover }}>
                  <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#fff" strokeWidth="1.5"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" /><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" /></svg>
                </div>
                <div className="rbody">
                  <span className="kind">{r.kind}</span>
                  <h4>{r.t}</h4>
                  <p>{r.d}</p>
                  <span className="rlink">{r.link} →</span>
                </div>
              </Link>
            ))}
          </div>
        </div>
      </section>

      <section style={{ padding: "8px 0 80px" }}>
        <div className="wrap">
          <div className="cta-band">
            <div><h2>Can&apos;t find what you need?</h2><p>Our team is happy to help you get set up and answer any question.</p></div>
            <div className="acts"><Link className="btn btn-white" href="/contact">Contact Us</Link><Link className="btn btn-outline-white" href="/signup">Start Free Trial →</Link></div>
          </div>
        </div>
      </section>
    </>
  );
}
