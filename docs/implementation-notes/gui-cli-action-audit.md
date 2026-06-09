# GUI/CLI action audit: Control Center, Maintenance, and worker jobs

Date: 2026-06-08

Related todo: `TODO-d471d631`.

## Purpose

This started as the required audit/plan before removing duplicate or obsolete GUI/CLI actions. It now records the implemented consolidation decisions and the remaining backend retirement prerequisites.

## Source files inspected

Primary GUI and route files:

- `laravel/routes/web.php`
- `laravel/resources/js/components/AppSidebar.svelte`
- `laravel/resources/js/pages/Dashboard.svelte`
- `laravel/resources/js/pages/admin/Maintenance.svelte`
- `laravel/resources/js/pages/worker/Index.svelte`
- `laravel/resources/js/pages/worker/Show.svelte`
- `laravel/resources/js/pages/pipeline-runs/Index.svelte`
- `laravel/resources/js/pages/pipeline-runs/Show.svelte`
- `laravel/resources/js/pages/processing/Embeddings.svelte`
- `laravel/resources/js/pages/diagnostics/Errors.svelte`
- `laravel/resources/js/pages/webhooks/Index.svelte`
- `laravel/resources/js/pages/webhooks/Show.svelte`
- `laravel/resources/js/pages/review/Show.svelte`

Primary backend/CLI files:

- `laravel/app/Http/Controllers/DashboardController.php`
- `laravel/app/Http/Controllers/Admin/MaintenanceController.php`
- `laravel/app/Http/Controllers/MaintenanceCommandController.php`
- `laravel/app/Http/Controllers/EmbeddingIndexController.php`
- `laravel/app/Http/Controllers/Workers/WorkerJobController.php`
- `laravel/app/Http/Controllers/PipelineRunController.php`
- `laravel/app/Http/Controllers/WebhookDeliveryController.php`
- `laravel/app/Http/Controllers/ErrorsController.php`
- `laravel/app/Console/Commands/ArchibotReset.php`
- `laravel/app/Console/Commands/RecoverPipelineActors.php`
- `laravel/app/Console/Commands/RecoverWorkerJobs.php`
- `laravel/app/Console/Commands/CancelStaleWorkerJobs.php`
- `app/cli.py`

Relevant docs/decisions:

- `docs/architecture/job-control-model.md`
- `docs/decisions/0012-worker-jobs-as-temporary-control-plane.md`
- `docs/operations/event-driven-pipeline.md`
- `docs/developer/worker-jobs.md`
- `docs/developer/cli.md`

## Current action inventory

### Dashboard operations

`Dashboard.svelte` is already an event-driven operations surface for the main actions:

| Action | GUI location | Backend route/controller | Durable path | Recommendation |
| --- | --- | --- | --- | --- |
| Start/resume embedding build | Dashboard embedding section | `POST embedding-index/build` -> `EmbeddingIndexController::build` | `commands` + `RunPythonActorJob::embeddingIndexBuild` | Keep |
| Mark embedding index stale | Dashboard embedding section | `POST embedding-index/mark-stale` -> `EmbeddingIndexController::markStale` | `embedding_index_state` + audit | Keep |
| Run poll now | Dashboard maintenance section | `POST maintenance/poll` -> `MaintenanceCommandController::poll` | `commands` + poll actor | Keep |
| Start reindex | Dashboard maintenance section | `POST maintenance/reindex` -> `MaintenanceCommandController::reindex` | `commands` + reindex actor | Keep |
| Retry pipeline run | Dashboard recent pipeline runs | `POST pipeline-runs/{id}/retry` | `pipeline_runs` | Keep |
| Retry failed pipeline items | Dashboard recent pipeline runs | `POST pipeline-runs/{id}/retry-failed-items` | `pipeline_runs` / `pipeline_items` | Keep |
| Cancel pipeline run | Dashboard recent pipeline runs | `POST pipeline-runs/{id}/cancel` | `pipeline_runs` | Keep |
| Retry webhook delivery | Dashboard recent webhook deliveries | `POST webhook-deliveries/{id}/retry` | `webhook_deliveries` + actor dispatch | Keep |
| Dismiss webhook failure | Dashboard recent webhook deliveries | `POST webhook-deliveries/{id}/dismiss` | `webhook_deliveries` | Keep |

