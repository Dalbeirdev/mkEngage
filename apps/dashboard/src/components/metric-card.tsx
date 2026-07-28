"use client";

import { useId } from "react";

import { IconStar, IconTrendDown, IconTrendUp } from "@/components/icons";

/** Shared card surface used across dashboard + list pages. */
export const cardShell =
  "rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900";

const TINTS: Record<string, string> = {
  indigo: "bg-indigo-50 text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-300",
  pink: "bg-pink-50 text-pink-600 dark:bg-pink-500/15 dark:text-pink-300",
  violet: "bg-violet-50 text-violet-600 dark:bg-violet-500/15 dark:text-violet-300",
  sky: "bg-sky-50 text-sky-600 dark:bg-sky-500/15 dark:text-sky-300",
  amber: "bg-amber-50 text-amber-600 dark:bg-amber-500/15 dark:text-amber-300",
  emerald: "bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-300",
  zinc: "bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300",
};

const SPARK_STROKE: Record<string, string> = {
  indigo: "#6366f1",
  pink: "#ec4899",
  violet: "#8b5cf6",
  sky: "#0ea5e9",
  amber: "#f59e0b",
  emerald: "#10b981",
  zinc: "#71717a",
};

export function MetricCard({
  icon,
  tint,
  label,
  value,
  spark,
  delta,
  caption,
  stars,
}: {
  icon: React.ReactNode;
  tint: keyof typeof TINTS;
  label: string;
  value: string;
  spark?: number[];
  delta?: number | null;
  caption?: string;
  stars?: number | null;
}) {
  return (
    <div className={cardShell}>
      <div className="flex items-center gap-2.5">
        <span className={`grid size-9 shrink-0 place-items-center rounded-xl ${TINTS[tint]}`} aria-hidden>
          {icon}
        </span>
        <span className="text-sm font-medium text-zinc-500">{label}</span>
      </div>
      <div className="mt-3 text-3xl font-bold tracking-tight tabular-nums">{value}</div>

      {stars !== undefined && <Stars value={stars} />}

      {delta !== undefined && delta !== null && (
        <div className="mt-2 flex items-center gap-1 text-xs">
          <span className={delta >= 0 ? "text-emerald-600" : "text-red-600"}>
            {delta >= 0 ? <IconTrendUp /> : <IconTrendDown />}
          </span>
          <span className={delta >= 0 ? "font-medium text-emerald-600" : "font-medium text-red-600"}>
            {delta >= 0 ? "+" : ""}
            {Math.round(delta * 100)}%
          </span>
          <span className="text-zinc-400">vs first half</span>
        </div>
      )}

      {spark !== undefined && spark.length > 1 && (
        <Sparkline data={spark} stroke={SPARK_STROKE[tint] ?? "#6366f1"} />
      )}

      {caption !== undefined && delta === undefined && (
        <p className="mt-2 text-xs text-zinc-400">{caption}</p>
      )}
    </div>
  );
}

function Stars({ value }: { value: number | null | undefined }) {
  const filled = value === null || value === undefined ? 0 : Math.round(value);
  return (
    <div className="mt-2 flex gap-0.5 text-indigo-500" aria-hidden>
      {[1, 2, 3, 4, 5].map((i) => (
        <span key={i} className={i <= filled ? "text-indigo-500" : "text-zinc-300 dark:text-zinc-700"}>
          <IconStar />
        </span>
      ))}
    </div>
  );
}

export function Sparkline({ data, stroke }: { data: number[]; stroke: string }) {
  const gid = `sp${useId().replace(/:/g, "")}`;
  const w = 120;
  const h = 34;
  const max = Math.max(1, ...data);
  const step = data.length > 1 ? w / (data.length - 1) : w;
  const pts = data.map((v, i) => [i * step, h - (v / max) * (h - 4) - 2] as const);
  const line = pts.map(([x, y]) => `${x.toFixed(1)},${y.toFixed(1)}`).join(" ");
  const area = `M0,${h} L${line.split(" ").join(" L")} L${w},${h} Z`;
  return (
    <svg viewBox={`0 0 ${w} ${h}`} className="mt-3 h-8 w-full" preserveAspectRatio="none" aria-hidden>
      <defs>
        <linearGradient id={gid} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor={stroke} stopOpacity="0.25" />
          <stop offset="1" stopColor={stroke} stopOpacity="0" />
        </linearGradient>
      </defs>
      <path d={area} fill={`url(#${gid})`} />
      <polyline points={line} fill="none" stroke={stroke} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}
