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

`admin/Maintenance.svelte` uses the preferred grouped layout, but its labels still say worker jobs even when the backend redirects some actions to durable commands.

| Action | GUI location | Backend route/controller | Durable path | Recommendation |
| --- | --- | --- | --- | --- |
| Run worker recovery now | Admin Maintenance / Recovery | `POST admin/maintenance/recover-worker-jobs` -> `MaintenanceController::recoverWorkerJobs` | `worker_jobs` recovery only | Keep temporarily until all `worker_jobs` rows are retired; consider adding pipeline actor recovery beside it |
| Start poll reconciliation | Admin Maintenance / Maintenance commands | `POST admin/maintenance/worker-jobs` type `poll` | Redirected to durable `poll_reconciliation` command | Keep |
| Start forced poll reconciliation | Admin Maintenance / Maintenance commands | same route, `force=1` | Durable `poll_reconciliation` command | Keep |
| Start full reindex | Admin Maintenance / Maintenance worker jobs | `POST admin/maintenance/worker-jobs` type `reindex` | Redirected to durable `reindex` command | Keep, but rename/reword as command action |
| Start OCR reindex | Admin Maintenance / Maintenance commands | `POST admin/maintenance/worker-jobs` type `reindex_ocr` | Durable `reindex_ocr` command + `RunPythonActorJob::reindexOcr` | Keep; backend unified on 2026-06-08 |
| Start OCR reindex force | Admin Maintenance / Maintenance commands | same route, `force=1` | Durable `reindex_ocr` command + `RunPythonActorJob::reindexOcr` | Keep; backend unified on 2026-06-08 |
| Start embedding index build | Admin Maintenance / Maintenance commands | `POST admin/maintenance/worker-jobs` type `reindex_embed` | Redirected to durable embedding build command | Keep |
| Mark embedding index stale | Admin Maintenance / Embedding gate | `POST embedding-index/mark-stale` | Durable embedding gate state + audit | Keep |
| Queue document pipeline | Admin Maintenance / Manual document pipeline | `POST admin/maintenance/document-pipeline` | `DocumentPipelineStarter` / `pipeline_runs` | Keep |

### Control Center / Worker Jobs page

`worker/Index.svelte` used to combine durable command visibility, quick controls, document processing, and temporary `worker_jobs` rows. Duplicate launchers have been removed. The next route-level cleanup is to replace the user-facing `/worker-jobs` surface with a unified Operations Log, without introducing a `/legacy-worker-jobs` replacement route.

| Action | GUI location | Backend route/controller | Durable path | Duplication / issue | Recommendation |
| --- | --- | --- | --- | --- | --- |
| Run poll reconciliation | Removed Control Center quick controls | `POST maintenance/poll` | Durable command | Duplicated Dashboard and Maintenance | Removed from Control Center; keep in Dashboard/Maintenance |
| Run forced poll reconciliation | Removed Control Center quick controls | `POST maintenance/poll` with `force=1` | Durable command | Needed a Maintenance replacement | Added to Maintenance and removed from Control Center |
| Queue all-document reindex command | Removed Control Center quick controls | `POST maintenance/reindex` | Durable command | Duplicated Dashboard and Maintenance | Removed from Control Center; keep in Dashboard/Maintenance |
| Queue OCR reindex command | Removed Control Center quick controls | `POST worker-jobs` type `reindex_ocr` | Durable `reindex_ocr` command + `RunPythonActorJob::reindexOcr` | Duplicated Maintenance | Removed from Control Center; keep in Maintenance |
| Queue forced OCR reindex command | Removed Control Center quick controls | `POST worker-jobs` type `reindex_ocr`, `force=1` | Durable `reindex_ocr` command + `RunPythonActorJob::reindexOcr` | Duplicated Maintenance | Removed from Control Center; keep in Maintenance |
| Queue embedding index build command | Removed Control Center quick controls | `POST embedding-index/build` | Durable command | Duplicated Dashboard and Maintenance | Removed from Control Center; keep in Dashboard/Maintenance |
| Mark embedding index stale | Removed Control Center quick controls | `POST embedding-index/mark-stale` | Durable state/action | Duplicated Dashboard; missing from Maintenance | Added to Maintenance and removed from Control Center |
| Process document ID | Removed Control Center form | `POST worker-jobs` type `process_document` | Redirected to `DocumentPipelineStarter` / pipeline run | Useful action with legacy naming | Added target-language Maintenance action and removed from Control Center |
| Generic worker job type selector | Removed Control Center form | `POST worker-jobs` with allowed types | Mixed durable/legacy | Duplicated all specific controls and exposed implementation terms | Removed from Control Center |
| Stop worker job | Temporary worker rows list | `POST worker-jobs/{id}/stop` | Legacy worker row mutation | Required only for active legacy rows | Keep as long as active legacy worker rows can exist |
| Retry whole worker job | Temporary worker rows list | `POST worker-jobs/{id}/retry` | Migrates some types to pipeline/commands; legacy for OCR | Required for historical/active legacy rows | Keep as detail/history action until no legacy rows remain |
| Retry failed documents only | Temporary worker rows list | `POST worker-jobs/{id}/retry` with `failed_only=1` | Migrates process-doc to pipeline; legacy for OCR | Required for historical/active legacy rows | Keep as detail/history action until no legacy rows remain |

