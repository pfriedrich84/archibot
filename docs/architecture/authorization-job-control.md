# Authorization for Job Control

## Purpose

Only admins may control Archibot jobs and pipelines.

The existing Laravel method is the required authorization gate:

```php
is_admin()
```

## Hard Rule

```text
Any action that starts, retries, cancels, forces, commits, dismisses, repairs or otherwise controls pipeline/job execution must require is_admin().
```

## Admin-Only Actions

Require `is_admin()` for:

- start document processing
- retry document pipeline
- retry failed actor or pipeline items
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

## Non-Admin Behavior

Non-admin users may be allowed to view permitted status pages, but they must not mutate job or pipeline execution state.

The UI should hide or disable controls for non-admins, but this is not sufficient. The backend must enforce authorization.

## Laravel/API Enforcement

Every Laravel controller/action that mutates job or pipeline state must check admin authorization before doing work.

Recommended pattern:

```php
abort_unless(auth()->user()?->is_admin(), 403);
```

or an equivalent project-standard policy/middleware.

The authorization check must happen before:

- creating commands
- changing pipeline state
- enqueueing Dramatiq work
- retrying failed work
- cancelling work
- changing embedding/reindex state
- committing Paperless mutations

## Webhook Exception

Paperless webhook ingestion is not a user job-control action and does not use `is_admin()`.

Webhook security must be handled separately:

- internal network only
- reverse proxy ACLs
- shared secret header if available
- request validation
- durable webhook delivery persistence

## Python Worker Boundary

Python workers should not decide whether a user is admin.

Admin authorization belongs at the Laravel/API boundary before a command or pipeline run is created.

Python should persist/audit metadata when available:

```text
created_by_user_id
requested_by_user_id
trigger_source
```

## Audit Events

Admin job-control actions should write events.

Recommended events:

```text
job_control.retry_requested
job_control.cancel_requested
job_control.force_reprocess_requested
job_control.reindex_requested
job_control.embedding_build_requested
job_control.webhook_failure_dismissed
job_control.review_commit_requested
```

Recommended audit fields:

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

## Review Checklist Additions

For every PR touching job-control UI/API:

- Does every mutating endpoint check `is_admin()`?
- Are UI controls hidden or disabled for non-admins?
- Is backend authorization enforced even if the UI is bypassed?
- Are job-control actions audited?
- Does webhook ingestion avoid user-session auth but still have webhook security?
- Does Python receive user/request metadata but avoid making authorization decisions?

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
- hidden UI buttons are not the only protection
- webhook ingestion still works without user session but with webhook security

## Related ADR

- `docs/decisions/0011-require-admin-authorization-for-job-control.md`
