# Pipeline Start caller inventory and freeze

Status: Steps 7–9 implemented; the Step 10 deletion candidate is complete but hardening plan 2.2 acceptance remains pending its required full-suite and clean-install Docker gates. This file-by-file inventory was checked over `app/`, `laravel/app/`, scheduler/supervisor configuration, MCP registration, migrations, dependencies, CI and tests. The deny-by-default regression guard is `tests/test_pipeline_start_ownership.py`.

## Productive Pipeline Start ownership

`App\Services\Pipeline\DocumentPipelineStarter` is the sole constructor/coalescer of document `pipeline_runs`. `PipelineStartGate` holds a shared fence through committed creation/coalescing, final readiness and the subsequent dispatch decision; queue failure therefore occurs only after a recoverable pending run commits. Recovery selects its Webhook Delivery in a separate completed transaction before calling the starter, so it cannot accidentally defer that commit behind an outer transaction. The post-dispatch `pending` to `queued` transition is conditional and cannot overwrite a fast worker's `running` or terminal state. Exclusive stale/build/reindex transitions cannot interleave. Python's former `app/jobs/pipeline_start.py`, dedupe helper and productive Pipeline Run INSERT were deleted.

| Productive caller file | Trigger and final path | Replacement/deletion milestone |
| --- | --- | --- |
| `laravel/app/Http/Controllers/PaperlessEventWebhookController.php` | create/consume webhook -> starter | Final Step 7 path. |
| `laravel/app/Services/Pipeline/DocumentPipelineStarter.php` | sole run constructor/coalescer; shared-fenced readiness and newly-created dispatch | Final Step 7 owner. |
| `laravel/app/Services/Pipeline/PipelineStartGate.php` | PostgreSQL shared/exclusive cross-process fence | Final Step 7 ownership boundary. |
| `laravel/app/Services/Pipeline/PollCandidateConsumer.php` | scheduled/admin poll candidate -> versioned fenced lease -> starter | Final Step 7 path. |
| `laravel/app/Services/Pipeline/PipelineContentStateNormalizer.php` | canonical webhook/poll timestamp, hash and content-state normalization | Final Step 7 normalization owner. |
| `laravel/app/Console/Commands/DispatchMaintenanceCommand.php` | CLI `process_document` -> starter | Final Step 7 path. |
| `laravel/app/Http/Controllers/Admin/MaintenanceController.php` | admin manual document start -> starter | Final Step 7 path. |
| `laravel/app/Http/Controllers/ReviewSuggestionController.php` | authorized force reprocess -> starter | Final Step 7 path. |
| `laravel/app/Http/Controllers/PipelineRunController.php` | manual existing-run retry changes durable state; Laravel recovery redispatches the fixed queued job, whose execution revalidates inside the shared fence | Final Step 7 execution path; lifecycle consolidation remains hardening 1.3. |
| `laravel/app/Services/Pipeline/PipelineRecoveryDispatcher.php` | candidate replay and missing-run recovery -> candidate consumer/starter; existing runs are redispatched as `RunPythonActorJob` without recreation | Final Step 7 path; lifecycle consolidation remains hardening 1.3. |
| `laravel/app/Jobs/RunPythonActorJob.php` | sole productive launch point for document/build/reindex Python actors; performs eligibility only and never holds a parent lease while waiting for Python | Final Step 7 child-owned fenced execution path. |
| `laravel/app/Services/Actors/PythonActorRunner.php` | fixed allowlisted subprocess invocation reached only by `RunPythonActorJob` | Retain transport seam; lifecycle consolidation remains hardening 1.3. |
| `laravel/app/Services/Pipeline/PipelineLifecycleRecorder.php` | append-only `PipelineEvent`/`AuditLog` persistence called through literal `event`/`audit` methods, keeping creation APIs outside lifecycle-owner files | Retain narrow audit seam; it has no Pipeline Run creation authority. |
| `laravel/app/Services/Pipeline/MaintenanceCommandDispatcher.php` | reindex closes the shared fence before command dispatch; polling emits candidates | Final reindex/poll orchestration seam; actor lifecycle cleanup is hardening 1.3/1.5. |
| `laravel/app/Http/Controllers/EmbeddingIndexController.php` | manual stale transition through shared fence | Final gate-control seam. |
| `app/actors/maintenance.py` | discovers and inserts protocol-v1 candidates only | Productive Python Pipeline Start deleted in Step 7. Candidate discovery remains Python domain work. |
| `app/actors/webhook.py` | refresh/delete domain actions; stale `process_document` fails closed | Delete stale process-document compatibility branch in hardening 1.3 after old queued work is drained. |

