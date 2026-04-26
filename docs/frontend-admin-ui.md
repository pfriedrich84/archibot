# Frontend Admin UI Migration

This document tracks the new SvelteKit + Flowbite Svelte admin UI that is gradually replacing the legacy server-rendered HTMX interface.

## Current routing model

- New admin app: `/app`
- Legacy UI: `/`, `/review`, `/inbox`, `/tags`, ...
- JSON API for the new app: `/api/v1/*`

The old UI stays active until feature parity is reached. The new Svelte app is intentionally isolated under `/app` during migration.

## Build and serve

```bash
cd frontend
npm install
npm run build
```

The FastAPI backend serves the generated static SvelteKit build from `frontend/build`.

## Design system principles

- **Dark mode first**: dark surfaces and high-contrast metric cards are the default.
- **Persistent shell**: sidebar + sticky top bar for all admin routes.
- **Card composition**: dashboard widgets, status panels, settings groups, and placeholders all use rounded card containers.
- **Flowbite-first components**: prefer Flowbite Svelte primitives before adding custom wrappers.
- **German-first copy**: German strings should be complete; English may lag.
- **Migration transparency**: each incomplete screen should communicate its planned API surface and legacy fallback status.

## Shared component inventory

- `src/lib/components/AppShell.svelte`
  - persistent navigation shell
  - migration status messaging
  - locale toggle placeholder
- `src/lib/components/StatCard.svelte`
  - KPI/hero metric tile
- `src/lib/components/StatusPanel.svelte`
  - pipeline, reindex, logging, and runtime health overview
- `src/lib/components/PagePlaceholder.svelte`
  - explicit migration placeholder for unfinished views

## Route rollout plan

1. **Dashboard**: highest polish, backed by `/api/v1/dashboard` and `/api/v1/system/status`
2. **Settings**: schema-driven categories via `/api/v1/settings/schema`
3. **Errors / Stats / Review / Inbox**: migrate to data-rich Svelte tables and filters
4. **Embeddings / Chat / Setup**: specialized workflows with focused UX
5. **Cutover**: remove direct entrypoints to deprecated templates only after parity

## Deprecated legacy areas

The following are legacy UI surfaces slated for later deletion once parity is complete:

- `app/templates/*.html`
- template-driven routes in `app/routes/*.py` that only exist for server-rendered pages
- HTMX partials in `app/templates/partials/*`

Do not delete them yet; migration still depends on them for functional fallback.

## Testing strategy

- **Component/UI**: Vitest + Testing Library under `frontend/tests/components`
- **E2E smoke**: Playwright under `frontend/tests/e2e`
- **Backend integration**: FastAPI route tests in `tests/`

During migration, each new route should ideally have:

- one backend route/API test if backend behavior changed
- one component or page-level UI test
- one smoke assertion in Playwright if the route is user-facing