The useful, non-duplicated part of Control Center is the combined operational log/history view:

- recent durable commands (`commands` list);
- temporary `worker_jobs` rows and their logs;
- worker job detail pages with payload/progress/result/logs/retry lineage/audit entries.

This supports the maintainer observation that the job log is useful there. It should be preserved as normalized operational history, not as a new legacy-worker-job product surface.

### Pipeline runs pages

`pipeline-runs/Index.svelte` and `pipeline-runs/Show.svelte` expose target durable controls:

| Action | GUI location | Backend route/controller | Recommendation |
| --- | --- | --- | --- |
| Retry run | Pipeline runs index/show | `PipelineRunController::retry` | Keep |
| Retry failed items | Pipeline runs index/show | `PipelineRunController::retryFailedItems` | Keep |
| Cancel run | Pipeline runs index/show | `PipelineRunController::cancel` | Keep |
| Linked worker jobs | Pipeline run show | `PipelineRunController::linkedWorkerJobs` | Keep temporarily for migration/historical context; remove once `worker_jobs` is fully retired |

### Errors and webhook pages

| Action | GUI location | Backend route/controller | Recommendation |
| --- | --- | --- | --- |
| Retry whole worker job | Errors page, failed worker job section | `WorkerJobController::retry` | Keep temporarily while worker failures can exist; later replace with pipeline/actor failures only |
| Retry failed worker documents only | Errors page | `WorkerJobController::retry` | Keep temporarily for historical/legacy rows |
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
| Poll | `archibot poll [--force]` | Python CLI path | GUI creates durable `poll_reconciliation` commands | Needs follow-up parity review before changing CLI behavior |
| Full reindex | `archibot reindex` | Python CLI path | GUI creates durable `reindex` command | Needs follow-up parity review before changing CLI behavior |
| OCR reindex | `archibot reindex-ocr [--force]` | Python CLI path currently runs direct OCR logic | GUI creates durable `reindex_ocr` command | Follow-up: make CLI delegate to Laravel durable command creation; no direct operator mode |
| Embedding reindex | `archibot reindex-embed` | Python CLI path | GUI creates durable embedding build command | Needs follow-up parity review; may be operator/debug only after actor path is canonical |
| Process document | `archibot process-doc <id> [--force]` | Python CLI path / worker-compatible | GUI manual processing starts pipeline runs through Maintenance | Needs follow-up parity review before changing CLI behavior |
| Worker jobs list/status | `archibot jobs list/status` | Read-only SQLite/Laravel DB adapter in `app/cli.py` | Worker detail UI still exists | Keep read-only for legacy visibility while worker rows exist |
| Worker jobs stop/retry | `archibot jobs stop/retry` | Code prints deprecation/read-only message | GUI still mutates via Laravel admin routes | Docs in `docs/developer/cli.md` are stale and should be corrected |
| Pipeline actor recovery | `php artisan archibot:recovery-scan` | Laravel service dispatches actor jobs | No direct GUI button found | Consider adding to Maintenance as target recovery action |
| Worker job recovery | `php artisan worker-jobs:recover` | WorkerJobRecovery | GUI Maintenance button exists | Keep temporarily |
| Cancel stale worker jobs | `php artisan worker-jobs:cancel-stale` | StaleWorkerJobCanceller | Indirect in UI through recovery/index controller | Keep temporarily |

## Worker Jobs references and suspected remaining dependencies

