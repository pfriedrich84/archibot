# ADR-0022: Use Temporal for Durable Workflow Orchestration

## Status

Accepted. Supersedes ADR-0015 and the transport/orchestration parts of ADR-0017 and ADR-0021. [ADR-0024](0024-own-the-complete-document-lifecycle-in-one-temporal-workflow.md) supersedes the document scheduling details below while retaining Temporal as the sole orchestration owner.

## Context

ArchiBot currently splits one logical operation across Laravel database queue jobs,
short-lived Python processes, PostgreSQL lifecycle rows and a periodic Laravel recovery
scan. A process can complete its domain work while the parent queue job still reports a
protocol failure. Long AI calls can also remain healthy while the recovery scan declares
their actor execution stale. The result is duplicate dispatch, exhausted attempts,
commands stuck at 100 percent, and documents retained by an old poll command.

These are coordination failures. Adding more status repair and timeout exceptions would
keep the same split ownership.

## Decision

Temporal is the sole productive workflow execution, timer, retry, heartbeat and recovery
owner for ArchiBot background work.

- Laravel remains the authorization, HTTP, UI and review-decision boundary. It writes an
  immutable Temporal start/signal/cancel intent to a transactional PostgreSQL outbox in
  the same transaction as the corresponding business command or review decision.
- A supervised Python relay uses the official Temporal Python SDK to deliver outbox
  intents idempotently. A crash between Temporal acceptance and outbox acknowledgement is
  safe because the workflow ID and intent ID are stable. The relay makes no workflow
  scheduling decisions of its own.
- Python uses the same official SDK for deterministic workflows and activities. Activities
  own Paperless, AI-provider and ArchiBot PostgreSQL I/O.
- Temporal persists execution history in dedicated Temporal PostgreSQL databases. The
  ArchiBot PostgreSQL/pgvector database remains the business-data, audit, review,
  embedding and UI-projection store.
- Laravel database queues and `RunPythonActorJob` are removed from productive workflow
  execution as each flow cuts over. The periodic stale-actor recovery loop is removed
  after the final cutover.
- A productive flow has exactly one owner. Shadow validation may read and compare output,
  but it may not write Paperless or create review decisions.

Temporal workflow state is authoritative for whether work is scheduled, waiting,
retrying or complete. PostgreSQL projections are authoritative for product data and are
updated idempotently by activities. UI status must identify the Temporal workflow ID and
reflect its latest durable projection; a projection lag must be shown as such and must not
cause redispatch.

## Workflow model

### Embedding index

One `embedding-index/{generation}` workflow owns a build generation. It completes
successfully with `0/0` when the trusted corpus is empty. It changes the durable readiness
projection to complete only after every required embedding activity has reached a terminal
state. Activity heartbeats carry item progress, and retries resume from persisted item
state.

### Discovery and documents

Scheduled polling and Paperless webhooks only discover document identities. A poll command
never owns a document and cannot prevent a later discovery from progressing. Duplicate
webhook, poll and normal manual triggers converge on one stable workflow per Paperless
document ID; explicit force reprocessing creates a separate generation.

The recurring reconciliation timer is a singleton Temporal Schedule. Its overlap policy is
`SKIP`, its interval follows `POLL_INTERVAL_SECONDS`, and setting the interval to zero pauses
the schedule. Each scheduled execution creates its auditable PostgreSQL Command before it
discovers documents. Laravel does not run a minute-level due check for this flow.

Each document workflow owns and schedules its OCR, target embedding, classification,
judge, review wait and accepted Paperless commit in sequence as specified by ADR-0024.
The retired model-phase scheduler was removed after its histories were reset.

### Review and Paperless commit

A document workflow waits without occupying a worker until Laravel sends an authorized
accept or reject signal. Acceptance contains the immutable review decision identity and
authorized principal references. A Paperless commit activity rechecks the reviewed target,
uses an idempotency key and preserves every existing Paperless storage path. Repeated
signals or activity retries cannot duplicate the write.

## Retry and heartbeat policy

- Workflow code performs no network, filesystem, random-time or database I/O.
- External I/O runs only in activities with explicit start-to-close and schedule-to-close
  timeouts.
- Long embedding, OCR and LLM activities heartbeat while work is active. Cancellation and
  worker loss use Temporal heartbeat semantics rather than wall-clock inspection of an
  application row.
- Known validation failures are non-retryable application failures. Transient network,
  provider and Paperless failures use bounded exponential activity retries.
- Paperless mutations and PostgreSQL projections are idempotent because an activity may
  execute more than once.
- Workflow implementation changes must pass replay tests for retained histories.

## Deployment and security boundary

The standard Docker stack gains a private Temporal Service and a dedicated Temporal
PostgreSQL volume. The Temporal frontend port is internal by default. Temporal UI is an
optional operator profile and must not be publicly exposed without authentication and TLS.
Image versions are pinned. Schema setup runs through the matching pinned Temporal admin
tools image before the server starts.

Temporal receives workflow IDs, internal row identifiers, status, retry metadata and
activity inputs needed to resume execution. Secrets and full document content must not be
stored in workflow arguments, search attributes, memo or logs. Activities load credentials
and document content only when needed from their existing protected sources.

## Migration

Cutover is performed in reviewable slices:

1. add the Temporal service, clients, worker, health checks and replay-test harness;
2. migrate embedding build and readiness, including empty installations;
3. migrate polling/webhook discovery and per-document processing;
4. migrate review waiting and Paperless commit;
5. reconcile in-flight legacy rows, remove Laravel actor dispatch/recovery and remove the
   legacy poll-owned staged-batch owner;
6. restore image publication only after clean-stack, restart and failure-injection tests.

During a slice, the old path remains productive only for flows not yet cut over. There is
no operator-selectable permanent backend mode.

## Consequences

- Container and worker restarts no longer require status-age guesses to recover work.
- A completed activity cannot be converted into a failed command by a missing subprocess
  protocol record.
- Poll commands no longer retain ownership of documents.
- Each review becomes visible when its owning document workflow completes model processing.
- The deployed stack has additional Temporal server and persistence services and requires
  schema/version lifecycle management.
- Temporal history compatibility and activity idempotency become mandatory release gates.

## References

- [Temporal self-hosted deployment guide](https://docs.temporal.io/self-hosted-guide/deployment)
- [Temporal Python SDK](https://python.temporal.io/)
- [Temporal Docker Compose PostgreSQL sample](https://github.com/temporalio/samples-server/blob/main/compose/docker-compose-postgres.yml)
- [ADR-0004: Do Not Add a Long-term Legacy Compatibility Mode](0004-no-legacy-compatibility-mode.md)
- [ADR-0006: Require Complete Embedding Index Before Document Processing](0006-require-complete-embedding-index-before-document-processing.md)
- [ADR-0018: Suspend Model-confidence Auto-commit](0018-suspend-model-confidence-auto-commit.md)
- [ADR-0019: Separate Review Decisions from Admin Job Control](0019-separate-review-decisions-from-admin-job-control.md)
- [ADR-0024: Own the Complete Document Lifecycle in One Temporal Workflow](0024-own-the-complete-document-lifecycle-in-one-temporal-workflow.md)