The empty-webhook poll hint persists a Webhook Delivery and creates a reconciliation Command; it reaches the starter only through `PollCandidateConsumer`. Reindex/embedding build is not itself a document start and any future document observations must use the candidate protocol.

## Durable candidate and gate fencing

Each claim records a random `claim_token` and monotonic `claim_version`. Claim completion, marker skip, protocol failure and retry release all condition on candidate ID + `claimed` status + token + version. A stale consumer may therefore neither complete nor reset a lease reclaimed by another consumer. Force replay remains idempotent because the candidate UUID is the force token.

A stable PostgreSQL session advisory-lock key is the cross-process fence. Pipeline Start holds a Laravel shared lease through its committed creation/readiness/dispatch linearization point. The productive Python document child opens a separate PostgreSQL session, acquires the shared lease, performs the decisive readiness query on that exact lease-owning session, passes the result into the actor, and retains the lease through completion. Productive Python embedding build/reindex children similarly acquire an exclusive lease before the stale/build transition and retain it through the complete lifecycle. Laravel may perform a short exclusive stale transition before dispatch, but never holds or transfers a lease while waiting for a child, avoiding exclusive self-deadlock. Shared starts and document actors remain concurrent and serialize only against embedding mutation. A Laravel-parent SIGKILL closes only the parent session; the live child session and lease remain valid. Unit tests verify child ownership/order, while `PostgresPipelineFenceTest` exercises independent sessions and parent-session-death/live-child behavior.

## MCP inventory (every registered tool/resource)

MCP entry points do not own Pipeline Start. Their current state source and required retirement are explicit:

| File | Current behavior/state | Replacement/deletion milestone |
| --- | --- | --- |
| `app/mcp_server.py` | Registers all MCP tools/resources and still initializes legacy DB compatibility. | Hardening 1.4: use Laravel/PostgreSQL service seam; remove legacy DB initialization. |
| `app/mcp_tools/__init__.py` | Package marker only; no state/start caller. | Retain. |
| `app/mcp_tools/_auth.py` | MCP token/auth checks only. | Retain; no migration. |
| `app/mcp_tools/_deps.py` | Dormant lifespan/verified-identity marker; creates no product-state client. | Retain; no state owner. |
| `app/mcp_tools/classify.py` | Registration retired in Step 9. | No return before a permission-aware Laravel Pipeline seam exists. |
| `app/mcp_tools/correspondents.py` | Proposal registrations retired in Step 9. | No return before an admin-authorized PostgreSQL entity seam exists. |
| `app/mcp_tools/doctypes.py` | Proposal registrations retired in Step 9. | Same. |
| `app/mcp_tools/documents.py` | All registrations retired in Step 9. | No return before a permission-aware Laravel/PostgreSQL read or mutation seam exists. |
| `app/mcp_tools/entities.py` | All registrations retired in Step 9. | No return before a permission-aware Laravel/PostgreSQL entity-read seam exists. |
| `app/mcp_tools/resources.py` | Identity-less resources retired in Step 9. | No return before identity-aware PostgreSQL redaction seam exists. |
| `app/mcp_tools/suggestions.py` | All registrations retired in Step 9. | No return before permission-filtered Laravel Review seam exists. |
| `app/mcp_tools/system.py` | Global status registration retired in Step 9. | No return before admin-only PostgreSQL diagnostics seam exists. |
| `app/mcp_tools/tags.py` | Proposal registrations retired in Step 9. | No return before an admin-authorized PostgreSQL entity seam exists. |

## Retired SQLite state/vector inventory (Step 10)

The former `app/db.py`, `app/api_data.py`, `app/indexer.py`, `app/job_events.py`, `app/vector_store.py`, `app/worker.py`, `app/pipeline/committer.py`, `app/pipeline/document_processing.py` and Laravel `LegacyPythonState` service were deleted. Their processing, suggestion, vector/search, processed-document, poll-cycle, timing, error and audit tables have no productive reader or writer. PostgreSQL/pgvector repositories, Laravel scheduling, durable Pipeline Events/Audit Logs and authorized Review/Entity services are the replacements. `app/config.py` no longer defines or inspects a local database path, and reset remains exclusively `php artisan archibot:reset` through `ArchibotResetService`.

