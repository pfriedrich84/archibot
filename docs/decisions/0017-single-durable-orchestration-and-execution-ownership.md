# ADR-0017: Use One Durable Orchestration and Execution Ownership Model

## Status

Accepted, amended 2026-07-17 to make the orchestration/execution fence explicit. Clarifies the final migration state selected by ADR-0004, ADR-0015 and ADR-0016; the amendment does not transfer orchestration ownership to Python.

## Context

ArchiBot currently retains several transitional implementations: SQLite processing used by legacy CLI/MCP paths, Absurd queue wiring alongside Laravel Database Queues, Pipeline Start logic in both Laravel and Python, and actor lifecycle transitions spread across both runtimes. These parallel implementations split product state, retry behavior and operational understanding.

## Decision

ArchiBot uses one durable production model:

- PostgreSQL and pgvector are the only product-state and vector-search stores.
- Laravel Database Queues are the only execution transport.
- Laravel is the sole owner of orchestration: command ingestion, authorization, every Pipeline Start decision, start-time embedding-readiness checks, dedupe/coalescing, force-run creation, recovery/manual redispatch, maintenance/build invocation and actor dispatch. No productive Python entry point may independently start a Pipeline Run or decide orchestration readiness.
- Python owns document processing and one deep execution-lifecycle Module for domain status transitions, progress, retry classification, domain retry scheduling and sanitized events.
- A productive Python child must acquire its own PostgreSQL advisory lease and revalidate embedding readiness on the lease-owning session immediately before document execution. It holds that shared lease through all document mutations. An embedding build/reindex child acquires the exclusive counterpart before the first stale/build transition and holds it through the lifecycle. This execution-time revalidation and fencing is mandatory defense-in-depth against queue delay, process races and parent death; it does not make Python an orchestration, Pipeline Start or readiness-policy owner.
- Laravel queue retry semantics cover dispatch, process launch and transport/protocol failures only. Python/PostgreSQL retry semantics cover Paperless/provider/processing domain failures under ADR-0008.
- Laravel records transport launch/outcome but must not overwrite a retryable or pending domain state selected by Python.
- CLI, UI and MCP entry points use the same durable Laravel/PostgreSQL path.
- SQLite processing, Absurd SDK/schema/workers/recovery, and duplicate Python Pipeline Start logic are removed after focused parity tests pass. They are not optional compatibility backends.

## Consequences

Migration must proceed through small parity-backed slices so that productive entry points are moved before legacy code is deleted. Runtime configuration, supervisor programs, migrations, dependencies, tests and documentation for removed backends must be deleted together. A temporary migration seam may exist only while its removal criterion and dependent callers are explicit in the implementation plan.

Laravel's start-time check and the child's execution-time check are intentionally distinct. The first owns orchestration and whether work may be created/dispatched; the second refuses unsafe execution if readiness changed after dispatch. Recovery, manual retry and queued-before-gate-change work cannot bypass the child check. Laravel never transfers its database session lease to the child and does not wait for a child while holding a conflicting lease.

## References

- [ADR-0004: Do Not Add a Long-term Legacy Compatibility Mode](0004-no-legacy-compatibility-mode.md)
- [ADR-0008: Use Durable Retries and Recovery for Pipeline Failures](0008-use-durable-retries-and-recovery-for-pipeline-failures.md)
- [ADR-0015: Use Laravel Database Queues for Event Transport](0015-use-laravel-database-queues-for-event-transport.md)
- [ADR-0016: Clean-install Retirement of Worker Jobs](0016-clean-install-worker-jobs-retirement.md)
- [Webhook/polling coordination and cross-process fence](../architecture/webhook-polling-coordination.md)
- [Pipeline Start caller inventory and ownership guard](../implementation-notes/pipeline-start-caller-inventory.md)
- [Security and architecture hardening plan](../implementation-plan-security-architecture-hardening.md)
