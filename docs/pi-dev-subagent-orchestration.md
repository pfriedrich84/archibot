# Archived Pi.dev Subagent Orchestration Guide

## Status

**Superseded; retained as a historical pointer only. Do not execute the former phase map or initial prompt.**

This guide was written for the retired [`worker_jobs`/Absurd implementation plan](implementation-plan-laravel-job-control.md). Its fixed phase assignments and broad read requirements no longer match ADR-0015, ADR-0016, or the repository's task-triggered context routing.

## Active workflow

Use:

1. root [`AGENTS.md`](../AGENTS.md) for instruction precedence and task-specific reading;
2. [`docs/governance/agent-workflow.md`](governance/agent-workflow.md) for shared contracts, delegation and milestone checks;
3. the [conditional migration task router](prompts/pi-dev-event-driven-migration.md) only when changing migration, queue, pipeline, recovery or superseded runtime paths;
4. [`docs/agent/CONTEXT_AND_EVIDENCE.md`](agent/CONTEXT_AND_EVIDENCE.md) for delegated evidence, handoff and recovery.

Delegate only independent, bounded scopes with explicit file ownership and output contracts. Do not require a fixed number of subagents or preload an entire implementation plan when a focused source is sufficient.

## Historical note

The former guide recommended one fresh subagent per worker-job phase and embedded prompts for progressively hardening the temporary control plane. Those instructions are obsolete. The complete pre-archive snapshot remains available in Git revision `aec74e6` and earlier history.
