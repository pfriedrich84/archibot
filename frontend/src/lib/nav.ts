export type NavItem = {
  href: string;
  label: string;
  emoji: string;
  badgeKey?: string;
};

export type NavGroup = {
  label: string;
  items: NavItem[];
};

export const navGroups: NavGroup[] = [
  {
    label: 'Arbeitsplatz',
    items: [
      { href: '/', label: 'Dashboard', emoji: '📊' },
      { href: '/inbox', label: 'Posteingang', emoji: '📥', badgeKey: 'inbox' },
      { href: '/review', label: 'Review Queue', emoji: '✅', badgeKey: 'review' }
    ]
  },
  {
    label: 'Freigaben',
    items: [{ href: '/tags', label: 'Tags', emoji: '🏷️', badgeKey: 'approvals' }]
  },
  {
    label: 'Analyse',
    items: [
      { href: '/chat', label: 'Chat', emoji: '💬' },
      { href: '/embeddings', label: 'Embeddings', emoji: '🧠' },
      { href: '/stats', label: 'Statistiken', emoji: '📈' },
      { href: '/errors', label: 'Fehler', emoji: '🚨', badgeKey: 'errors' }
    ]
  },
  {
    label: 'System',
    items: [
      { href: '/settings', label: 'Einstellungen', emoji: '⚙️' },
      { href: '/setup', label: 'Setup', emoji: '🪄' },
      { href: '/auth/sign-in', label: 'Anmeldung', emoji: '🔐' }
    ]
  }
];

export const navItems = navGroups.flatMap((group) => group.items);
