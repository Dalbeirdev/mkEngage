import type { Metadata } from "next";

import { BrandGradientDef } from "@/components/marketing/brand";
import { MarketingFooter } from "@/components/marketing/footer";
import { MarketingNav } from "@/components/marketing/nav";

import "./marketing.css";

export const metadata: Metadata = {
  title: "mkEngage — AI Chatbots, Live Chat & Automation",
  description:
    "mkEngage helps you engage, support and convert customers across every channel with AI chatbots, live chat, and smart automation.",
};

export default function MarketingLayout({
  children,
}: Readonly<{ children: React.ReactNode }>) {
  return (
    <div className="mkm">
      <BrandGradientDef />
      <MarketingNav />
      {children}
      <MarketingFooter />
    </div>
  );
}
