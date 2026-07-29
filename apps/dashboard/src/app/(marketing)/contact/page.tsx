"use client";

import { useActionState } from "react";

import { submitContact, type LeadState } from "../lead-actions";

const METHODS = [
  { t: "Sales Inquiries", d: "Want to learn more about mkEngage? Talk to our sales team.", m: "sales@mkengage.com", bg: "#eef0ff", c: "#4f66d6" },
  { t: "Support", d: "Need help with your account? We're here for you 24/7.", m: "support@mkengage.com", bg: "#e7f8ee", c: "#16a34a" },
  { t: "Partnerships", d: "Explore partnerships and integration opportunities.", m: "partners@mkengage.com", bg: "#fff2e6", c: "#f5a623" },
  { t: "General Inquiries", d: "For all other questions and general information.", m: "info@mkengage.com", bg: "#f0ebfe", c: "#8b3dff" },
];

const FAQS = [
  { q: "How quickly will I get a response?", a: "Our team typically replies within a few hours during business days, and always within 24 hours." },
  { q: "Can I schedule a personalized demo?", a: "Absolutely — pick “Book a Demo” and choose a time that suits you. A specialist walks you through mkEngage on your own data." },
  { q: "Do you offer 24/7 customer support?", a: "Yes. Support is available around the clock via chat and email for all paid plans." },
];

