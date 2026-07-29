import Link from "next/link";

import { BrandMark } from "./brand";
import { NewsletterForm } from "./newsletter-form";

const COLS: { title: string; links: { label: string; href: string }[] }[] = [
  { title: "Product", links: [
    { label: "Features", href: "/features" }, { label: "Pricing", href: "/pricing" },
    { label: "Integrations", href: "/features" }, { label: "Changelog", href: "/resources" },
  ] },
  { title: "Resources", links: [
    { label: "Blog", href: "/resources" }, { label: "Help Center", href: "/resources" },
    { label: "Documentation", href: "/resources" }, { label: "Webinars", href: "/resources" },
  ] },
  { title: "Company", links: [
    { label: "About Us", href: "/about" }, { label: "Careers", href: "/about" },
    { label: "Contact Us", href: "/contact" }, { label: "Press Kit", href: "/about" },
  ] },
  { title: "Legal", links: [
    { label: "Privacy Policy", href: "/legal/privacy" }, { label: "Terms & Conditions", href: "/legal/terms" },
    { label: "Security", href: "/legal/privacy" }, { label: "Cookie Policy", href: "/legal/privacy" },
  ] },
];

export function MarketingFooter() {
  return (
    <footer className="foot">
      <div className="wrap foot-grid">
        <div>
          <Link className="brand" href="/">
            <BrandMark size={28} faceLight />
            <span>mk<span className="mk-word">Engage</span></span>
          </Link>
          <p className="desc">AI-powered customer engagement platform that helps you connect, support and convert customers across every channel.</p>
          <div className="social">
            <a href="#" aria-label="Facebook">f</a><a href="#" aria-label="X">✕</a><a href="#" aria-label="LinkedIn">in</a><a href="#" aria-label="YouTube">▶</a>
          </div>
        </div>
        {COLS.map((col) => (
          <div key={col.title}>
            <h5>{col.title}</h5>
            <ul>
              {col.links.map((l, i) => (
                <li key={`${l.label}-${i}`}><Link href={l.href}>{l.label}</Link></li>
              ))}
            </ul>
          </div>
        ))}
        <div>
          <h5>Stay Updated</h5>
          <p style={{ fontSize: "13.5px" }}>Subscribe to our newsletter for product updates and tips.</p>
          <NewsletterForm />
        </div>
      </div>
      <div className="wrap foot-bottom">© 2026 mkEngage. All rights reserved.</div>
    </footer>
  );
}
