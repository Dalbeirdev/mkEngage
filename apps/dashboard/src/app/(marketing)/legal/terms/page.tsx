import type { Metadata } from "next";

export const metadata: Metadata = { title: "Terms & Conditions — mkEngage" };

export default function TermsPage() {
  return (
    <section className="section">
      <div className="wrap prose">
        <h1 style={{ fontSize: 34, marginBottom: 6 }}>Terms &amp; Conditions</h1>
        <p className="updated">Last updated: July 29, 2026</p>

        <p>These Terms govern your access to and use of the mkEngage website and platform. By creating an account or using the service you agree to these Terms.</p>

        <h2>Your account</h2>
        <p>You are responsible for the activity under your organization&apos;s account and for keeping credentials secure. You must be authorized to act on behalf of the business you register.</p>

        <h2>Acceptable use</h2>
        <ul>
          <li>Do not use mkEngage to send spam or unlawful, harmful or misleading content.</li>
          <li>Do not attempt to breach tenant isolation, reverse-engineer the service, or disrupt its operation.</li>
          <li>Comply with the terms of any messaging channel you connect (WhatsApp, Messenger, Telegram, etc.).</li>
        </ul>

        <h2>Your data</h2>
        <p>You retain ownership of the content and customer data you bring to mkEngage. You grant us the limited rights needed to operate the service on your behalf. Our handling of data is described in the <a href="/legal/privacy" style={{ color: "var(--cta)", fontWeight: 600 }}>Privacy Policy</a>.</p>

        <h2>Plans &amp; billing</h2>
        <p>Paid plans renew automatically until cancelled. Upgrades apply immediately; downgrades take effect at the next billing cycle. Fees are non-refundable except where required by law.</p>

        <h2>Availability &amp; changes</h2>
        <p>We work to keep the service reliable but provide it &ldquo;as is&rdquo; without warranties. We may update the service and these Terms; material changes will be communicated in advance.</p>

        <h2>Contact</h2>
        <p>Questions about these Terms? Email <a href="mailto:legal@mkengage.com" style={{ color: "var(--cta)", fontWeight: 600 }}>legal@mkengage.com</a>.</p>
      </div>
    </section>
  );
}