See [SQLite disposition and upgrade notes](sqlite-disposition.md) for the classification of every retained reference and persistent-volume behavior.
## Absurd/lifecycle inventory

| File | Current caller/reference | Replacement/deletion milestone |
| --- | --- | --- |
| `app/absurd_queue.py` | Absurd SDK backend implementation. | Hardening 1.5: delete backend/dependency. |
| `app/actors/__init__.py` | Imports queue backend/decorator compatibility. | Hardening 1.5. |
| `app/actors/document.py` | Exposes only the implementation imported by the fixed Laravel actor runner; the Absurd wrapper was removed so legacy recovery cannot bypass the shared fence. | Hardening 1.3: consolidate lifecycle transitions. |
| `app/actors/embedding.py` | Exposes only the implementation imported by the fixed Laravel actor runner; the Absurd wrapper was removed so build/reindex cannot bypass the exclusive fence. | Hardening 1.3: consolidate lifecycle transitions. |
| `app/actors/maintenance.py` | Same compatibility decorator; productive candidate discovery is fixed-command invocation. | Hardening 1.3/1.5. |
| `app/actors/review.py` | Same compatibility decorator. | Hardening 1.3/1.5. |
| `app/actors/webhook.py` | Same compatibility decorator and stale process-document guard. | Hardening 1.3/1.5. |
| `app/event_worker.py` | Unsupervised transitional Absurd worker/recovery entry point. | Hardening 1.5: delete launcher. |
| `app/actor_runner.py` | Fixed Laravel-launched actor CLI and the only productive importer/caller of private document/build implementations; uses PostgreSQL repositories only. | Retain fixed runner. |
| `app/jobs/pipeline_runs.py` | Existing-run lifecycle reads/updates only; all insertion/start helpers were removed. | Hardening 1.3 consolidates lifecycle transitions; never restore creation here. |
| Laravel `EntityApprovalDecisionService` | PostgreSQL-owned approval/blacklist decisions, durable command/events, Review Suggestion resolution, and retroactive Paperless application. | Productive entity approval never invokes Python or `classifier.db`; the former sync actor is retired. |
| `app/jobs/idempotency.py` | Webhook/command key helpers only; the Python Pipeline Start dedupe key was removed. | Retain non-start helpers; never restore Pipeline Start ownership. |
| `app/jobs/recovery.py` | Transitional recovery remains for non-document legacy actors, but document/build/reindex enqueue helpers fail closed and the scan never redispatches them. | Hardening 1.3/1.5: delete after remaining Laravel recovery parity. |
| `laravel/database/migrations/2026_06_05_000000_install_absurd_queue_schema.php` | Transitional Absurd schema. | Hardening 1.5 clean-install removal migration. |
| `laravel/database/sql/absurd.sql` | Transitional schema source. | Hardening 1.5 delete. |
| `.github/workflows/ci.yml` | No Python SQLite initialization remains. Laravel's isolated test job uses an ephemeral SQLite database as test infrastructure only. | Retain test-only harness; it is not product state. |
| `scripts/event_driven_smoke.py` | Imports fixed actors and PostgreSQL repositories without initializing local state. | Retain dependency-light contract smoke. |
| `pyproject.toml` | Declares transitional Absurd dependency. | Hardening 1.5 remove with runtime/schema references. |
| `constraints.txt` | Pins transitional Absurd dependency. | Hardening 1.5 remove with `pyproject.toml`. |
| `Dockerfile` | Absurd dependency remains installed. | Hardening 1.5 remove with schema/runtime references. |
| `docker/supervisord.conf` | Productive runtime launches Laravel `queue:work`, not event worker. | Retain Laravel worker; verify no Absurd process before 1.5 deletion. |

## Structural freeze

