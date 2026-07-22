import "@testing-library/jest-dom/vitest";
import { cleanup } from "@testing-library/react";
import { vi, afterEach } from "vitest";
import type { ReactNode } from "react";

afterEach(() => {
  cleanup();
});

// next-intl's useTranslations needs a provider; unit tests stub it with a
// pass-through that returns the key (assertions use keys, not copy).
vi.mock("next-intl", async (importOriginal) => {
  const actual = await importOriginal<typeof import("next-intl")>();
  return {
    ...actual,
    useTranslations: () => (key: string) => key,
    NextIntlClientProvider: ({ children }: { children: ReactNode }) => children,
  };
});