export default function ContactPage() {
  const [state, formAction, pending] = useActionState<LeadState, FormData>(submitContact, {
    status: "idle",
  });

  return (
    <>
      <section className="hero soft-bg">
        <div className="wrap hero-grid">
          <div>
            <span className="eyebrow">Contact Us</span>
            <h1>We&apos;re here to help <span className="grad-text">you succeed.</span></h1>
            <p className="sub">Have a question, need support, or want to explore how mkEngage can help your business grow? Our team is just a message away.</p>
            <div className="badges">
              <div className="badge"><span className="bi"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9"><circle cx="12" cy="12" r="9" /><path d="M12 8v4l3 2" /></svg></span><div><b>Quick Response</b><small>Within 24 hours</small></div></div>
              <div className="badge"><span className="bi"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /></svg></span><div><b>Expert Support</b><small>Real humans</small></div></div>
              <div className="badge"><span className="bi"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1a5.5 5.5 0 1 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8z" /></svg></span><div><b>Customer First</b><small>We care for you</small></div></div>
            </div>
          </div>
          <div style={{ display: "grid", placeItems: "center", minHeight: 260 }}>
            <div style={{ maxWidth: 260, background: "#fff", border: "1px solid var(--line)", borderRadius: 16, boxShadow: "var(--shadow)", padding: 18 }}>
              <div style={{ fontWeight: 700, color: "var(--ink)", fontSize: 14 }}>We&apos;re here to help! 👋</div>
              <p style={{ fontSize: 13, color: "var(--muted)", marginTop: 4 }}>Typically replies within a few hours.</p>
              <span style={{ display: "inline-flex", alignItems: "center", gap: 6, marginTop: 8, fontSize: 12, color: "#16a34a", fontWeight: 600 }}><i style={{ width: 7, height: 7, borderRadius: "50%", background: "#22c55e", display: "inline-block" }} />Online</span>
            </div>
          </div>
        </div>
      </section>

      <section className="section">
        <div className="wrap cols3">
          <div className="card">
            <h2>Get in touch</h2>
            <p className="lede">Choose the best way to reach us</p>
            {METHODS.map((m) => (
              <div className="method" key={m.t}>
                <span className="mi" style={{ background: m.bg, color: m.c }}><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><rect x="2" y="4" width="20" height="16" rx="2" /><path d="m2 7 10 6 10-6" /></svg></span>
                <div><b>{m.t}</b><p>{m.d}</p><a className="mail" href={`mailto:${m.m}`}>{m.m}</a></div>
                <span className="chev">›</span>
              </div>
            ))}
          </div>

          <div className="card form">
            <h2>Send us a message</h2>
            <p className="lede">Fill out the form and our team will get back to you.</p>
            {state.status === "ok" ? (
              <div role="status" style={{ padding: "24px 4px" }}>
                <div style={{ fontSize: 32 }} aria-hidden>✅</div>
                <b style={{ display: "block", marginTop: 8, color: "var(--ink)" }}>Message sent</b>
                <p style={{ fontSize: 14, color: "var(--muted)", marginTop: 4 }}>{state.message}</p>
              </div>
            ) : (
              <form action={formAction}>
                {state.status === "error" && (
                  <div role="alert" style={{ background: "#fef2f2", color: "#b91c1c", border: "1px solid #fecaca", borderRadius: 10, padding: "10px 12px", fontSize: 13.5, marginBottom: 12 }}>
                    {state.message}
                  </div>
                )}
                <div className="field"><label htmlFor="fn">Full Name</label><input id="fn" name="name" required placeholder="Enter your full name" /></div>
                <div className="field"><label htmlFor="em">Work Email</label><input id="em" name="email" type="email" required placeholder="Enter your work email" /></div>
                <div className="field"><label htmlFor="co">Company Name</label><input id="co" name="company" placeholder="Enter your company name" /></div>
                <div className="field"><label htmlFor="su">Subject</label><select id="su" name="subject" defaultValue=""><option value="">What is this regarding?</option><option>Sales</option><option>Support</option><option>Partnership</option><option>Other</option></select></div>
                <div className="field"><label htmlFor="ms">Message</label><textarea id="ms" name="message" required placeholder="Tell us more about your question or requirement…" /></div>
                <button className="btn btn-primary" type="submit" disabled={pending}>{pending ? "Sending…" : "Send Message"}</button>
                <div className="privacy">🔒 Your information is safe with us. We respect your privacy.</div>
              </form>
            )}
          </div>

          <div className="card office">
            <h2>Our Office</h2>
            <p className="lede">We&apos;d love to meet you.</p>
            <div className="row"><span className="oi"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z" /><circle cx="12" cy="10" r="3" /></svg></span><div><b>mkEngage Technologies Pvt. Ltd.</b><p>4th Floor, Global Business Park,<br />Sector 62, Noida,<br />Uttar Pradesh 201309, India</p></div></div>
            <div className="row"><span className="oi"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3-8.6A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.4 2.5 1.2 4 .7 4.9L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3c.9-.5 2.4.3 4.9.7a2 2 0 0 1 1.7 2z" /></svg></span><div><b>Phone</b><p>+91 120 456 7890</p></div></div>
            <div className="row"><span className="oi"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><rect x="2" y="4" width="20" height="16" rx="2" /><path d="m2 7 10 6 10-6" /></svg></span><div><b>Email</b><p>hello@mkengage.com</p></div></div>
            <div className="row"><span className="oi"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg></span><div><b>Working Hours</b><p>Mon - Fri: 9:00 AM - 6:00 PM (IST)<br />Sat - Sun: Closed</p></div></div>
          </div>
        </div>
      </section>

      <section style={{ padding: "8px 0 70px" }}>
        <div className="wrap cols2">
          <div className="card">
            <h2>Frequently Asked Questions</h2>
            <p className="lede">Quick answers to common questions</p>
            <div className="faq">
              {FAQS.map((f, i) => (
                <details key={i} open={i === 0}>
                  <summary>{f.q} <span className="cg">⌄</span></summary>
                  <p>{f.a}</p>
                </details>
              ))}
            </div>
          </div>
          <div className="card" style={{ background: "linear-gradient(135deg,#faf8ff,#f3effe)", borderColor: "#e9e2fb" }}>
            <h2>Let&apos;s build something great together</h2>
            <p style={{ fontSize: 15, color: "var(--slate)", marginTop: 8 }}>Join thousands of businesses that trust mkEngage to engage, support and grow.</p>
            <div className="avatars" style={{ margin: "16px 0 0" }}>
              <span style={{ background: "#6d3bf5" }}>R</span><span style={{ background: "#3b7bf6" }}>A</span><span style={{ background: "#ec3f94" }}>V</span><span style={{ background: "#0ea5e9" }}>P</span><span style={{ background: "#16a34a" }}>J</span>
              <span style={{ background: "#eadcff", color: "#6d3bf5", fontSize: 12 }}>5K+</span>
            </div>
            <div style={{ display: "flex", alignItems: "center", gap: 8, marginTop: 12, fontSize: 13.5, fontWeight: 600, color: "var(--ink)" }}>😊 Happy Customers Worldwide</div>
            <div style={{ marginTop: 20, display: "flex", alignItems: "flex-end", gap: 12, height: 110 }} aria-hidden="true">
              <div style={{ flex: 1, height: "34%", borderRadius: "8px 8px 0 0", background: "var(--band)", opacity: 0.35 }} />
              <div style={{ flex: 1, height: "52%", borderRadius: "8px 8px 0 0", background: "var(--band)", opacity: 0.55 }} />
              <div style={{ flex: 1, height: "74%", borderRadius: "8px 8px 0 0", background: "var(--band)", opacity: 0.75 }} />
              <div style={{ flex: 1, height: "100%", borderRadius: "8px 8px 0 0", background: "var(--band)" }} />
            </div>
          </div>
        </div>
      </section>
    </>
  );
}
