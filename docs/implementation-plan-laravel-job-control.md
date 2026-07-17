# Archived Plan: Laravel Job Control and Product Reliability

## Status

**Superseded; retained as a historical pointer only. Do not use this file as implementation instructions.**

The former plan hardened `worker_jobs` as a temporary Laravel control plane and then proposed an Absurd-based event transport. Both directions have been superseded:

- [ADR-0015](decisions/0015-use-laravel-database-queues-for-event-transport.md) selects Laravel database queues plus fixed Python actor commands.
- [ADR-0016](decisions/0016-clean-install-worker-jobs-retirement.md) retires `worker_jobs` for clean installs instead of preserving compatibility.

## Active sources

Use these documents for current work:

1. Root [`AGENTS.md`](../AGENTS.md) for task routing and non-negotiable repository rules.
2. [Event-driven implementation plan](implementation-plan-event-driven-archibot.md) for target architecture and remaining migration.
3. [Event-driven phase status](implementation-notes/event-driven-phase-status.md) for the revisions and transition debt currently inspected.
4. [Job-control model](architecture/job-control-model.md) for active durable actions, ownership and state machines.
5. [Conditional migration task router](prompts/pi-dev-event-driven-migration.md) only for migration-related tasks.

## Historical scope

The superseded plan previously covered:

- stabilization of `WorkerJobDispatcher`, `worker_jobs` and `RunPythonWorkerJob`;
- dispatch leases, heartbeats, stale-job recovery, retry and cancellation;
- Worker Job UI/detail/history work;
- staged movement from worker jobs to commands, pipeline runs and events;
- a later Absurd actor transport target.

Those phases are not valid next steps. They must not be copied into issues, prompts, patches or subagent assignments.

The complete pre-archive snapshot remains available in Git revision `aec74e6` and earlier history for archaeology. Accepted ADRs and current code take precedence over that history.