### Maintenance page

`admin/Maintenance.svelte` is the preferred grouped admin action-launch surface. Labels and routes use command/pipeline/recovery language instead of worker-job terminology.

| Action | GUI location | Backend route/controller | Durable path | Recommendation |
| --- | --- | --- | --- | --- |
| Run durable recovery scan now | Admin Maintenance / Recovery | `POST admin/maintenance/recover-pipeline-actors` -> `MaintenanceController::recoverPipelineActors` | Durable commands, pipeline runs, webhook deliveries and actor jobs | Keep |
| Start poll reconciliation | Admin Maintenance / Maintenance commands | `POST admin/maintenance/commands` type `poll` | Durable `poll_reconciliation` command | Keep |
| Start forced poll reconciliation | Admin Maintenance / Maintenance commands | same route, `force=1` | Durable `poll_reconciliation` command | Keep |
| Start full reindex | Admin Maintenance / Maintenance commands | `POST admin/maintenance/commands` type `reindex` | Durable `reindex` command | Keep |
| Start OCR reindex | Admin Maintenance / Maintenance commands | `POST admin/maintenance/commands` type `reindex_ocr` | Durable `reindex_ocr` command + `RunPythonActorJob::reindexOcr` | Keep |
| Start OCR reindex force | Admin Maintenance / Maintenance commands | same route, `force=1` | Durable `reindex_ocr` command + `RunPythonActorJob::reindexOcr` | Keep |
| Start embedding index build | Admin Maintenance / Maintenance commands | `POST admin/maintenance/commands` type `reindex_embed` | Durable embedding build command | Keep |
| Mark embedding index stale | Admin Maintenance / Embedding gate | `POST embedding-index/mark-stale` | Durable embedding gate state + audit | Keep |
| Queue document pipeline | Admin Maintenance / Manual document pipeline | `POST admin/maintenance/document-pipeline` | `DocumentPipelineStarter` / `pipeline_runs` | Keep |

### Control Center / Worker Jobs page

`worker/Index.svelte` used to combine durable command visibility, quick controls, document processing, and `worker_jobs` rows. It has been removed. The useful history value moved to `/operations-log`, backed only by durable event-driven tables, without introducing a `/legacy-worker-jobs` replacement route.

| Action | GUI location | Backend route/controller | Durable path | Duplication / issue | Recommendation |
| --- | --- | --- | --- | --- | --- |
| Run poll reconciliation | Removed Control Center quick controls | `POST maintenance/poll` | Durable command | Duplicated Dashboard and Maintenance | Removed from Control Center; keep in Dashboard/Maintenance |
| Run forced poll reconciliation | Removed Control Center quick controls | `POST maintenance/poll` with `force=1` | Durable command | Needed a Maintenance replacement | Added to Maintenance and removed from Control Center |
| Queue all-document reindex command | Removed Control Center quick controls | `POST maintenance/reindex` | Durable command | Duplicated Dashboard and Maintenance | Removed from Control Center; keep in Dashboard/Maintenance |
| Queue OCR reindex command | Removed Control Center quick controls | Removed; Maintenance uses `POST admin/maintenance/commands` type `reindex_ocr` | Durable `reindex_ocr` command + `RunPythonActorJob::reindexOcr` | Duplicated Maintenance | Removed from Control Center; keep in Maintenance |
| Queue forced OCR reindex command | Removed Control Center quick controls | Removed; Maintenance uses `POST admin/maintenance/commands` type `reindex_ocr`, `force=1` | Durable `reindex_ocr` command + `RunPythonActorJob::reindexOcr` | Duplicated Maintenance | Removed from Control Center; keep in Maintenance |
| Queue embedding index build command | Removed Control Center quick controls | `POST embedding-index/build` | Durable command | Duplicated Dashboard and Maintenance | Removed from Control Center; keep in Dashboard/Maintenance |
| Mark embedding index stale | Removed Control Center quick controls | `POST embedding-index/mark-stale` | Durable state/action | Duplicated Dashboard; missing from Maintenance | Added to Maintenance and removed from Control Center |
| Process document ID | Removed Control Center form | Removed; Maintenance uses `POST admin/maintenance/document-pipeline` | `DocumentPipelineStarter` / pipeline run | Useful action with legacy naming | Added target-language Maintenance action and removed from Control Center |
| Generic worker job type selector | Removed Control Center form | Removed | N/A | Duplicated all specific controls and exposed implementation terms | Removed from Control Center |
| Stop worker job | Removed worker rows list | Removed | N/A | Superseded by clean-state removal | Removed with `/worker-jobs` and worker backend |
| Retry whole worker job | Removed worker rows list | Removed | N/A | Superseded by durable retry controls | Removed with `/worker-jobs` and worker backend |
| Retry failed documents only | Removed worker rows list | Removed | N/A | Superseded by durable retry controls | Removed with `/worker-jobs` and worker backend |

