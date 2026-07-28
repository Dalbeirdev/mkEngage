/** Minimal 18px stroke icons (Lucide-style) for the sidebar. */
const base = {
  width: 18,
  height: 18,
  viewBox: "0 0 24 24",
  fill: "none",
  stroke: "currentColor",
  strokeWidth: 2,
  strokeLinecap: "round" as const,
  strokeLinejoin: "round" as const,
};

export const IconDashboard = () => (
  <svg {...base}>
    <rect x="3" y="3" width="7" height="9" rx="1" />
    <rect x="14" y="3" width="7" height="5" rx="1" />
    <rect x="14" y="12" width="7" height="9" rx="1" />
    <rect x="3" y="16" width="7" height="5" rx="1" />
  </svg>
);

export const IconConversations = () => (
  <svg {...base}>
    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
  </svg>
);

export const IconContacts = () => (
  <svg {...base}>
    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
    <circle cx="9" cy="7" r="4" />
    <path d="M22 21v-2a4 4 0 0 0-3-3.87" />
  </svg>
);

export const IconChatbots = () => (
  <svg {...base}>
    <rect x="4" y="8" width="16" height="12" rx="2" />
    <path d="M12 8V4M8 2h8" />
    <circle cx="9" cy="14" r="1" />
    <circle cx="15" cy="14" r="1" />
  </svg>
);

export const IconKnowledge = () => (
  <svg {...base}>
    <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" />
    <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" />
  </svg>
);

export const IconDepartments = () => (
  <svg {...base}>
    <rect x="3" y="3" width="7" height="7" rx="1" />
    <rect x="14" y="3" width="7" height="7" rx="1" />
    <rect x="14" y="14" width="7" height="7" rx="1" />
    <rect x="3" y="14" width="7" height="7" rx="1" />
  </svg>
);

export const IconWidget = () => (
  <svg {...base}>
    <path d="M7 8h10M7 12h6" />
    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
  </svg>
);

export const IconVisitors = () => (
  <svg {...base}>
    <circle cx="12" cy="12" r="2" />
    <path d="M16.24 7.76a6 6 0 0 1 0 8.49" />
    <path d="M7.76 16.24a6 6 0 0 1 0-8.49" />
    <path d="M19.07 4.93a10 10 0 0 1 0 14.14" />
    <path d="M4.93 19.07a10 10 0 0 1 0-14.14" />
  </svg>
);

export const IconProfile = () => (
  <svg {...base}>
    <circle cx="12" cy="8" r="4" />
    <path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1" />
  </svg>
);

export const IconChannels = () => (
  <svg {...base}>
    <circle cx="12" cy="12" r="10" />
    <path d="M2 12h20" />
    <path d="M12 2a15.3 15.3 0 0 1 0 20 15.3 15.3 0 0 1 0-20" />
  </svg>
);

export const IconCanned = () => (
  <svg {...base}>
    <path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z" />
  </svg>
);

export const IconDevelopers = () => (
  <svg {...base}>
    <path d="M16 18l6-6-6-6" />
    <path d="M8 6l-6 6 6 6" />
  </svg>
);

export const IconSettings = () => (
  <svg {...base}>
    <circle cx="12" cy="12" r="3" />
    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
  </svg>
);

export const IconSearch = () => (
  <svg {...base}>
    <circle cx="11" cy="11" r="8" />
    <path d="m21 21-4.3-4.3" />
  </svg>
);

export const IconBell = () => (
  <svg {...base}>
    <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
    <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
  </svg>
);

export const IconSparkles = () => (
  <svg {...base}>
    <path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z" />
    <path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z" />
  </svg>
);

export const IconMessages = () => (
  <svg {...base}>
    <rect x="2" y="4" width="20" height="16" rx="2" />
    <path d="m2 7 10 6 10-6" />
  </svg>
);

export const IconBot = () => (
  <svg {...base}>
    <rect x="4" y="8" width="16" height="12" rx="3" />
    <path d="M12 8V4" />
    <circle cx="12" cy="3" r="1" />
    <path d="M9 14h.01M15 14h.01" />
  </svg>
);

export const IconClock = () => (
  <svg {...base}>
    <circle cx="12" cy="12" r="9" />
    <path d="M12 7v5l3 2" />
  </svg>
);

export const IconStar = () => (
  <svg {...base}>
    <path d="M12 3l2.9 6 6.6.9-4.8 4.6 1.1 6.5L12 18l-5.9 3 1.1-6.5L2.4 9.9 9 9z" />
  </svg>
);

export const IconTrendUp = () => (
  <svg {...base}>
    <path d="M22 7 13.5 15.5l-5-5L2 17" />
    <path d="M16 7h6v6" />
  </svg>
);

export const IconTrendDown = () => (
  <svg {...base}>
    <path d="M22 17 13.5 8.5l-5 5L2 7" />
    <path d="M16 17h6v-6" />
  </svg>
);

export const IconArrowRight = () => (
  <svg {...base}>
    <path d="M5 12h14" />
    <path d="m12 5 7 7-7 7" />
  </svg>
);
