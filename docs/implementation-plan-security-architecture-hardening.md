# Implementation Plan: Security, Product and Architecture Hardening

## Status and baseline

Status: accepted plan; implementation in progress. Containment 0.1 (Chat/RAG), 0.2 (confidence auto-commit), 0.3 (local-only OCR with live Paperless permissions), 0.4 (webhook authentication), 0.5 (admin-only structured diagnostics), and 0.6 (first-run setup hardening) are implemented; remaining milestones are pending.

Baseline: `main` after PR [#219](https://github.com/pfriedrich84/archibot/pull/219), merge commit `5ec7cb2`.

This plan records the maintainer decisions from the product-risk and architecture review. It does not claim that the current runtime already satisfies the target controls. Each milestone must ship as a focused, reviewable pull request with current validation evidence.

## Outcomes

ArchiBot should reach the following release posture:

- non-admin document-facing features cannot disclose a Paperless Document outside the requesting user's live Paperless permissions; explicitly admin-only operational diagnostics remain a privileged global surface with structured/redacted output;
- OCR corrections remain local and never overwrite Paperless content;
- setup and webhook trust boundaries fail closed;
- model output cannot authorize Paperless writes by self-reported confidence;
- operational diagnostics are admin-only and use structured presentation;
- PostgreSQL/pgvector and Laravel Database Queues are the only production state/transport model;
- Laravel owns Pipeline Start and Python owns processing plus domain execution lifecycle;
- CLI, UI and MCP use the same durable behavior;
- documented UX features work consistently before a stable release.

## Accepted product decisions

| Area | Decision | Durable reference |
| --- | --- | --- |
| Chat/RAG | Disable Chat/RAG for all users until an authorization-safe redesign is approved. Preserve data but expose no page, route or navigation entry. | [Issue #221](https://github.com/pfriedrich84/archibot/issues/221) |
| OCR behavior | Delete all OCR write-back, restore and automatic-write behavior. Corrections remain local. | `docs/agent/RULES.md` |
| OCR authorization | View requires live Paperless view access; approve/reject requires live Paperless change permission. Fail closed when Paperless cannot verify access. | This plan |
| Paperless v3 | Investigate v3 remote OCR, file versions, parser plugins, effective content and API version 9 without weakening local-only OCR. | [Issue #222](https://github.com/pfriedrich84/archibot/issues/222) |
| First-run setup | Keep the wizard, but pin the Paperless URL through deployment configuration. The wizard verifies a Paperless superuser and cannot select another Paperless destination. Rate-limit and validate setup requests. AI-provider configuration becomes editable only after claim. | This plan |
| Webhooks | Require a secret and fail closed when it is absent. Any development bypass must be explicit, visibly unsafe and impossible to enable accidentally in production. | This plan |
| Diagnostics | Operations, webhook, Pipeline Run, actor, error and audit surfaces are admin-only. | This plan |
| Diagnostic presentation | Render structured fields, tables, timelines and badges only; do not display raw JSON. | `docs/agent/RULES.md` |
| Auto-commit | Disable model-confidence auto-commit immediately. Re-enable only after deterministic safety gates and adversarial tests receive explicit approval. | [ADR-0018](decisions/0018-suspend-model-confidence-auto-commit.md) |
| Retry ownership | Python/PostgreSQL owns domain retry state. Laravel is transport and must preserve retryable/pending domain outcomes. | [ADR-0017](decisions/0017-single-durable-orchestration-and-execution-ownership.md) |
| UX consistency | Fix path-prefix support, pagination, mutation feedback/confirmation and manual model-ID entry as one pre-release milestone. | This plan |

## Accepted architecture decisions

| Opportunity | Decision |
| --- | --- |
| OCR Adapter | Delete the Paperless OCR-content mutation Adapter; keep a local OCR review Module. |
| Processing state | Remove productive SQLite processing. PostgreSQL/pgvector is the only state and search implementation. |
| Queue transport | Remove Absurd completely. Laravel Database Queues are the only transport. |
| Pipeline Start | Laravel is the sole owner of gate, dedupe, coalescing, force-run creation and dispatch. |
| Execution lifecycle | Create one deep Python lifecycle Module for domain transitions, progress, retries and sanitized events. |

The final ownership model is governed by [ADR-0017](decisions/0017-single-durable-orchestration-and-execution-ownership.md).

## Delivery rules

1. Containment precedes redesign and cleanup.
2. Never combine unrelated containment fixes into one large patch merely because they share this plan.
3. Every removed path must first have caller inventory and parity evidence.
4. A compatibility flag such as `legacy|event`, `sqlite|postgres` or `absurd|laravel` is forbidden.
5. Schema changes must include forward migration, rollback limits and persistent-volume safety notes.
6. Security-sensitive routes require negative authorization tests, not only happy-path tests.
7. Private document/OCR/prompt content must not appear in tests, logs or committed fixtures.
8. Update user, developer, operations, trust-boundary and agent docs in the same milestone that changes behavior.

## Milestone 0 — Immediate containment

Deliver these as independent PRs so each can be reviewed and rolled back separately.

### 0.1 Disable Chat/RAG

Scope:

- remove Chat navigation;
- remove Chat page and API route registration so direct requests return the normal not-found response; do not retain a compatibility endpoint;
- prevent Laravel from invoking `PythonChatRag` and prevent MCP/resource paths from exposing equivalent global retrieval;
- preserve existing chat rows without exposing their content;
- update user documentation and settings text.

Acceptance:

- authenticated admins and non-admins cannot execute Chat/RAG;
- direct page/API requests return `404` and tests prove no Python process or AI provider is called;
- existing chat data is not deleted;
- Issue #221 remains the only re-enable/redesign track.

Rollback: route/UI containment can be reverted, but must not be reverted before the authorization redesign is approved.

### 0.2 Suspend auto-commit

Status: implemented. Confidence remains review evidence only; safe automation is still a separate, unapproved redesign track.

Scope:

- make the effective auto-commit threshold disabled in Laravel settings export and Python processing;
- stop the Document Actor from auto-accepting/queueing commit based on model confidence;
- preserve every result as a pending Review Suggestion;
- hide or disable controls that imply confidence can authorize writes;
- add an operator-visible explanation referencing the temporary security suspension.

Acceptance:

- thresholds from environment, imported settings and PostgreSQL cannot trigger a Paperless PATCH;
- high-confidence and judge-approved classifications remain pending;
- manual acceptance still queues the reviewed commit path;
- regression tests use adversarial document instructions and model confidence `100`.

Rollback: do not restore model-confidence writes; rollback must remain manual-review-only.

### 0.3 Enforce local-only, permission-scoped OCR

Scope:

- remove `updateDocumentContent`, write-back, restore and auto-write flows from Laravel;
- remove `ocr.auto_write_back` configuration and UI;
- keep local original/corrected/approved snapshots only where retention is documented;
- replace the current admin-bypassing permission behavior for OCR: every user, including an ArchiBot admin, must pass live Paperless document authorization;
- authorize document IDs before pagination/cursor creation and before loading OCR content, rather than paginate globally and filter afterward;
- use a shared Paperless permission policy for every list, show, approve and reject operation;
- filter list queries without exposing inaccessible rows, totals or record existence;
- fail closed on Paperless authorization failure.

Acceptance:

- no OCR-originated request can PATCH Paperless `content`;
- cross-user tests prove inaccessible OCR text is neither listed nor shown, including an ArchiBot admin denied by Paperless;
- mutation requires current Paperless change permission with no ArchiBot-admin bypass;
- no user-facing text promises write-back or restore;
- Issue #222 remains investigation-only and cannot bypass ADR/rules.

Migration/rollback: retain existing OCR rows; remove obsolete state only after confirming no UI/runtime reader depends on it. Do not delete original content as part of the containment PR.

### 0.4 Require webhook authentication

Status: implemented. Both supported aliases fail closed before parsing/persistence and retain existing durable dedupe/retry behavior after successful authentication.

Scope:

- generate or require a secret during setup/deployment;
- reject requests when the effective secret is empty;
- use constant-time comparison;
- add request-size and rate limits;
- define an explicit development-only bypass with startup/UI warning and production guard;
- document Paperless workflow configuration and secret rotation.

Acceptance:

- missing configuration, missing header and wrong secret all fail closed;
- valid secret remains compatible with duplicate delivery and retry behavior;
- tests cover oversized/rate-limited requests without storing private payloads;
- rotation has an operator runbook and rollback window.

### 0.5 Restrict and structure diagnostics

Status: implemented. One admin middleware protects every diagnostic route before route-model binding, mutation controllers retain defense-in-depth admin checks, and every browser diagnostic contract (including statistics and embedding snapshots) uses fixed field schemas, source-inventoried canonical application/provider enums, non-leaking `unknown` aggregate buckets, stable non-reversible references for opaque identifiers (including webhook dedupe, request IDs, configurable provider profiles and every model ID), and fixed redaction notices for free-form messages. Canonical actor phases, event codes and explicitly inventoried internal error classes/types remain visible for recovery; arbitrary or merely grammar-conforming stored keys and values do not.

Scope:

- apply one admin middleware/policy to operations log, Pipeline Runs, webhook deliveries, actor executions, errors, embeddings diagnostics, maintenance and audit routes;
- verify every mutation remains admin-guarded in the controller as defense in depth;
- replace raw JSON blocks with structured summaries, labeled metadata, event timelines and redacted error fields;
- validate browser-bound diagnostic scalars against fixed types/source-inventoried enums and replace attacker-controlled webhook event, modified/dedupe, request-ID and equivalent unknown values with stable non-reversible references; never echo configurable model IDs, provider-profile IDs or merely exception-shaped error values;
- never expose authorization headers, tokens, full document text, prompts or OCR content.

Acceptance:

- every non-admin direct request returns `403` without record-existence leakage;
- admin feature tests cover all route groups;
- frontend contains no raw payload `JSON.stringify` presentation for diagnostic metadata;
- structured views preserve the fields needed for retry/recovery diagnosis.

### 0.6 Harden first-run setup

Status: implemented. Deployment `PAPERLESS_URL` is the immutable bootstrap origin across Laravel and managed Python runtime configuration; setup requires Paperless `is_superuser`, defers AI-provider editing until after claim, bounds public inputs and decoded responses (with a separate finite preview limit), and enforces the request/network controls below. Regression coverage maps to every acceptance item.

Scope:

- require the canonical Paperless origin from deployment configuration and treat it as immutable during bootstrap;
- use that same origin for setup, tag loading, login, admin settings, Laravel Paperless clients and Python runtime export;
- reject database/admin-setting URL overrides while the deployment pin is active and define migration behavior for existing stored `paperless.url` values;
- render the pinned destination read-only and reject submitted overrides server-side;
- derive `isSuperuser` only from Paperless's documented superuser field; `is_staff` or similarly broad roles must not claim an instance;
- allow only Paperless superuser verification against the pinned destination;
- rate-limit setup, tag-loading, and model-discovery endpoints;
- apply URL parsing, redirect, timeout and response-size controls;
- defer editable AI-provider endpoints until setup has created the administrator session.

Acceptance:

- tests prove submitted, stored-database and admin-settings Paperless URL overrides are ignored/rejected across setup, login and Python export;
- staff-only users cannot complete setup; explicit `is_superuser: false` is authoritative, while a missing field exhausts documented compatibility fallbacks and then fails closed;
- redirect chains cannot escape the pinned origin;
- response sinks bound actual retained/decoded bytes for compressed and chunked responses, verified through Guzzle against raw gzip and chunk-framed loopback responses, while document previews use a separate, finite preview bound;
- repeated authentication/model discovery is throttled, and public setup/tag credential and input lengths are bounded before network access;
- setup still completes against the configured Paperless URL;
- documentation states that deployment configuration owns the initial Paperless destination and documents the response/input limits.

Residual accepted risk: this plan does not add a separate bootstrap token. The pinned Paperless destination and live superuser verification are the bootstrap trust anchors.

## Milestone 1 — Establish final ownership seams

### 1.1 Inventory callers and freeze legacy expansion

Produce a checked inventory for:

- `app/db.py`, `processed_documents`, legacy `suggestions`, SQLite vector/search and worker polling;
- `app/absurd_queue.py`, actor decorators, Python recovery/event workers, supervisor programs and Absurd SQL/migrations;
- Python and Laravel Pipeline Start callers;
- command, Pipeline Run, review commit, webhook and maintenance retry paths;
- CLI and MCP entry points.

For every caller, record its replacement and deletion milestone. Add tests that fail if new productive references to `classifier.db`, `processed_documents`, Absurd or duplicate Pipeline Start are introduced.

### 1.2 Make Laravel the only Pipeline Start owner

Scope:

- route webhook, scheduled poll, manual, retry and reindex triggers through Laravel's starter;
- make poll discovery persist candidates rather than create Pipeline Runs in Python;
- define a durable poll-candidate record or versioned result protocol containing candidate ID, Paperless Document ID, normalized modified/content state, Classification Marker disposition, trigger metadata and idempotency key;
- make a Laravel consumer transaction claim/replay candidates, call `DocumentPipelineStarter`, record the outcome and dispatch only newly created runs;
- keep one canonical timestamp/content-state normalization implementation;
- atomically create/coalesce the run and dispatch the Laravel queued actor job;
- return durable outcomes for created, coalesced, blocked and force-created states;
- delete Python Pipeline Start only after all callers migrate.

Acceptance:

- concurrent webhook/poll tests create one run;
- `Z`, offsets and equivalent timestamps normalize identically;
- explicit manual force reprocess and explicit forced poll create force-new runs, while ordinary starts and non-force retry retain attach/coalescing semantics;
- enqueue failure leaves recoverable durable state;
- a crash before/after candidate persistence, claim, Pipeline Run creation or dispatch replays without duplicate classification;
- no productive Python caller imports `app.jobs.pipeline_start` after deletion.

### 1.3 Introduce the Python execution-lifecycle Module

The Module must own domain transitions without exposing transport details through its Interface. Required behavior:

- load durable execution/run state;
- start/resume idempotently;
- update item-derived progress;
- classify transient/permanent/cancelled/blocked outcomes;
- increment attempts and schedule bounded backoff exactly once;
- emit sanitized canonical events;
- return a versioned, machine-readable domain-outcome protocol that distinguishes succeeded, blocked, cancelled, retrying, failed-permanent and protocol failure;
- apply the lifecycle consistently to document runs, review commands/suggestions, webhooks, embedding builds, poll/reindex commands and actor executions;
- finalize without allowing Laravel transport cleanup to overwrite retryable state.

Acceptance:

- a transition-matrix test covers pending, queued, running, blocked, retrying, succeeded, failed-permanent and cancelled;
- crash/restart tests reconstruct progress and attempts;
- subprocess integration tests prove Python `retrying` remains `retrying` after Laravel observes command completion/failure for document, review, webhook, embedding, poll and reindex flows;
- version mismatch, malformed outcome and missing outcome fail as transport/protocol errors without corrupting domain state;
- actor implementations lose duplicated lifecycle/retry boilerplate.

### 1.4 Align Laravel transport outcome handling

Scope:

- parse and validate the versioned Python domain-outcome protocol separately from process exit status;
- distinguish process launch/protocol failures from Python domain outcomes;
- make queued jobs reference only durable IDs;
- preserve Python-selected pending/retrying/blocked state;
- prevent duplicate command completion and actor dispatch;
- ensure recovery scans use due times and active-execution checks.

Acceptance:

- real subprocess tests cover exit success, malformed/missing/version-mismatched output, timeout, signal/crash and retryable domain failure across every actor family;
- transport failure cannot mark a successful/retrying Pipeline Run, Command, Webhook Delivery, Review Suggestion or Actor Execution failed;
- stale queue jobs can be safely redispatched once.

## Milestone 2 — Remove parallel backends

### 2.1 Migrate CLI and MCP off SQLite

Scope:

- make operator CLI commands delegate to the same Laravel command/Pipeline Run behavior as UI controls;
- inventory every MCP tool/resource and record one disposition: migrate behind a permission-aware Laravel/PostgreSQL seam or retire;
- cover document search/retrieval/update, classification, suggestion list/accept/reject, entity list/approve/reject/unblacklist, system status and summary resources rather than only classify/suggestion operations;
- carry verified MCP user identity and Paperless permissions into every document-scoped operation, failing closed before content or mutation;
- route every MCP mutation through the same durable Command/Review/Pipeline behavior and audit semantics as Laravel UI actions;
- ensure read resources use PostgreSQL-backed state and redact records the MCP identity cannot access;
- remove SQLite initialization/output from productive commands and MCP startup;
- preserve fixed command contracts and user-visible progress semantics.

Acceptance:

- CLI/UI equivalence tests cover poll, force poll, per-document process, reindex, OCR reindex, reset and review commit;
- a committed MCP disposition matrix names every registered tool/resource, replacement seam, identity/permission rule, durable audit/command behavior and deletion criterion;
- MCP tests cover every retained tool/resource with allowed and denied identities and PostgreSQL-backed state;
- productive commands never create/read `classifier.db`;
- restart tests prove durable state continuity.

### 2.2 Delete SQLite processing

Delete only after 2.1 passes:

- legacy processing tables and migrations;
- SQLite vector/search and suggestion repositories;
- `processed_documents` and legacy poll-cycle state;
- unused config, reset behavior, tests and documentation;
- SQLite-specific dependencies when no allowed local cache still requires them.

Do not delete an unrelated local cache merely because it uses SQLite; first classify it as product state or bounded cache and document the result.

Acceptance:

- repository search finds no productive legacy state references;
- reset remains Laravel/PostgreSQL-owned;
- full Python/Laravel suites and a clean-install Docker smoke test pass.

### 2.3 Remove Absurd

Delete:

- Absurd SDK and constraints;
- `app/absurd_queue.py`, decorators and queue bootstrap;
- Absurd recovery/event workers and supervisor programs;
- vendored SQL and installation migration;
- environment/settings/docs and broker-specific tests.

Preserve actor work as plain allowlisted functions reached through Laravel queued jobs.

Acceptance:

- all fixed actor commands import and run without Absurd installed;
- auto/manual review commits, webhooks, polls, embedding builds and recovery use Laravel Database Queues;
- no `absurd` schema objects are created on a clean install;
- upgrade notes explain whether existing Absurd schema is left inert or removed and how rollback behaves;
- Docker contains no Absurd worker process.

## Milestone 3 — UX consistency before stable release

### 3.1 Complete path-prefix support

- replace hard-coded frontend URLs with generated/prefix-aware route data;
- cover navigation, setup, settings, forms, previews and API calls;
- run core browser/feature flows with empty prefix and `/archibot`.

### 3.2 Complete pagination

- add one reusable pagination presentation to every paginated list;
- preserve filters, sort and page size across links;
- test navigation beyond item 25 for reviews, OCR, Pipeline Runs, webhooks and errors.

### 3.3 Complete mutation feedback and confirmation

- expose status/error flash data through Inertia;
- render accessible success/failure messages consistently;
- prevent duplicate submits while work is queued;
- require confirmation for bulk/destructive actions and state the number/effect of documents.

### 3.4 Allow manual model identifiers

- let setup use validated manual classification/embedding/OCR/judge model IDs when provider discovery is empty or incomplete;
- distinguish discovery failure from model validation failure;
- test OpenAI-compatible providers without a useful `/models` response.

Milestone acceptance:

- frontend lint, format, typecheck and production build pass;
- focused feature/browser tests cover each affected control;
- user documentation matches actual navigation and setup behavior.

## Milestone 4 — Deliberate redesign tracks

These do not block containment or backend retirement unless their issue explicitly changes priority.

### 4.1 Authorization-safe RAG

Tracked in Issue #221. Do not re-enable Chat/RAG until the issue's identity propagation, ACL filtering, revocation, source-redaction and cross-user tests are complete and explicitly approved.

### 4.2 Paperless-ngx v3 compatibility and OCR investigation

Tracked in Issue #222. Produce a Paperless 2.20/v3 compatibility matrix and API-version negotiation tests. Parser plugins, remote OCR or file versions must not silently reintroduce OCR content write-back or new cloud-data flows.

### 4.3 Safe automation eligibility

Before proposing auto-commit re-enable:

- define field-level deterministic eligibility and prohibited changes;
- define evidence/calibration independent of model confidence;
- define allowed document/entity cohorts and minimum sample sizes;
- add prompt-injection, malformed OCR, conflicting-context and permission-change tests;
- provide dry-run metrics and operator-visible reasons;
- request explicit maintainer security/product approval.

Until all criteria pass, `AUTO_COMMIT_CONFIDENCE` remains ineffective.

## Validation matrix

Every PR runs the smallest focused checks while developing and the final relevant set after its last material edit.

| Change area | Required validation |
| --- | --- |
| Python actors/lifecycle/CLI/MCP | `ruff check app/ tests/`; `ruff format --check app/ tests/`; `pytest tests/ -v`; focused subprocess contract tests |
| Laravel authorization/routes/transport | `COMPOSER_ALLOW_SUPERUSER=1 composer test`; focused feature tests for every route/control and negative authorization case |
| Svelte UX | `npm run lint:check`; `npm run format:check`; `npm run types:check`; `npm run build`; browser/manual control smoke evidence where automation is absent |
| PostgreSQL migrations | clean migration; upgrade fixture; rollback-limit review; Python/Laravel shared-schema contract tests |
| Docker/runtime/dependency removal | `scripts/ci-local.sh --full`; Docker build; Grype; Trivy; supervisor/process smoke; clean persistent-volume start |
| Documentation/ADRs | `python3 scripts/check_markdown_links.py` |

For security milestones, a green happy path is insufficient. Record denial tests, skipped cases, warnings and evidence freshness under `docs/agent/CONTEXT_AND_EVIDENCE.md`.

## Release gates

Do not declare a stable multi-user release until:

- Milestone 0 is complete;
- no critical/high confidentiality finding remains open without explicit release acceptance;
- Milestones 1 and 2 leave one productive state/transport/orchestration model;
- Milestone 3 core flows pass with the supported path-prefix configurations;
- clean install, upgrade, backup and rollback procedures are tested;
- CI Docker, Grype and Trivy checks pass on the release commit;
- documentation clearly identifies disabled redesign tracks.

## Suggested PR sequence

1. Disable Chat/RAG.
2. Suspend auto-commit.
3. Remove OCR write-back and add OCR authorization.
4. Require webhook secret.
5. Harden setup and pin the Paperless origin.
6. Restrict/structure diagnostics.
7. Add ownership inventory and regression guards.
8. Move all Pipeline Start callers to Laravel.
9. Add Python execution lifecycle and align Laravel transport outcomes.
10. Migrate CLI/MCP off SQLite.
11. Delete SQLite processing.
12. Remove Absurd.
13. Deliver UX consistency slices.
14. Complete Issue #222 compatibility research.
15. Design work for Issue #221 and safe automation only after separate approval.

PRs 1-6 may be reordered for reviewer availability, but containment must precede redesign. PRs 8-12 are dependency-ordered and should not be collapsed into one large migration.