The useful, non-duplicated part of Control Center is operational history. Preserve that value through `/operations-log` backed by durable tables only:

- recent durable commands;
- pipeline runs and events;
- actor executions;
- webhook delivery history;
- audit entries.

Do not preserve old worker-job rows, detail pages, retry lineage, or logs. Runtime state will be cleaned before install, so no historical worker-job migration is required.

### Pipeline runs pages

`pipeline-runs/Index.svelte` and `pipeline-runs/Show.svelte` expose target durable controls:

| Action | GUI location | Backend route/controller | Recommendation |
| --- | --- | --- | --- |
| Retry run | Pipeline runs index/show | `PipelineRunController::retry` | Keep |
| Retry failed items | Pipeline runs index/show | `PipelineRunController::retryFailedItems` | Keep |
| Cancel run | Pipeline runs index/show | `PipelineRunController::cancel` | Keep |
| Linked worker jobs | Pipeline run show | `PipelineRunController::linkedWorkerJobs` | Remove; no worker-job compatibility after clean-state retirement |

### Errors and webhook pages

| Action | GUI location | Backend route/controller | Recommendation |
| --- | --- | --- | --- |
| Retry whole worker job | Errors page, failed worker job section | `WorkerJobController::retry` | Remove with worker-job backend; errors should target pipeline/actor/webhook failures |
| Retry failed worker documents only | Errors page | `WorkerJobController::retry` | Remove with worker-job backend |
| Retry webhook delivery | Errors, webhooks index/show | `WebhookDeliveryController::retry` | Keep |
| Dismiss webhook failure | Errors, webhooks index/show | `WebhookDeliveryController::dismiss` | Keep |

### Review page

| Action | GUI location | Backend route/controller | Durable path | Recommendation |
| --- | --- | --- | --- | --- |
| Force/manual reprocess | Review detail | `ReviewSuggestionController::reprocess` | `DocumentPipelineStarter` / pipeline run | Keep |
| Accept/reject/save/bulk actions | Review pages | `ReviewSuggestionController` | Review + commands for event-driven commits | Out of scope for duplicate operations cleanup; keep |

### CLI and Artisan actions

| Action | CLI command | Current implementation | GUI/backend parity | Recommendation |
| --- | --- | --- | --- | --- |
| Reset | `archibot reset --yes` | Delegates to `php artisan archibot:reset --yes` | CLI-only by design | Keep |
| Poll | `archibot poll [--force]` | Delegates to `php artisan archibot:maintenance-command poll` | Same durable `poll_reconciliation` command as Maintenance | Done |
| Full reindex | `archibot reindex` | Delegates to `php artisan archibot:maintenance-command reindex` | Same durable `reindex` command as Maintenance | Done |
| OCR reindex | `archibot reindex-ocr [--force]` | Delegates to `php artisan archibot:maintenance-command reindex_ocr` | Same durable `reindex_ocr` command as Maintenance; no direct operator mode | Done |
| Embedding reindex | `archibot reindex-embed` | Delegates to `php artisan archibot:maintenance-command reindex_embed` | Same durable embedding build command as Maintenance | Done |
| Process document | `archibot process-doc <id> [--force]` | Delegates to `php artisan archibot:maintenance-command process_document --document-id=<id>` | Same durable manual pipeline-run path as Maintenance | Done |
| Worker jobs list/status | `archibot jobs list/status` | Removed from `app/cli.py` | No Worker Jobs UI remains | Done |
| Worker jobs stop/retry | `archibot jobs stop/retry` | Removed from `app/cli.py` | No Worker Jobs controls remain | Done |
| Pipeline actor recovery | `php artisan archibot:recovery-scan` | Laravel service dispatches durable actor jobs | Maintenance exposes a recovery-scan button | Done |
| Worker job recovery | `php artisan worker-jobs:recover` | Removed | No GUI/backend compatibility | Done |
| Cancel stale worker jobs | `php artisan worker-jobs:cancel-stale` | Removed | No GUI/backend compatibility | Done |

