# Frontend Admin UI

This document tracks the SvelteKit + Flowbite Svelte admin UI design principles and implementation notes.

## Current routing model

- Admin app: `/app`
- JSON API for the app: `/api/v1/*`

## Build and serve

```bash
cd frontend
npm install
npm run build
```

The FastAPI backend serves the generated static SvelteKit build from `frontend/build`.

## Design system principles

- **Compact by default**: headers, cards, lists, filters, and empty states should use dense spacing (`p-4`, `gap-4`, `rounded-2xl`) unless a workflow explicitly needs more room.
- **No legacy fallbacks in the UI**: do not show fallback buttons or migration labels in the admin interface. Users should see one coherent app.
- **Condensed filters**: filter/sort panels should be collapsed by default unless filtering is the primary task on that page.
- **Small status pills over large summary blocks**: prefer compact chips for counts and state summaries; reserve large cards for primary KPIs only.
- **Human-readable values**: show Paperless names for correspondents, document types, storage paths, and tags. IDs are fallback/debug data only.
- **Clear visual separation**: split navigation/list panes from detail panes with borders or spacing, especially in review workflows.
- **Dark mode first**: dark surfaces and high-contrast status accents are the default.
- **Persistent shell**: sidebar + sticky top bar for all admin routes.
- **Flowbite-first components**: prefer Flowbite Svelte primitives before adding custom wrappers.
- **German-first copy**: German strings should be complete; English may lag.

## Shared component inventory

- `src/lib/components/AppShell.svelte`
  - persistent navigation shell
  - compact page header
  - locale toggle placeholder
- `src/lib/components/StatCard.svelte`
  - KPI/hero metric tile
- `src/lib/components/StatusPanel.svelte`
  - pipeline, reindex, logging, and runtime health overview
- `src/lib/components/PagePlaceholder.svelte`
  - explicit migration placeholder for unfinished views

## Route UX checklist

Before adding or changing an admin page, verify:

1. Page header uses the compact `AppShell` default.
2. Primary content starts quickly; avoid large intro copy blocks.
3. Lists are compact, scan-friendly, and visually separated from detail panes.
4. Filters are collapsed/condensed by default.
5. No bulk action is shown unless the user explicitly requested that workflow.
6. Metadata displays names first and IDs only as fallback/debug information.

## Testing strategy

- **Component/UI**: Vitest + Testing Library under `frontend/tests/components`
- **E2E smoke**: Playwright under `frontend/tests/e2e`
- **Backend integration**: FastAPI route tests in `tests/`

During migration, each new route should ideally have:

- one backend route/API test if backend behavior changed
- one component or page-level UI test
- one smoke assertion in Playwright if the route is user-facing
