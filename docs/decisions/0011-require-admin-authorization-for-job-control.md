# ADR-0011: Require Admin Authorization for Job Control

## Status

Accepted

## Context

Archibot exposes powerful operational actions:

- start document processing
- retry failed jobs
- cancel running jobs
- force reprocess documents
- start reconciliation/polling manually
- start embedding build or rebuild
- start reindex
- commit review suggestions
- dismiss failed webhook deliveries
- manipulate pipeline state

These actions can consume LLM/provider resources, modify Paperless metadata, create or commit review suggestions, and affect system-wide processing state.

The Laravel application already has an authorization method:

```php
is_admin()
```

## Decision

Only admins may control jobs.

Hard rule:

```text
Any action that starts, retries, cancels, forces, commits, dismisses, repairs or otherwise controls pipeline/job execution must require is_admin().
```

Non-admin users may view permitted UI state where appropriate, but they must not be able to mutate job/pipeline execution state.

## Admin-Only Actions

The following actions require `is_admin()`:

- start document processing
- retry document pipeline
- retry failed actor/pipeline items
- retry failed webhook delivery
- cancel queued/running pipeline
- force reprocess document
- start or resume embedding build
- mark embedding index stale/complete/failed manually
- start reindex
- start reconciliation/poll manually
- dismiss permanent webhook failure
- commit review suggestion to Paperless
- sync entity approval
- change worker/pipeline settings
- change LLM provider/model used for processing

## Webhook Exception

Paperless webhook ingestion is not a user action and therefore does not use `is_admin()`.

Webhook authorization must be handled separately through webhook security:

- internal network restrictions
- reverse proxy ACLs
- shared secret header if available
- request validation

Webhook ingestion may create pipeline work automatically, but manual user-triggered job control remains admin-only.

## API Requirement

Every Laravel controller/action that mutates job or pipeline state must explicitly check admin authorization before doing work.

Recommended pattern:

```php
abort_unless(auth()->user()?->is_admin(), 403);
```

or project-standard equivalent policy/middleware.

## UI Requirement

The Laravel UI must hide or disable job-control actions for non-admin users.

However, UI hiding is not sufficient. The backend API must enforce authorization.

## Audit Requirement

Admin job-control actions should be auditable.

Recommended fields/events:

```text
actor_user_id
actor_is_admin
action
target_type
target_id
pipeline_run_id
paperless_document_id
created_at
```

Recommended pipeline events:

```text
job_control.retry_requested
job_control.cancel_requested
job_control.force_reprocess_requested
job_control.reindex_requested
job_control.embedding_build_requested
job_control.webhook_failure_dismissed
```

## Python Worker Requirement

Python actors should not make user authorization decisions for UI/API actions.

Authorization belongs at the Laravel/API boundary before commands or pipeline runs are created.

Python should still record `created_by_user_id` / `requested_by_user_id` / `trigger_source` if provided.

## Test Requirements

Minimum tests:

- non-admin cannot start document processing
- non-admin cannot retry failed document
- non-admin cannot cancel pipeline run
- non-admin cannot force reprocess
- non-admin cannot start reindex
- non-admin cannot start embedding build
- non-admin cannot dismiss failed webhook delivery
- admin can perform job-control actions
- UI/API does not rely only on hidden buttons
- webhook ingestion still works without user session but with webhook security

## Consequences

What gets easier:

- safer operations
- clearer separation between viewing and controlling jobs
- fewer accidental expensive LLM/reindex operations
- better auditability

What gets harder:

- every job-control endpoint needs explicit authorization
- tests must cover admin/non-admin behavior
- UI must distinguish view-only and control permissions

What must not be done anymore:

- adding job-control endpoints without `is_admin()`
- relying only on frontend visibility to protect actions
- letting non-admins start expensive or mutating pipeline work
- performing admin authorization inside Python workers instead of Laravel/API boundary
