import type { Metadata } from "next";

export const metadata: Metadata = { title: "Privacy Policy — mkEngage" };

export default function PrivacyPage() {
  return (
    <section className="section">
      <div className="wrap prose">
        <h1 style={{ fontSize: 34, marginBottom: 6 }}>Privacy Policy</h1>
        <p className="updated">Last updated: July 29, 2026</p>

        <p>This Privacy Policy explains how mkEngage Technologies Pvt. Ltd. (&ldquo;mkEngage&rdquo;, &ldquo;we&rdquo;) collects, uses and protects information when you use our website and platform. We designed mkEngage to be private by default — tenant data is isolated at the database level and credentials are stored encrypted.</p>

        <h2>Information we collect</h2>
        <ul>
          <li><strong>Account data</strong> — your name, work email and organization when you sign up.</li>
          <li><strong>Conversation data</strong> — messages, contacts and attachments you and your customers exchange through the platform.</li>
          <li><strong>Coarse location</strong> — an approximate city/country derived from your edge provider&apos;s headers to help agents. We do not store visitor IP addresses.</li>
          <li><strong>Usage data</strong> — logs and metrics needed to operate and secure the service.</li>
        </ul>

        <h2>How we use information</h2>
        <p>We use information to provide and improve the service, deliver messages across channels, generate the analytics you see in your dashboard, and keep the platform secure. We do not sell your data.</p>

        <h2>Data sharing</h2>
        <p>We share data only with sub-processors required to run the service (for example, messaging channel providers you connect and AI providers you configure), each under contractual data-protection obligations.</p>

        <h2>Data security</h2>
        <p>Every tenant is isolated with PostgreSQL row-level security, channel credentials and API secrets are encrypted, and access is audit-logged. Every boundary re-verifies authorization.</p>

        <h2>Your rights</h2>
        <p>You may access, export or delete your organization&apos;s data at any time. To make a request, email <a href="mailto:privacy@mkengage.com" style={{ color: "var(--cta)", fontWeight: 600 }}>privacy@mkengage.com</a>.</p>

        <h2>Contact</h2>
        <p>Questions about this policy? Reach us at <a href="mailto:privacy@mkengage.com" style={{ color: "var(--cta)", fontWeight: 600 }}>privacy@mkengage.com</a>.</p>
      </div>
    </section>
  );
}
