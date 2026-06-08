# GUI/CLI action audit: Control Center, Maintenance, and worker jobs

Date: 2026-06-08

Related todo: `TODO-d471d631`.

## Purpose

This is the required audit/plan before removing duplicate or obsolete GUI/CLI actions. It intentionally makes no code removals. The goal is to identify which actions should stay, move, or be removed after maintainer approval.

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
| Start poll reconciliation | Admin Maintenance / Maintenance worker jobs | `POST admin/maintenance/worker-jobs` type `poll` | Redirected to durable `poll_reconciliation` command | Keep, but rename/reword as command action |
| Start full reindex | Admin Maintenance / Maintenance worker jobs | `POST admin/maintenance/worker-jobs` type `reindex` | Redirected to durable `reindex` command | Keep, but rename/reword as command action |
| Start OCR reindex | Admin Maintenance / Maintenance worker jobs | `POST admin/maintenance/worker-jobs` type `reindex_ocr` | Legacy `worker_jobs` path | Keep only until durable OCR reindex actor exists |
| Start OCR reindex force | Admin Maintenance / Maintenance worker jobs | same route, `force=1` | Legacy `worker_jobs` path | Keep only until durable OCR reindex actor exists |
| Start embedding reindex | Admin Maintenance / Maintenance worker jobs | `POST admin/maintenance/worker-jobs` type `reindex_embed` | Redirected to durable embedding build command | Keep, but rename/reword as embedding index build command |

### Control Center / Worker Jobs page

`worker/Index.svelte` combines durable command visibility, quick controls, document processing, and temporary `worker_jobs` rows. This is where most duplication exists.

| Action | GUI location | Backend route/controller | Durable path | Duplication / issue | Recommendation |
| --- | --- | --- | --- | --- | --- |
| Run poll reconciliation | Control Center quick controls | `POST maintenance/poll` | Durable command | Duplicates Dashboard and Maintenance | Remove from Control Center after approval; keep in Dashboard/Maintenance |
| Run forced poll reconciliation | Control Center quick controls | `POST maintenance/poll` with `force=1` | Durable command | Partly duplicates Maintenance, but Maintenance's poll form does not visibly expose force | Either move force-poll option into Maintenance or keep until Maintenance has it |
| Queue all-document reindex command | Control Center quick controls | `POST maintenance/reindex` | Durable command | Duplicates Dashboard and Maintenance | Remove from Control Center after approval; keep in Dashboard/Maintenance |
| Queue OCR reindex worker | Control Center quick controls | `POST worker-jobs` type `reindex_ocr` | Legacy `worker_jobs` | Duplicates Maintenance | Remove from Control Center after approval; keep in Maintenance while legacy OCR actor gap remains |
| Queue forced OCR reindex worker | Control Center quick controls | `POST worker-jobs` type `reindex_ocr`, `force=1` | Legacy `worker_jobs` | Duplicates Maintenance | Remove from Control Center after approval; keep in Maintenance while legacy OCR actor gap remains |
| Queue embedding index build command | Control Center quick controls | `POST embedding-index/build` | Durable command | Duplicates Dashboard and Maintenance | Remove from Control Center after approval; keep in Dashboard/Maintenance |
| Mark embedding index stale | Control Center quick controls | `POST embedding-index/mark-stale` | Durable state/action | Duplicates Dashboard; missing from current Maintenance page | Move/add to Maintenance before removing from Control Center |
| Process document ID | Control Center form | `POST worker-jobs` type `process_document` | Redirected to `DocumentPipelineStarter` / pipeline run | Useful action, but page name suggests worker job | Move to Maintenance or Pipeline runs as a manual pipeline action before retiring Control Center action |
| Generic worker job type selector | Control Center form | `POST worker-jobs` with allowed types | Mixed durable/legacy | Duplicates all specific controls and exposes implementation terms | Remove after specific replacement actions are available elsewhere |
| Stop worker job | Temporary worker rows list | `POST worker-jobs/{id}/stop` | Legacy worker row mutation | Required only for active legacy rows | Keep as long as active legacy worker rows can exist |
| Retry whole worker job | Temporary worker rows list | `POST worker-jobs/{id}/retry` | Migrates some types to pipeline/commands; legacy for OCR | Required for historical/active legacy rows | Keep as detail/history action until no legacy rows remain |
| Retry failed documents only | Temporary worker rows list | `POST worker-jobs/{id}/retry` with `failed_only=1` | Migrates process-doc to pipeline; legacy for OCR | Required for historical/active legacy rows | Keep as detail/history action until no legacy rows remain |

