# ArchiBot Context

ArchiBot is a self-hosted Paperless-NGX assistant that suggests document metadata, keeps safety-critical changes behind review and permission checks, and is migrating to a durable event-driven pipeline.

## Language

**Paperless Document**:
A document stored in Paperless-NGX that ArchiBot may classify, index, review, or commit metadata changes to.
_Avoid_: file, record

**Inbox Document**:
A **Paperless Document** carrying the configured inbox tag, such as `Posteingang`, and therefore not trusted as classification context.
_Avoid_: unprocessed file

**Trusted Document**:
A **Paperless Document** without the configured inbox tag and therefore eligible to be embedded and used as classification context.
_Avoid_: reviewed document when only inbox-tag absence is meant

**Review Suggestion**:
An ArchiBot proposal for Paperless metadata that must pass the configured review and permission flow before Paperless is changed.
_Avoid_: automatic update

**Pipeline Run**:
A durable PostgreSQL record tracking one event-driven processing attempt for a **Paperless Document**.
_Avoid_: worker job when referring to the target event-driven pipeline

**Worker Job**:
A temporary Laravel control-plane record for legacy/subprocess execution during migration.
_Avoid_: pipeline run

**Webhook Delivery**:
A persisted Paperless webhook receipt normalized by Laravel before Python actors execute the requested ArchiBot action.
_Avoid_: webhook event when referring to the durable PostgreSQL receipt

## Relationships

- A **Paperless Document** with the inbox tag is an **Inbox Document**.
- A **Paperless Document** without the inbox tag is a **Trusted Document**.
- A **Trusted Document** may be embedded and used as classification context.
- An **Inbox Document** must not be used as trusted classification context.
- A **Pipeline Run** may produce one **Review Suggestion** for a **Paperless Document**.
- A **Webhook Delivery** may create or link to one **Pipeline Run** for a **Paperless Document**.
- A **Worker Job** is temporary migration infrastructure and must not become the permanent **Pipeline Run** model.

## Example dialogue

> **Dev:** "Can this **Inbox Document** be one of the examples in the LLM prompt?"
> **Domain expert:** "No. Only a **Trusted Document** — a Paperless document without the inbox tag — can be used as classification context."

## Flagged ambiguities

- "reviewed document" previously meant either an accepted ArchiBot review or a document outside the inbox. Resolved: for classification context, trust means absence of the configured inbox tag.
