"use client";

import { use, useCallback, useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";
import {
  addEdge,
  Background,
  Controls,
  Handle,
  Position,
  ReactFlow,
  useEdgesState,
  useNodesState,
  type Connection,
  type Edge,
  type Node,
  type NodeProps,
} from "@xyflow/react";
import "@xyflow/react/dist/style.css";

import { z } from "zod";

/**
 * mkEngage Flow builder (Phase 27): drag-drop canvas over the chatbot's
 * flow graph. Nodes carry their editable props in data; edges from an
 * options node carry the chosen option as their label.
 */

const NODE_TYPES = ["message", "options", "question", "handoff", "ai", "end"] as const;
type FlowNodeType = (typeof NODE_TYPES)[number];

const flowSchema = z.object({
  flow: z
    .object({
      start: z.string(),
      nodes: z.array(
        z.object({
          id: z.string(),
          type: z.enum(NODE_TYPES),
          text: z.string().nullish(),
          options: z.array(z.string()).optional(),
          variable: z.string().nullish(),
          x: z.number().optional(),
          y: z.number().optional(),
        }),
      ),
      edges: z
        .array(z.object({ from: z.string(), to: z.string(), option: z.string().optional() }))
        .optional(),
    })
    .nullable(),
});

type StoredFlow = NonNullable<z.infer<typeof flowSchema>["flow"]>;
type NodeData = {
  nodeType: FlowNodeType;
  text: string;
  options: string[];
  variable: string;
  isStart: boolean;
};

const TYPE_COLORS: Record<FlowNodeType, string> = {
  message: "#4f46e5",
  options: "#0891b2",
  question: "#d97706",
  handoff: "#dc2626",
  ai: "#7c3aed",
  end: "#52525b",
};

function FlowNode({ data }: NodeProps) {
  const d = data as NodeData;
  return (
    <div
      className="min-w-40 max-w-56 rounded-lg border bg-white text-xs shadow-sm dark:bg-zinc-900"
      style={{ borderColor: TYPE_COLORS[d.nodeType] }}
    >
      <Handle type="target" position={Position.Top} />
      <div
        className="rounded-t-md px-2 py-1 font-semibold text-white"
        style={{ background: TYPE_COLORS[d.nodeType] }}
      >
        {d.nodeType}
        {d.isStart ? " · START" : ""}
      </div>
      <div className="px-2 py-1.5 text-zinc-700 dark:text-zinc-300">
        {d.nodeType === "ai" && "Hand over to the AI assistant"}
        {d.nodeType === "handoff" && (d.text || "Route to a human agent")}
        {d.nodeType !== "ai" && d.nodeType !== "handoff" && (d.text || "…")}
        {d.nodeType === "options" && d.options.length > 0 && (
          <div className="mt-1 flex flex-wrap gap-1">
            {d.options.map((option) => (
              <span key={option} className="rounded-full bg-zinc-100 px-1.5 dark:bg-zinc-800">
                {option}
              </span>
            ))}
          </div>
        )}
        {d.nodeType === "question" && d.variable !== "" && (
          <div className="mt-1 font-mono text-[10px] text-zinc-500">→ {"{{" + d.variable + "}}"}</div>
        )}
      </div>
      <Handle type="source" position={Position.Bottom} />
    </div>
  );
}

const nodeTypes = { flowNode: FlowNode };

/** Starter templates (Phase 30) — loaded onto an empty canvas with one click. */
const TEMPLATES: Record<string, StoredFlow> = {
  lead_capture: {
    start: "n1",
    nodes: [
      { id: "n1", type: "message", text: "Hi there! 👋 Before we start, a couple of quick questions.", x: 120, y: 40 },
      { id: "n2", type: "question", text: "What's your name?", variable: "name", x: 120, y: 190 },
      { id: "n3", type: "question", text: "Thanks {{name}}! What's the best email to reach you?", variable: "email", x: 120, y: 340 },
      { id: "n4", type: "end", text: "Perfect — our team will reach out at {{email}} shortly!", x: 120, y: 490 },
    ],
    edges: [
      { from: "n1", to: "n2" },
      { from: "n2", to: "n3" },
      { from: "n3", to: "n4" },
    ],
  },
  support_triage: {
    start: "n1",
    nodes: [
      { id: "n1", type: "options", text: "Welcome to support! What do you need help with?", options: ["Billing", "Technical issue", "Something else"], x: 220, y: 40 },
      { id: "n2", type: "handoff", text: "Connecting you to our billing team…", x: 40, y: 240 },
      { id: "n3", type: "question", text: "Sorry to hear that! Briefly, what's going wrong?", variable: "issue", x: 240, y: 240 },
      { id: "n4", type: "handoff", text: "Thanks — an engineer will take it from here.", x: 240, y: 400 },
      { id: "n5", type: "ai", x: 460, y: 240 },
    ],
    edges: [
      { from: "n1", to: "n2", option: "Billing" },
      { from: "n1", to: "n3", option: "Technical issue" },
      { from: "n1", to: "n5", option: "Something else" },
      { from: "n3", to: "n4" },
    ],
  },
  faq_ai: {
    start: "n1",
    nodes: [
      { id: "n1", type: "message", text: "Hi! Ask me anything about our product — I'm powered by your knowledge base.", x: 120, y: 60 },
      { id: "n2", type: "ai", x: 120, y: 220 },
    ],
    edges: [{ from: "n1", to: "n2" }],
  },
};

async function fetchFlow(chatbotId: string): Promise<StoredFlow | null> {
  const response = await fetch(`/api/cp/chatbots/${chatbotId}/flow`, { cache: "no-store" });
  if (!response.ok) throw new Error(`Failed to load flow (${response.status})`);
  return flowSchema.parse(await response.json()).flow;
}

function toCanvas(flow: StoredFlow | null): { nodes: Node[]; edges: Edge[] } {
  if (flow === null) return { nodes: [], edges: [] };
  return {
    nodes: flow.nodes.map((node, index) => ({
      id: node.id,
      type: "flowNode",
      position: { x: node.x ?? 80 + (index % 3) * 260, y: node.y ?? 60 + Math.floor(index / 3) * 170 },
      data: {
        nodeType: node.type,
        text: node.text ?? "",
        options: node.options ?? [],
        variable: node.variable ?? "",
        isStart: flow.start === node.id,
      } satisfies NodeData,
    })),
    edges: (flow.edges ?? []).map((edge, index) => ({
      id: `e${index}`,
      source: edge.from,
      target: edge.to,
      label: edge.option,
    })),
  };
}

export default function FlowBuilderPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const t = useTranslations("flowBuilder");
  const queryClient = useQueryClient();

  const { data, isPending, isError } = useQuery({
    queryKey: ["chatbot", id, "flow"],
    queryFn: () => fetchFlow(id),
  });

  return (
    <div className="flex h-[calc(100dvh-7rem)] flex-col space-y-3">
      <h1 className="text-2xl font-bold tracking-tight">{t("title")}</h1>
      {isPending && (
        <p className="text-sm text-zinc-500" role="status">
          {t("loading")}
        </p>
      )}
      {isError && (
        <p className="text-sm text-red-600" role="alert">
          {t("error")}
        </p>
      )}
      {data !== undefined && (
        <FlowCanvas
          chatbotId={id}
          initial={data}
          onSaved={() => void queryClient.invalidateQueries({ queryKey: ["chatbot", id, "flow"] })}
        />
      )}
    </div>
  );
}

