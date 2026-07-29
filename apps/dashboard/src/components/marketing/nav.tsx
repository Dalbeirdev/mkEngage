"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

import { BrandMark } from "./brand";

const LINKS = [
  { href: "/", label: "Home" },
  { href: "/features", label: "Features" },
  { href: "/pricing", label: "Pricing" },
  { href: "/about", label: "About Us" },
  { href: "/resources", label: "Resources" },
];

export function MarketingNav() {
  const pathname = usePathname();

  return (
    <header className="nav">
      <div className="wrap nav-in">
        <Link className="brand" href="/">
          <BrandMark size={30} />
          <span>mk<span className="mk-word">Engage</span></span>
        </Link>
        <nav className="nav-links" aria-label="Primary">
          {LINKS.map((l) => {
            const active = l.href === "/" ? pathname === "/" : pathname.startsWith(l.href);
            return (
              <Link key={l.href} href={l.href} className={active ? "active" : undefined}>
                {l.label}
              </Link>
            );
          })}
        </nav>
        <div className="nav-cta">
          <Link className="nav-login" href="/login">Log in</Link>
          <Link className="btn btn-primary" href="/signup">Start Free Trial</Link>
        </div>
      </div>
    </header>
  );
}