## Worker Jobs references and suspected remaining dependencies

Worker Jobs are now planned for clean-state removal. The audit found active references that must be removed rather than preserved for compatibility:

- **Models/tables/factories/migrations:** `WorkerJob`, `WorkerJobLog`, `worker_jobs`, `worker_job_logs` have been removed for clean installs.
- **Runtime execution:** `RunPythonWorkerJob`, `WorkerJobDispatcher`, `PythonWorkerCommand`, `WorkerResultIngestor`, and `WorkerJobRecovery` have been removed; productive work uses fixed actor jobs and durable command/pipeline state.
- **Migrated flow:** `reindex_ocr` no longer creates productive `worker_jobs` rows from Maintenance/Control Center. GUI requests create durable `reindex_ocr` commands and dispatch `RunPythonActorJob::reindexOcr`, matching the technical default used by poll, full reindex, and embedding builds.
- **Visibility references:** dashboard/errors/stats/pipeline detail pages no longer show or link worker rows; operator history is durable-only through `/operations-log`, `/pipeline-runs`, webhook delivery pages and audit logs.
- **Review/entity references:** review commits and entity approval syncs now use durable commands/actors rather than worker-job rows.
- **Health/readiness:** `/healthz`, dashboard readiness and recovery controls no longer check stale/failed worker jobs; recovery targets durable commands, pipeline runs, webhook deliveries and actor executions.

Conclusion: the `worker_jobs` backend/table/routes/controllers/jobs/recovery/results/tests have been removed as a clean-install breaking cleanup. Do not migrate old rows, do not keep backend compatibility, and do not create `/worker-jobs` or `/legacy-worker-jobs` surfaces.

## Duplicate/obsolete candidates

These were the conservative cleanup candidates. The GUI launcher consolidation candidates have now been implemented; backend `worker_jobs` retirement is now a clean-install removal, not a compatibility migration.

### Candidate 0: unify OCR reindex backend

Implemented before removing duplicate Control Center actions. OCR reindex now uses the same durable technical default as the other migrated operations:

- durable command type `reindex_ocr`;
- allowlisted Laravel queued actor wrapper `RunPythonActorJob::reindexOcr(<command-id>)`;
- fixed Python actor-runner contract `python -m app.actor_runner reindex-ocr --command-id <commands.id>`;
- OCR force/limit options loaded from `commands.payload`;
- progress/state recorded through durable command, pipeline event and actor execution tables rather than productive worker-job rows;
- Maintenance GUI and operator-facing `archibot reindex-ocr [--force]` both delegate to the Laravel durable command path;
- no direct OCR operator entrypoint or old OCR worker visibility remains.

Rationale: the maintainer wants one unified backend for GUI and CLI actions.

### Candidate A: remove duplicate Quick Controls from Control Center

Remove these quick-control buttons from `worker/Index.svelte` after the Maintenance page has equivalent preferred controls:

- Run poll reconciliation
- Queue all-document reindex command
- Queue OCR reindex command
- Queue forced OCR reindex command
- Queue embedding index build command
- Mark embedding index stale, but only after adding it to Maintenance

Rationale: these controls duplicate Dashboard/Maintenance operations, and the maintainer prefers the Maintenance layout/grouping.