function FlowCanvas({
  chatbotId,
  initial,
  onSaved,
}: {
  chatbotId: string;
  initial: StoredFlow | null;
  onSaved: () => void;
}) {
  const t = useTranslations("flowBuilder");
  const seeded = useMemo(() => toCanvas(initial), [initial]);
  const [nodes, setNodes, onNodesChange] = useNodesState(seeded.nodes);
  const [edges, setEdges, onEdgesChange] = useEdgesState(seeded.edges);
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [startId, setStartId] = useState<string | null>(initial?.start ?? null);
  const [saved, setSaved] = useState(false);

  const selected = nodes.find((node) => node.id === selectedId) ?? null;

  const save = useMutation({
    mutationFn: async () => {
      const payload: StoredFlow | null =
        nodes.length === 0
          ? null
          : {
              start: startId ?? nodes[0]!.id,
              nodes: nodes.map((node) => {
                const d = node.data as NodeData;
                return {
                  id: node.id,
                  type: d.nodeType,
                  ...(d.text.trim() !== "" ? { text: d.text.trim() } : {}),
                  ...(d.nodeType === "options" ? { options: d.options.filter((o) => o.trim() !== "") } : {}),
                  ...(d.nodeType === "question" && d.variable.trim() !== ""
                    ? { variable: d.variable.trim() }
                    : {}),
                  x: Math.round(node.position.x),
                  y: Math.round(node.position.y),
                };
              }),
              edges: edges.map((edge) => ({
                from: edge.source,
                to: edge.target,
                ...(typeof edge.label === "string" && edge.label !== "" ? { option: edge.label } : {}),
              })),
            };

      const response = await fetch(`/api/cp/chatbots/${chatbotId}/flow`, {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ flow: payload }),
      });
      if (!response.ok) throw new Error(`Save failed (${response.status})`);
    },
    onSuccess: () => {
      setSaved(true);
      setTimeout(() => setSaved(false), 2500);
      onSaved();
    },
  });

  const addNode = (type: FlowNodeType) => {
    // Deterministic next id: max numeric suffix over existing ids + 1
    // (collision-safe against loaded flows; keeps the linter's purity rule).
    const max = nodes.reduce((highest, node) => {
      const match = /^n(\d+)$/.exec(node.id);
      return match ? Math.max(highest, Number(match[1])) : highest;
    }, 0);
    const id = `n${max + 1}`;
    setNodes((previous) => [
      ...previous,
      {
        id,
        type: "flowNode",
        position: { x: 120 + previous.length * 30, y: 80 + previous.length * 30 },
        data: {
          nodeType: type,
          text: "",
          options: type === "options" ? ["Option 1"] : [],
          variable: "",
          isStart: previous.length === 0,
        } satisfies NodeData,
      },
    ]);
    if (nodes.length === 0) setStartId(id);
    setSelectedId(id);
  };

  const updateSelected = (patch: Partial<NodeData>) => {
    setNodes((previous) =>
      previous.map((node) =>
        node.id === selectedId ? { ...node, data: { ...(node.data as NodeData), ...patch } } : node,
      ),
    );
  };

  const onConnect = useCallback(
    (connection: Connection) => {
      const source = nodes.find((node) => node.id === connection.source);
      const isOptions = (source?.data as NodeData | undefined)?.nodeType === "options";
      const option = isOptions
        ? (((source?.data as NodeData).options[0] ?? "Option") as string)
        : undefined;
      setEdges((previous) => addEdge({ ...connection, ...(option !== undefined ? { label: option } : {}) }, previous));
    },
    [nodes, setEdges],
  );

  const inputClass =
    "w-full rounded-md border border-zinc-300 px-2 py-1 text-sm dark:border-zinc-700 dark:bg-zinc-950";

  return (
    <div className="flex min-h-0 flex-1 gap-3">
      {/* Palette */}
      <aside className="w-36 shrink-0 space-y-2">
        <p className="text-xs font-semibold uppercase tracking-wide text-zinc-500">{t("palette")}</p>
        {NODE_TYPES.map((type) => (
          <button
            key={type}
            type="button"
            onClick={() => addNode(type)}
            className="w-full rounded-md border border-zinc-200 bg-white px-2 py-1.5 text-start text-sm capitalize shadow-sm hover:border-zinc-400 dark:border-zinc-700 dark:bg-zinc-900"
          >
            <span className="me-2 inline-block size-2 rounded-full" style={{ background: TYPE_COLORS[type] }} />
            {t(`type_${type}`)}
          </button>
        ))}
        <button
          type="button"
          disabled={save.isPending}
          onClick={() => save.mutate()}
          className="w-full rounded-md bg-indigo-600 px-2 py-1.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60"
        >
          {save.isPending ? t("saving") : saved ? t("savedNotice") : t("save")}
        </button>
        {save.isError && (
          <p className="text-xs text-red-600" role="alert">
            {t("saveError")}
          </p>
        )}
      </aside>

      {/* Canvas */}
      <div className="relative min-w-0 flex-1 overflow-hidden rounded-2xl border border-zinc-200 dark:border-zinc-800">
        {nodes.length === 0 && (
          <div className="absolute inset-0 z-10 grid place-items-center">
            <div className="space-y-2 rounded-xl border border-zinc-200 bg-white/95 p-4 text-center shadow-lg dark:border-zinc-700 dark:bg-zinc-900/95">
              <p className="text-sm font-semibold">{t("templatesTitle")}</p>
              <div className="flex flex-wrap justify-center gap-2">
                {Object.keys(TEMPLATES).map((key) => (
                  <button
                    key={key}
                    type="button"
                    onClick={() => {
                      const seededTemplate = toCanvas(TEMPLATES[key] ?? null);
                      setNodes(seededTemplate.nodes);
                      setEdges(seededTemplate.edges);
                      setStartId(TEMPLATES[key]?.start ?? null);
                    }}
                    className="rounded-md border border-indigo-300 px-3 py-1.5 text-xs font-medium text-indigo-700 hover:bg-indigo-50 dark:border-indigo-800 dark:text-indigo-300 dark:hover:bg-indigo-950"
                  >
                    {t(`template_${key}`)}
                  </button>
                ))}
              </div>
              <p className="text-xs text-zinc-500">{t("templatesHint")}</p>
            </div>
          </div>
        )}
        <ReactFlow
          nodes={nodes}
          edges={edges}
          nodeTypes={nodeTypes}
          onNodesChange={onNodesChange}
          onEdgesChange={onEdgesChange}
          onConnect={onConnect}
          onNodeClick={(_, node) => setSelectedId(node.id)}
          onPaneClick={() => setSelectedId(null)}
          fitView
          proOptions={{ hideAttribution: true }}
        >
          <Background />
          <Controls />
        </ReactFlow>
      </div>

      {/* Inspector */}
      <aside className="w-64 shrink-0 space-y-3">
        <p className="text-xs font-semibold uppercase tracking-wide text-zinc-500">{t("inspector")}</p>
        {selected === null ? (
          <p className="text-sm text-zinc-500">{t("inspectorEmpty")}</p>
        ) : (
          <div className="space-y-2">
            <p className="text-sm font-medium capitalize">{t(`type_${(selected.data as NodeData).nodeType}`)}</p>
            {(selected.data as NodeData).nodeType !== "ai" && (
              <label className="block text-sm">
                {t("fieldText")}
                <textarea
                  rows={3}
                  value={(selected.data as NodeData).text}
                  onChange={(event) => updateSelected({ text: event.target.value })}
                  className={inputClass}
                />
              </label>
            )}
            {(selected.data as NodeData).nodeType === "options" && (
              <label className="block text-sm">
                {t("fieldOptions")}
                <input
                  type="text"
                  value={(selected.data as NodeData).options.join(", ")}
                  onChange={(event) =>
                    updateSelected({ options: event.target.value.split(",").map((o) => o.trim()) })
                  }
                  className={inputClass}
                  placeholder="Sales, Support"
                />
              </label>
            )}
            {(selected.data as NodeData).nodeType === "question" && (
              <label className="block text-sm">
                {t("fieldVariable")}
                <input
                  type="text"
                  value={(selected.data as NodeData).variable}
                  onChange={(event) => updateSelected({ variable: event.target.value })}
                  className={inputClass}
                  placeholder="name"
                />
              </label>
            )}
            <label className="flex items-center gap-2 text-sm">
              <input
                type="radio"
                checked={startId === selected.id}
                onChange={() => {
                  setStartId(selected.id);
                  setNodes((previous) =>
                    previous.map((node) => ({
                      ...node,
                      data: { ...(node.data as NodeData), isStart: node.id === selected.id },
                    })),
                  );
                }}
              />
              {t("markStart")}
            </label>
            <button
              type="button"
              onClick={() => {
                setNodes((previous) => previous.filter((node) => node.id !== selected.id));
                setEdges((previous) =>
                  previous.filter((edge) => edge.source !== selected.id && edge.target !== selected.id),
                );
                setSelectedId(null);
              }}
              className="rounded-md border border-red-300 px-2 py-1 text-xs text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-950"
            >
              {t("deleteNode")}
            </button>
          </div>
        )}
        <p className="text-xs text-zinc-500">{t("hint")}</p>
      </aside>
    </div>
  );
}
