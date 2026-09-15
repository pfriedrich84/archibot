# Temporal Migration Plan

This plan implements [ADR-0022](decisions/0022-use-temporal-for-durable-workflow-orchestration.md)
and [ADR-0024](decisions/0024-own-the-complete-document-lifecycle-in-one-temporal-workflow.md) without running
two productive owners for the same flow.

## Invariants retained throughout migration

- No document processing starts before the required embedding generation is complete.
- An empty trusted corpus completes embedding readiness as `0/0`.
- Webhooks remain primary and polling remains reconciliation.
- Existing Paperless storage paths are authoritative and are always shown in review.
- Paperless writes require an authorized manual review decision.
- Inbox documents never become trusted classification context before review and commit.
- Every Paperless and PostgreSQL activity is idempotent and safe after worker loss.
- Normal discovery converges on one workflow per Paperless document ID; only explicit force
  reprocessing creates another generation.
- Every newly started document workflow owns its ordered OCR, embedding, classification,
  judge, review and commit lifecycle. Dedicated task queues retain provider-role isolation.
- Docker image publication follows the tested-commit release gate in Phase 5.

## Phase 1: Runtime foundation

- Add pinned Temporal server, admin-tools and optional UI services with dedicated
  persistence.
- Add the Temporal Python SDK, transactional outbox and idempotent relay.
- Add private connection, namespace and task-queue configuration.
- Start a supervised Python Temporal worker and expose Temporal readiness in `/healthz`.
- Add a no-side-effect connectivity workflow and deterministic replay test.

Exit criteria: a clean stack initializes the namespace, the worker polls, Laravel writes a
transactional intent, the relay starts the workflow, and a restart resumes it without
Laravel queue recovery.

## Phase 2: Embedding ownership

- Implement `EmbeddingIndexWorkflow` and idempotent index activities.
- Heartbeat once per document and project progress after each terminal item.
- Complete `0/0` builds without contacting an AI provider.
- Make startup and reindex use Temporal; remove their Laravel actor dispatch.
- Make readiness generation-based so a stale old command cannot close or reopen the gate.

Exit criteria: normal, empty, interrupted and provider-failure builds finish consistently in
Temporal, PostgreSQL and the dashboard.

## Phase 3: Discovery and document identity

Current implementation state: poll commands and new webhook/manual document runs use the
transactional Temporal outbox. Poll discovery writes global document observations and starts
document workflows without retaining ownership. Each current document workflow owns its model
activities and uses a stable normal identity or an explicit force-reprocess generation. The
legacy batch and recovery code remains only for pre-cutover rows and explicitly excludes
`orchestration_driver=temporal`.

- Implement scheduled poll discovery and webhook signal-with-start.
- Replace poll-owned candidates with global document observations.
- Converge normal discovery on one workflow per Paperless document ID.
- Keep explicit force reprocessing as a separate immutable generation.
- Remove the productive staged-document-batch path.

Exit criteria: duplicate poll/webhook events coalesce on document identity, explicit force
creates a distinct generation, restarts create no duplicate review, and no old poll can retain
a document.

## Phase 3A: Per-document lifecycle ownership

- Make each document workflow execute optional OCR, target embedding, classification and judge
  in order through dedicated model task queues.
- Freeze model, context-window and OCR-tag configuration before productive model work begins.
- Persist one Review Suggestion and keep the workflow active while it waits for review.
- Complete acceptance only after the workflow commits approved metadata to Paperless.
- End the old waiting workflow as superseded when an administrator starts a force-reprocess
  generation.
- Keep the former singleton model-phase scheduler registered only for replay of histories made
  before ADR-0024.

Exit criteria: Temporal shows one complete history per document generation; worker and Temporal
restarts resume at the exact activity or review wait; no pending review leaves an unowned
workflow; and each normal Paperless document is processed once.

## Phase 4: Review and commit

Current implementation state: authorized accept/reject actions write their review state and
Temporal intent in one Laravel transaction. Current document workflows receive a stable
decision signal; accepted legacy suggestions start a stable standalone review-commit
workflow. Upgrade migration adopts already queued/running legacy commits. Paperless writes
are idempotent across activity retries, existing storage paths are loaded live and locked in
the review UI, and legacy Laravel jobs refuse Temporal-owned commands.

- Signal document workflows from authorized Laravel review actions.
- Implement idempotent Paperless commit activities with permission and version checks.
- Preserve and display current storage paths independently of suggested changes.
- Remove Laravel queued review-commit jobs.

Exit criteria: accept/reject survives app, worker, Temporal and Paperless interruptions;
accepted metadata is written once and its result is visible in ArchiBot.

## Phase 5: Retirement and release

- Reconcile or explicitly terminalize all in-flight legacy command, pipeline and actor rows.
- Remove `RunPythonActorJob`, `PythonActorRunner`, stale-actor redispatch and Laravel queue
  worker configuration from productive paths.
- Remove obsolete batch ownership, timeout and queue settings.
- Update installation, operations, backup and upgrade documentation.
- Run clean install, upgrade, restart, replay, failure-injection, full CI, Docker build and
  vulnerability scans.
- Re-enable tested-commit Docker publication and publish one reviewed image.

Exit criteria: Temporal is the only productive workflow owner, there are no legacy queue
redispatches, all release checks pass, and the published image is tied to the tested commit.
