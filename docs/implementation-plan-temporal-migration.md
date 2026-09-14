# Temporal Migration Plan

This plan implements [ADR-0022](decisions/0022-use-temporal-for-durable-workflow-orchestration.md)
without running two productive owners for the same flow.

## Invariants retained throughout migration

- No document processing starts before the required embedding generation is complete.
- An empty trusted corpus completes embedding readiness as `0/0`.
- Webhooks remain primary and polling remains reconciliation.
- Existing Paperless storage paths are authoritative and are always shown in review.
- Paperless writes require an authorized manual review decision.
- Inbox documents never become trusted classification context before review and commit.
- Every Paperless and PostgreSQL activity is idempotent and safe after worker loss.
- Docker image publication remains manual until the release gate in Phase 5 passes.

## Phase 1: Runtime foundation

- Add pinned Temporal server, admin-tools and optional UI services with dedicated
  persistence.
- Add the Temporal Python SDK and PHP client SDK.
- Add private connection, namespace and task-queue configuration.
- Start a supervised Python Temporal worker and expose Temporal readiness in `/healthz`.
- Add a no-side-effect connectivity workflow and deterministic replay test.

Exit criteria: a clean stack initializes the namespace, the worker polls, Laravel starts a
workflow through the PHP client, and a restart resumes it without Laravel queue recovery.

## Phase 2: Embedding ownership

- Implement `EmbeddingIndexWorkflow` and idempotent index activities.
- Heartbeat once per document and project progress after each terminal item.
- Complete `0/0` builds without contacting an AI provider.
- Make startup and reindex use Temporal; remove their Laravel actor dispatch.
- Make readiness generation-based so a stale old command cannot close or reopen the gate.

Exit criteria: normal, empty, interrupted and provider-failure builds finish consistently in
Temporal, PostgreSQL and the dashboard.

## Phase 3: Discovery and document workflows

- Implement scheduled poll discovery and webhook signal-with-start.
- Replace poll-owned candidates with global document observations.
- Implement one workflow per Paperless document content version.
- Wait durably for embedding readiness; process each document independently afterward.
- Publish each Review Suggestion immediately when that document is complete.
- Remove the productive staged-document-batch path.

Exit criteria: duplicate poll/webhook events coalesce, restarts create no duplicate review,
and no old poll can retain a document.

## Phase 4: Review and commit

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