Worker Jobs are not yet removable everywhere. The audit found active references in these categories:

- **Models/tables/factories/migrations:** `WorkerJob`, `WorkerJobLog`, `worker_jobs`, `worker_job_logs` still exist and are referenced by tests and reset/prune code.
- **Runtime execution:** `RunPythonWorkerJob`, `WorkerJobDispatcher`, `PythonWorkerCommand`, `WorkerResultIngestor`, and `WorkerJobRecovery` still support legacy flows.
- **Former legacy flow:** `reindex_ocr` no longer creates productive `worker_jobs` rows from Maintenance/Control Center. New GUI requests create durable `reindex_ocr` commands and dispatch `RunPythonActorJob::reindexOcr`, matching the technical default used by poll, full reindex, and embedding builds.
- **Historical visibility:** dashboard/errors/stats/pipeline detail pages still show or link legacy worker rows.
- **Review/entity compatibility:** existing review suggestions and entity approvals may still link to worker job IDs for Python-origin or legacy sync paths.
- **Health/readiness:** `/healthz`, dashboard readiness, and worker recovery settings still check stale/failed worker jobs.

Conclusion: **do not remove the `worker_jobs` backend/table everywhere yet**. OCR reindex is no longer the productive exception, but `worker_jobs` is still required for historical/active compatibility and old-row stop/retry behavior until those actions are normalized. However, do not preserve or create user-facing `/worker-jobs` or `/legacy-worker-jobs` routes as long-term surfaces; expose any still-needed historical information through Operations Log terminology and durable command/pipeline/actor views.

## Duplicate/obsolete candidates

These were the conservative cleanup candidates. The GUI launcher consolidation candidates have now been implemented; backend `worker_jobs` retirement remains gated by the prerequisites below.

### Candidate 0: unify OCR reindex backend

Before removing duplicate Control Center actions, implement OCR reindex on the same durable technical default as the other migrated operations:

- add a durable command type for OCR reindex, for example `ocr_reindex` or `reindex_ocr`;
- add an allowlisted Laravel queued actor wrapper, analogous to `RunPythonActorJob::reindex(<command-id>)` and `RunPythonActorJob::embeddingIndexBuild(<command-id>)`;
- add a fixed Python actor-runner contract, for example `python -m app.actor_runner reindex-ocr --command-id <commands.id>`;
- persist OCR reindex progress through `commands`, `pipeline_runs` / `pipeline_items` where appropriate, `pipeline_events`, and `actor_executions`, not through new productive `worker_jobs` rows;
- move `MaintenanceCommandDispatcher` / Maintenance GUI OCR actions to create this durable command instead of calling `WorkerJobDispatcher`;
- make Control Center retry/visibility treat old OCR worker rows as legacy history only;
- update CLI parity so `archibot reindex-ocr [--force]` delegates to the same Laravel/durable command path; do not keep a separate direct operator entrypoint.

Rationale: the maintainer wants a unified backend and OCR reindex appears to be the only remaining productive GUI action still based on `worker_jobs`.

Risk: this is an implementation change, not just UI cleanup. It needs focused Laravel/Python tests for command creation, actor dispatch, force payload propagation, progress/state, and GUI action paths.

### Candidate A: remove duplicate Quick Controls from Control Center

Remove these quick-control buttons from `worker/Index.svelte` after the Maintenance page has equivalent preferred controls:

- Run poll reconciliation
- Queue all-document reindex command
- Queue OCR reindex command
- Queue forced OCR reindex command
- Queue embedding index build command
- Mark embedding index stale, but only after adding it to Maintenance

Rationale: these controls duplicate Dashboard/Maintenance operations, and the maintainer prefers the Maintenance layout/grouping.

Risk: forced poll and mark-stale are currently visible in Control Center/Dashboard but not as explicit Maintenance actions. Add or preserve them before removal.

### Candidate B: replace Control Center with Operations Log, not legacy worker pages

After Candidate A, replace the user-facing Control Center / Worker Jobs surface with **Operations Log**:

- route should be `/operations-log`, not `/worker-jobs`;
- do not introduce `/operations-log/legacy-worker-jobs/{id}` or any `/legacy-worker-jobs` route;
- durable commands, pipeline runs/events, actor executions, webhook deliveries and audit entries should be the primary records;
- any still-needed old `worker_jobs` data should appear only as normalized archived operation details, without making "Legacy Worker Jobs" a product/navigation concept.

