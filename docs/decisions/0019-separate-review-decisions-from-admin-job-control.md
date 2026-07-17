# ADR-0019: Separate Review Decisions from Admin Job Control

## Status

Accepted. Amends ADR-0011 where it classified committing an authorized Review Suggestion as admin-only job control.

## Context

Operational controls such as reindex, retry, cancellation, force reprocess and pipeline repair affect global execution and remain admin-only. A Review Suggestion is different: it is a per-document product decision, and ArchiBot already verifies whether a non-admin user has live Paperless permission to change that Paperless Document. Requiring ArchiBot admin status as well would prevent authorized Paperless users from completing their own review workflow.

## Decision

A non-admin may view a Review Suggestion when live Paperless authorization permits viewing the corresponding Paperless Document. They may edit, accept or reject it only when live Paperless authorization permits changing that document. Acceptance may create the durable review-commit Command and queued actor as the consequence of that authorized product decision.

This exception does not grant general job control. Retry, cancel, force reprocess, poll, reindex, embedding controls, webhook failure controls, pipeline repair and system-wide settings remain admin-only under ADR-0011. Authorization occurs in Laravel before durable mutation; Python actors do not make user authorization decisions.

## References

- [ADR-0011: Require Admin Authorization for Job Control](0011-require-admin-authorization-for-job-control.md)
- [Security and architecture hardening plan](../implementation-plan-security-architecture-hardening.md)
