# ADR-0017: Use One Durable Orchestration and Execution Ownership Model

## Status

Accepted. Clarifies the final migration state selected by ADR-0004, ADR-0015 and ADR-0016.

## Context

ArchiBot currently retains several transitional implementations: SQLite processing used by legacy CLI/MCP paths, Absurd queue wiring alongside Laravel Database Queues, Pipeline Start logic in both Laravel and Python, and actor lifecycle transitions spread across both runtimes. These parallel implementations split product state, retry behavior and operational understanding.

## Decision

ArchiBot uses one durable production model:

- PostgreSQL and pgvector are the only product-state and vector-search stores.
- Laravel Database Queues are the only execution transport.
- Laravel is the sole owner of command ingestion, authorization, Pipeline Start, embedding-readiness checks, dedupe/coalescing, force-run creation and actor dispatch.
- Python owns document processing and one deep execution-lifecycle Module for domain status transitions, progress, retry classification, domain retry scheduling and sanitized events.
- Laravel queue retry semantics cover dispatch, process launch and transport/protocol failures only. Python/PostgreSQL retry semantics cover Paperless/provider/processing domain failures under ADR-0008.
- Laravel records transport launch/outcome but must not overwrite a retryable or pending domain state selected by Python.
- CLI, UI and MCP entry points use the same durable Laravel/PostgreSQL path.
- SQLite processing, Absurd SDK/schema/workers/recovery, and duplicate Python Pipeline Start logic are removed after focused parity tests pass. They are not optional compatibility backends.

## Consequences

Migration must proceed through small parity-backed slices so that productive entry points are moved before legacy code is deleted. Runtime configuration, supervisor programs, migrations, dependencies, tests and documentation for removed backends must be deleted together. A temporary migration seam may exist only while its removal criterion and dependent callers are explicit in the implementation plan.

## References

- [ADR-0004: Do Not Add a Long-term Legacy Compatibility Mode](0004-no-legacy-compatibility-mode.md)
- [ADR-0008: Use Durable Retries and Recovery for Pipeline Failures](0008-use-durable-retries-and-recovery-for-pipeline-failures.md)
- [ADR-0015: Use Laravel Database Queues for Event Transport](0015-use-laravel-database-queues-for-event-transport.md)
- [ADR-0016: Clean-install Retirement of Worker Jobs](0016-clean-install-worker-jobs-retirement.md)
- [Security and architecture hardening plan](../implementation-plan-security-architecture-hardening.md)