Rationale: preserves useful job/log history while preventing the temporary `worker_jobs` model from becoming the new user-facing architecture.

Risk: route and route-helper changes affect navigation, errors/review/audit links, tests and docs; implement as a focused route/UI migration.

### Candidate C: move manual document processing out of generic Worker Job form

Replace Control Center's generic `process_document` form with a more target-language action such as **Start document pipeline** in Maintenance or Pipeline Runs.

Rationale: backend already starts a durable pipeline run; the UI should not require choosing a `worker_jobs` type.

Risk: route currently lives in `WorkerJobController::store`; moving it should preserve admin authorization, force-new-run semantics, and redirect to the pipeline run.

### Candidate D: remove generic worker-job type selector

Once specific actions exist in preferred locations, remove the generic type selector from Control Center.

Rationale: it exposes implementation terms and duplicates specific controls.

Risk: may be used for quick manual testing of OCR reindex; implement durable OCR reindex and preserve OCR-specific Maintenance cards first.

### Candidate E: update stale CLI documentation

Update `docs/developer/cli.md` so `archibot jobs stop` and `archibot jobs retry` are documented as deprecated/read-only, matching `app/cli.py` and `docs/architecture/job-control-model.md`.

Rationale: CLI/action naming parity is part of the todo and the current doc contradicts the code.

Risk: documentation-only, low risk.

## Recommended staged implementation plan

### Stage 1: unify OCR reindex first

Implemented on 2026-06-08 for the Laravel GUI/backend: Maintenance and former Control Center OCR reindex submissions now create durable `reindex_ocr` commands and dispatch the fixed OCR reindex actor. Follow-up: make the operator-facing Python CLI delegate to Laravel durable command creation as the only supported operator path.

### Stage 2: small documentation fix and Maintenance gap closure

Implemented on 2026-06-08:

1. `docs/developer/cli.md` documents deprecated/read-only `archibot jobs stop/retry` behavior.
2. Maintenance now exposes the previously missing actions:
   - Mark embedding index stale.
   - Forced poll reconciliation.
   - Manual document pipeline start with optional forced reprocess.
3. Maintenance labels use command/pipeline language instead of productive `worker_jobs` language.
4. Legacy worker recovery/status remain explicitly scoped to temporary worker rows.

### Stage 3: remove duplicate action launchers from Control Center

Implemented on 2026-06-08. The Control Center no longer exposes duplicate quick controls, the manual process-document worker form, or the generic worker-job type selector. It preserves:

- durable command list;
- temporary worker row list;
- links to worker detail/logs;
- legacy stop/retry actions for existing rows.

### Stage 4: replace `/worker-jobs` with Operations Log

Planned next route/UI cleanup:

1. Add a user-facing `/operations-log` route and navigation label **Operations Log**.
2. Remove the user-facing `/worker-jobs` route instead of keeping it as a compatibility URL.
3. Do not add `/legacy-worker-jobs` or `/operations-log/legacy-worker-jobs/{id}`.
4. Normalize still-needed old worker-row visibility into Operations Log entries/details using generic operation language such as source `legacy`, not product language such as "Worker Jobs".
5. Keep backend `worker_jobs` models/tables only as temporary compatibility storage until the Stage 5 retirement prerequisites are satisfied.

### Stage 5: retire Worker Jobs fully only after prerequisites

Do not remove backend/routes/controllers/models until all prerequisites are true:

- durable OCR reindex actor exists and has GUI/CLI parity;
- no current code creates productive `worker_jobs` rows, including OCR reindex;
- historical worker rows either have an accepted migration/archival strategy or the UI can safely ignore them;
- stats/errors/dashboard/health/review/entity/pipeline links no longer depend on `WorkerJob`;
- reset/prune/recovery docs are updated;
- tests prove equivalent actions remain available through commands/pipeline/actors.

## Remaining follow-ups

1. Make operator-facing `archibot reindex-ocr [--force]` delegate to Laravel durable command creation; do not keep a direct operator mode.
2. Add a Maintenance pipeline actor recovery button for `php artisan archibot:recovery-scan`.
3. Replace `/worker-jobs` with `/operations-log` and do not introduce `/legacy-worker-jobs` routes.
4. Retire the `worker_jobs` backend/table only after the Stage 5 prerequisites are satisfied.
