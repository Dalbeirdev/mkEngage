import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";

import { ARTICLES, findArticle } from "../articles";

type Props = { params: Promise<{ slug: string }> };

export function generateStaticParams(): Array<{ slug: string }> {
  return ARTICLES.map((a) => ({ slug: a.slug }));
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const article = findArticle((await params).slug);
  return { title: article ? `${article.t} — mkEngage` : "Resources — mkEngage" };
}

export default async function ArticlePage({ params }: Props) {
  const article = findArticle((await params).slug);
  if (article === undefined) notFound();

  return (
    <>
      <section className="hero soft-bg" style={{ paddingBottom: 34 }}>
        <div className="wrap" style={{ maxWidth: "46em", margin: "0 auto" }}>
          <span className="eyebrow">{article.kind}</span>
          <h1 style={{ marginTop: 16 }}>{article.t}</h1>
          <p className="sub" style={{ marginTop: 16 }}>{article.d}</p>
          <p style={{ marginTop: 14, fontSize: 14, color: "#6b7280" }}>{article.minutes} min read</p>
        </div>
      </section>

      <section className="section" style={{ paddingTop: 10 }}>
        <div className="wrap" style={{ maxWidth: "46em", margin: "0 auto" }}>
          <div className="cover" style={{ background: article.cover, height: 150, borderRadius: 16, display: "flex", alignItems: "center", justifyContent: "center" }}>
            <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#fff" strokeWidth="1.5"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" /><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" /></svg>
          </div>

          {article.sections.map((s) => (
            <div key={s.h} style={{ marginTop: 34 }}>
              <h2 style={{ fontSize: 22, marginBottom: 12 }}>{s.h}</h2>
              {s.ps?.map((p) => (
                <p key={p.slice(0, 40)} style={{ margin: "0 0 14px", fontSize: 15.5, lineHeight: 1.75, color: "#3f4451" }}>{p}</p>
              ))}
              {s.bullets !== undefined && (
                <ul style={{ margin: "0 0 14px", paddingLeft: 22, display: "grid", gap: 8, fontSize: 15.5, lineHeight: 1.65, color: "#3f4451" }}>
                  {s.bullets.map((b) => <li key={b}>{b}</li>)}
                </ul>
              )}
            </div>
          ))}

          <p style={{ marginTop: 36 }}>
            <Link href="/resources" style={{ fontWeight: 600, color: "#6d3bf5" }}>← All resources</Link>
          </p>
        </div>
      </section>

      <section style={{ padding: "8px 0 80px" }}>
        <div className="wrap">
          <div className="cta-band">
            <div><h2>Ready to try it yourself?</h2><p>Set up mkEngage in minutes — no credit card required.</p></div>
            <div className="acts"><Link className="btn btn-white" href="/signup">Start Free Trial →</Link><Link className="btn btn-outline-white" href="/contact">Talk to Us</Link></div>
          </div>
        </div>
      </section>
    </>
  );
}
