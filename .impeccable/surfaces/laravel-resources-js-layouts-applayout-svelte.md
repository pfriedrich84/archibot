---
version: 1
slug: "laravel-resources-js-layouts-applayout-svelte"
primary_target: "laravel/resources/js/layouts/AppLayout.svelte"
related_targets: ["laravel/resources/js/pages/Dashboard.svelte","laravel/resources/js/pages/review/Index.svelte","laravel/resources/js/pages/review/Show.svelte","laravel/resources/js/pages/admin/Maintenance.svelte","laravel/resources/js/pages/admin/Settings.svelte"]
---

# ArchiBot application shell and core workflows

## Scope and mode

Operate mode across the authenticated application, with the daily review path as the primary surface and the admin control layer as a secondary, conventional utility surface.

## Audience, job, and action

A self-hosting Paperless owner clears incoming document reviews quickly. The interface should slow them down only when approving new tags, correspondents, or document types. The primary action is to inspect the current document, resolve its changed metadata, and move directly to the next item.

## Constraints

Preserve every existing route and backend action, authorization boundary, durable state, and user-facing capability. Safety may be strengthened without bypassing the canonical endpoint: the embedding gate requires exact typed confirmation in both UI and controller. Keep OCR local-only and keep model output unable to commit. Technical evidence remains available but progressively disclosed. Keyboard, screen-reader, zoom, narrow-width, light, and dark use must remain viable.

## Direction

The Transfer Register: mineral-white document stock, archival green dividers, oxide action marks, crisp ledger rules, and one visually dominant active document. Blend it with a polished conventional admin grammar for dense operations. Navigation keeps Review and Library visible while Admin tools discloses Monitor and Configure. Routine content is open; evidence, advanced controls, recovery, and danger are disclosed only when needed.

## Memorable moment

The review workspace feels like opening one precise document sleeve: preview and proposed changes stay together, unchanged facts recede, and the next safe action is always visible.

## Shipped workflow behavior

Accepting or rejecting a suggestion advances to the next visible pending review and falls back to the queue when none remains. The embedding-gate shutdown requires exact typed confirmation. “Transfer Register” remains a visual and structural metaphor rather than mandatory user-facing terminology.
