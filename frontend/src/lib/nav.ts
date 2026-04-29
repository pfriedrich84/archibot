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
      { href: '/review', label: 'Review', emoji: '✅', badgeKey: 'review' },
      { href: '/inbox', label: 'Posteingang', emoji: '📥', badgeKey: 'inbox' },
      { href: '/processing', label: 'Verarbeitung', emoji: '⚡', badgeKey: 'errors' }
    ]
  },
  {
    label: 'Freigaben',
    items: [
      { href: '/tags', label: 'Tags', emoji: '🏷️', badgeKey: 'tags' },
      { href: '/correspondents', label: 'Korrespondenten', emoji: '👤', badgeKey: 'correspondents' },
      { href: '/doctypes', label: 'Dokumenttypen', emoji: '📄', badgeKey: 'doctypes' }
    ]
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