The useful, non-duplicated part of Control Center is the combined operational log/history view:

- recent durable commands (`commands` list);
- temporary `worker_jobs` rows and their logs;
- worker job detail pages with payload/progress/result/logs/retry lineage/audit entries.

This supports the maintainer observation that the job log is useful there. It should be preserved or moved before removing the Control Center navigation entry.

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
| Poll | `archibot poll [--force]` | Python CLI path | GUI now creates durable `poll_reconciliation` commands | Needs follow-up parity review before any GUI removal; do not assume obsolete |
| Full reindex | `archibot reindex` | Python CLI path | GUI now creates durable `reindex` command | Needs follow-up parity review before any GUI removal; do not assume obsolete |
| OCR reindex | `archibot reindex-ocr [--force]` | Python CLI path / legacy worker-compatible | GUI legacy worker path remains | Keep until durable OCR reindex actor exists |
| Embedding reindex | `archibot reindex-embed` | Python CLI path | GUI now creates durable embedding build command | Needs follow-up parity review; may be operator/debug only after actor path is canonical |
| Process document | `archibot process-doc <id> [--force]` | Python CLI path / worker-compatible | GUI manual processing now starts pipeline runs | Needs follow-up parity review; do not remove blindly |
| Worker jobs list/status | `archibot jobs list/status` | Read-only SQLite/Laravel DB adapter in `app/cli.py` | Worker detail UI still exists | Keep read-only for legacy visibility while worker rows exist |
| Worker jobs stop/retry | `archibot jobs stop/retry` | Code prints deprecation/read-only message | GUI still mutates via Laravel admin routes | Docs in `docs/developer/cli.md` are stale and should be corrected |
| Pipeline actor recovery | `php artisan archibot:recovery-scan` | Laravel service dispatches actor jobs | No direct GUI button found | Consider adding to Maintenance as target recovery action |
| Worker job recovery | `php artisan worker-jobs:recover` | WorkerJobRecovery | GUI Maintenance button exists | Keep temporarily |
| Cancel stale worker jobs | `php artisan worker-jobs:cancel-stale` | StaleWorkerJobCanceller | Indirect in UI through recovery/index controller | Keep temporarily |

## Worker Jobs references and suspected remaining dependencies

Worker Jobs are not yet removable everywhere. The audit found active references in these categories:

- **Models/tables/factories/migrations:** `WorkerJob`, `WorkerJobLog`, `worker_jobs`, `worker_job_logs` still exist and are referenced by tests and reset/prune code.
- **Runtime execution:** `RunPythonWorkerJob`, `WorkerJobDispatcher`, `PythonWorkerCommand`, `WorkerResultIngestor`, and `WorkerJobRecovery` still support legacy flows.
- **Known legacy flow:** `reindex_ocr` still dispatches through `WorkerJobDispatcher` from Maintenance/Control Center. `docs/architecture/job-control-model.md` explicitly says OCR reindex remains legacy until there is a durable OCR reindex actor.
- **Historical visibility:** dashboard/errors/stats/pipeline detail pages still show or link legacy worker rows.
- **Review/entity compatibility:** existing review suggestions and entity approvals may still link to worker job IDs for Python-origin or legacy sync paths.
- **Health/readiness:** `/healthz`, dashboard readiness, and worker recovery settings still check stale/failed worker jobs.

Conclusion: **do not remove `worker_jobs` backend/routes/controllers everywhere yet**. It is still required for at least OCR reindex and historical/active legacy visibility. Removal should be staged after replacing OCR reindex and migrating remaining visibility/diagnostic dependencies.

## Duplicate/obsolete candidates

These are conservative candidates only; each should be confirmed before implementation.

### Candidate A: remove duplicate Quick Controls from Control Center

Remove these quick-control buttons from `worker/Index.svelte` after the Maintenance page has equivalent preferred controls:

- Run poll reconciliation
- Queue all-document reindex command
- Queue OCR reindex worker
- Queue forced OCR reindex worker
- Queue embedding index build command
- Mark embedding index stale, but only after adding it to Maintenance