The guard scans every readable productive repository file outside tests, docs, generated dependency trees and local artifacts; it does not rely on a file-extension allowlist, so extensionless launchers, environment examples, migrations, CI, supervisor/Docker inputs, root config and scripts are covered. It parses Python imports/module aliases plus SQL string/f-string and alias data flow, rejects direct, aliased or dynamic calls into the fenced actor-runner entry points, and rejects `python -m app.actor_runner` launches outside Laravel's fixed `PythonActorRunner` transport. It also applies deny-by-default PHP provenance tracking: every static `PipelineRun` method (including scopes and future retrieval APIs), every container expression receiving `PipelineRun::class`, every `pipelineRuns()` relationship, and their fluent/assignment/collection/foreach/destructuring aliases are tainted. `DocumentPipelineStarter` is the sole creation owner: constructors, container factories, relationship factories, `make`, `newInstance`, `newModelInstance`, `newFromBuilder`, `replicate`, create/force-create/first-or-create/update-or-create variants, and unknown static factories are rejected as calls in their own right, without relying on an inferred origin or later save. Cloning a tainted value is rejected. Enumerated lifecycle-owner files use a stricter lexical rule independent of receiver provenance. Every dynamic instance/static invocation, variable function, invokable container, `call_user_func`/forwarded callback, PHP callable array, Reflection invocation, and `eval`/`assert` execution is forbidden. In particular, every `${` token is rejected anywhere in a lifecycle-owner file, and a balanced lexical scan rejects `{$...}` variable-call syntax through arbitrary brace nesting without a regex depth limit. String and concatenated-string callable assignments are rejected before invocation, and any literal composition naming `PipelineRun` plus a creation/mutation method is rejected wherever it appears. The only variable invocation exception is the exact unbraced `$onFailure(...)` and `$onSuccess(...)` calls inside the private `PythonActorRunner::runProcess` seam, where both are explicitly `Closure`-typed parameters; all call sites supply literal internal closures. Every literal instance or static method whose case/separator-normalized name contains `create`, `insert`, `upsert`, `persist`, `store`, `save`, `push`, `replicate`, `newInstance`, `newModel`, `newFromBuilder`, or `make` semantics is also forbidden. Substring matching covers prefix/suffix variants such as `forceCreateQuietly`, `createMany`, `createOrFirst`, and `incrementOrCreate` without scanner updates. All remaining calls must belong to the explicit closed read/retrieval/update vocabulary or the reviewed per-file service/helper allowlist. Legitimate lifecycle writes use explicit allowlisted `update`/`updateQuietly` or query updates; unrelated append-only event/audit creation is isolated behind the literal `PipelineLifecycleRecorder::event`/`audit` seam. Static/container `PipelineRun` factories remain reserved to `DocumentPipelineStarter`, and unknown Pipeline Run model/builder methods default denied without rejecting those audited service calls. The policy also rejects query-builder inserts, dynamic table/model write targets, and formatted/raw `INSERT INTO pipeline_runs`. Non-language runtime files receive the raw Pipeline Run insert rule too. The exact productive legacy-reference file set is frozen: new files fail rather than silently expanding an allowlist. Tests may create fixtures, but productive code may not create Pipeline Runs.

## Retention and rollback safety

`poll_candidates.command_id` uses `RESTRICT ON DELETE`: candidate discovery, leases and terminal outcomes cannot disappear when command retention runs. `pipeline_run_id` uses `SET NULL ON DELETE`, preserving candidate evidence if separately retained Pipeline Runs are removed. Tests cover command deletion protection and two-consumer lease fencing.

Rolling back the migration **does delete the candidate audit/replay table**; it is not a retention mechanism. Before rollback on a persistent PostgreSQL volume:

1. stop poll producers, queue workers and recovery consumers;
2. drain or explicitly freeze `ready`/`claimed` candidates;
3. export `poll_candidates` (including tokens/versions/outcomes and foreign IDs) to an operator-controlled encrypted backup and verify row counts/checksum;
4. retain Commands/Pipeline Runs and record the export beside the rollback change record;
5. run migration rollback only after the export is verified.

An older image against a persistent volume with Step 7 rows cannot replay them and must not restore Python Pipeline Start. Roll forward by redeploying Step 7 schema/code and importing the verified export with idempotency constraints intact, then replay `ready` or expired `claimed` rows. A clean rollback may drop `poll_candidates`; the advisory fence has no schema object, and Commands and Pipeline Runs are intentionally not deleted by `down()`.
