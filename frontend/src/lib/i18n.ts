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
    poweredBy: 'SvelteKit + Flowbite Admin UI'
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
    poweredBy: 'SvelteKit + Flowbite admin UI'
  }
} as const;
