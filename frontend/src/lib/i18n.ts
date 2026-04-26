export type Locale = 'de' | 'en';

export const translations = {
  de: {
    dashboard: 'Dashboard',
    review: 'Review Queue',
    inbox: 'Posteingang',
    tags: 'Tags',
    embeddings: 'Embeddings',
    stats: 'Statistiken',
    settings: 'Einstellungen',
    setup: 'Setup',
    errors: 'Fehler',
    chat: 'Chat',
    migrationPreview: 'Migrationsvorschau',
    poweredBy: 'Neue SvelteKit + Flowbite Admin UI',
    legacyUi: 'Legacy-UI bleibt bis zur Feature-Parity aktiv.'
  },
  en: {
    dashboard: 'Dashboard',
    review: 'Review Queue',
    inbox: 'Inbox',
    tags: 'Tags',
    embeddings: 'Embeddings',
    stats: 'Stats',
    settings: 'Settings',
    setup: 'Setup',
    errors: 'Errors',
    chat: 'Chat',
    migrationPreview: 'Migration Preview',
    poweredBy: 'New SvelteKit + Flowbite admin UI',
    legacyUi: 'Legacy UI remains active until feature parity.'
  }
} as const;