Rationale: these controls duplicate Dashboard/Maintenance operations, and the maintainer prefers the Maintenance layout/grouping.

Risk: forced poll and mark-stale are currently visible in Control Center/Dashboard but not as explicit Maintenance actions. Add or preserve them before removal.

### Candidate B: keep Control Center as log/history, not action launcher

After Candidate A, retain Control Center if it still provides useful operational logs:

- durable command list;
- temporary worker row list;
- links to worker detail/logs;
- maybe rename it from **Control Center** to **Job history** or **Legacy worker jobs** once it no longer has launch actions.

Rationale: avoids removing the job log the maintainer likes.

Risk: naming change affects navigation/tests/user docs.

### Candidate C: move manual document processing out of generic Worker Job form

Replace Control Center's generic `process_document` form with a more target-language action such as **Start document pipeline** in Maintenance or Pipeline Runs.

Rationale: backend already starts a durable pipeline run; the UI should not require choosing a `worker_jobs` type.

Risk: route currently lives in `WorkerJobController::store`; moving it should preserve admin authorization, force-new-run semantics, and redirect to the pipeline run.

### Candidate D: remove generic worker-job type selector

Once specific actions exist in preferred locations, remove the generic type selector from Control Center.

Rationale: it exposes implementation terms and duplicates specific controls.

Risk: may be used for quick manual testing of legacy OCR reindex; preserve OCR-specific Maintenance cards first.

### Candidate E: update stale CLI documentation

Update `docs/developer/cli.md` so `archibot jobs stop` and `archibot jobs retry` are documented as deprecated/read-only, matching `app/cli.py` and `docs/architecture/job-control-model.md`.

Rationale: CLI/action naming parity is part of the todo and the current doc contradicts the code.

Risk: documentation-only, low risk.

## Recommended staged implementation plan

### Stage 1: small documentation fix and Maintenance gap closure

1. Update `docs/developer/cli.md` for read-only/deprecated `archibot jobs stop/retry` behavior.
2. Add missing Maintenance actions that are currently only easy to find in Dashboard/Control Center:
   - Mark embedding index stale.
   - Forced poll reconciliation if this remains desired as a first-class admin action.
3. Reword Maintenance labels away from "worker jobs" where the backend already creates durable commands:
   - poll reconciliation command;
   - reindex command;
   - embedding index build command.
4. Keep OCR reindex explicitly labeled as legacy/temporary until the durable OCR actor exists.

### Stage 2: remove duplicate action launchers from Control Center

After Stage 1 review/approval, remove Control Center quick controls and generic launch forms that are duplicated by Maintenance/Dashboard:

- quick controls block;
- generic worker-job type selector;
- possibly manual process-document form only after it has a target-language replacement elsewhere.

Preserve the command list and temporary worker row list/detail pages.

### Stage 3: rename or narrow Control Center

If Control Center becomes only logs/history, decide whether to:

- keep the page title as **Control Center** for now;
- rename navigation/title to **Job history**;
- split durable command history and legacy worker rows into separate sections/pages.

Ask before doing this because it is user-facing navigation.

### Stage 4: retire Worker Jobs fully only after prerequisites

Do not remove backend/routes/controllers/models until all prerequisites are true:

- durable OCR reindex actor exists and has GUI/CLI parity;
- no current code creates productive `worker_jobs` rows;
- historical worker rows either have an accepted migration/archival strategy or the UI can safely ignore them;
- stats/errors/dashboard/health/review/entity/pipeline links no longer depend on `WorkerJob`;
- reset/prune/recovery docs are updated;
- tests prove equivalent actions remain available through commands/pipeline/actors.

## Approval questions before code changes

1. Should Stage 1 be implemented first as the next patch?
2. Should **forced poll reconciliation** be a first-class Maintenance action, or remain dashboard/control-only?
3. Should **manual process document** move to Maintenance, Pipeline Runs, or stay in Control Center until a separate design is chosen?
4. If Control Center loses launch buttons, should its navigation label stay **Control Center** or become **Job history** / **Legacy worker jobs**?
5. Is it acceptable to update `docs/developer/cli.md` now to mark `archibot jobs stop/retry` as deprecated/read-only?