Result: forced poll and mark-stale are explicit Maintenance actions before Control Center removal.

### Candidate B: replace Control Center with Operations Log, not legacy worker pages

After Candidate A, replace the user-facing Control Center / Worker Jobs surface with **Operations Log**:

- route should be `/operations-log`, not `/worker-jobs`;
- do not introduce `/operations-log/legacy-worker-jobs/{id}` or any `/legacy-worker-jobs` route;
- durable commands, pipeline runs/events, actor executions, webhook deliveries and audit entries should be the primary records;
- do not show old `worker_jobs` data; clean runtime state means there is no historical data to preserve.

Rationale: preserves useful job/log history while preventing the temporary `worker_jobs` model from becoming the new user-facing architecture.

Result: route and route-helper changes were implemented as a focused route/UI migration and covered by tests/CI.

### Candidate C: move manual document processing out of generic Worker Job form

Replace Control Center's generic `process_document` form with a more target-language action such as **Start document pipeline** in Maintenance or Pipeline Runs.

Rationale: backend already starts a durable pipeline run; the UI should not require choosing a `worker_jobs` type.

Result: route now lives in Maintenance, preserves admin authorization, force-new-run semantics, and redirects to the pipeline run.

### Candidate D: remove generic worker-job type selector

Once specific actions exist in preferred locations, remove the generic type selector from Control Center.

Rationale: it exposes implementation terms and duplicates specific controls.

Result: durable OCR reindex and OCR-specific Maintenance cards exist; the generic selector is removed.

### Candidate E: update stale CLI documentation

Implemented. `docs/developer/cli.md` documents that GUI-overlapping CLI commands delegate to Laravel Maintenance; removed `archibot jobs ...` compatibility is no longer documented as an available operator command.

## Recommended staged implementation plan

### Stage 1: unify OCR reindex first

Implemented. Maintenance and former Control Center OCR reindex submissions create durable `reindex_ocr` commands and dispatch the fixed OCR reindex actor. The operator-facing CLI also delegates to the same Laravel durable command path; there is no direct OCR operator mode.

### Stage 2: small documentation fix and Maintenance gap closure

Implemented on 2026-06-08:

1. `docs/developer/cli.md` documents durable Laravel Maintenance delegation for GUI-overlapping CLI commands and no longer documents `archibot jobs ...` compatibility.
2. Maintenance now exposes the previously missing actions:
   - Mark embedding index stale.
   - Forced poll reconciliation.
   - Manual document pipeline start with optional forced reprocess.
3. Maintenance labels use command/pipeline language instead of productive `worker_jobs` language.

Refined decision on 2026-06-09: remove worker recovery/status rather than preserving temporary worker rows.

### Stage 3: remove duplicate action launchers from Control Center

Implemented on 2026-06-08. The Control Center no longer exposes duplicate quick controls, the manual process-document worker form, or the generic worker-job type selector. It preserves:

- durable command list;
No worker row list, worker detail/log links, or legacy stop/retry actions should remain after the clean-state cleanup.

### Stage 4: replace `/worker-jobs` with Operations Log

Implemented:

1. Added user-facing `/operations-log` route and navigation label **Operations Log**.
2. Removed the user-facing `/worker-jobs` route instead of keeping it as a compatibility URL.
3. Did not add `/legacy-worker-jobs` or `/operations-log/legacy-worker-jobs/{id}`.
4. Built Operations Log from durable commands, pipeline events/items, actor executions, webhook deliveries and audit logs.
5. Removed backend `worker_jobs` models/tables/controllers/jobs/recovery/result ingestion/tests in the same clean-install cleanup; no compatibility storage is preserved.

### Stage 5: retire Worker Jobs fully as clean-install cleanup

Implemented. Backend/routes/controllers/models/migrations/factories/tests and runtime services were removed after durable replacements existed. No historical worker-row migration or archive is required. Operators will clean runtime state before install.

## Remaining follow-ups

No worker-job retirement follow-ups remain in the implementation scope. Future work should deepen durable pipeline/actor observability and retry controls without reintroducing worker-job compatibility.
