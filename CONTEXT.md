# ArchiBot Context

ArchiBot is a self-hosted Paperless-NGX assistant that suggests document metadata, keeps safety-critical changes behind review and permission checks, and uses a durable event-driven pipeline.

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

**Classification Marker**:
The durable existence of a **Review Suggestion** for a **Paperless Document**, proving that classification completed at least once. Automatic polling skips an **Inbox Document** with this marker even when Paperless metadata changed; explicit force reprocess remains available.
_Avoid_: Paperless modified timestamp

**Pipeline Run**:
A durable PostgreSQL record tracking one event-driven processing attempt for a **Paperless Document**.
_Avoid_: worker job when referring to the target event-driven pipeline

**Worker Job** (retired):
The removed temporary Laravel control-plane record from the legacy migration path. Do not use this term for current runtime state.
_Avoid_: pipeline run, command, actor execution

**Webhook Delivery**:
A persisted Paperless webhook receipt normalized by Laravel before Python actors execute the requested ArchiBot action.
_Avoid_: webhook event when referring to the durable PostgreSQL receipt

## Relationships

- A **Paperless Document** with the inbox tag is an **Inbox Document**.
- A **Paperless Document** without the inbox tag is a **Trusted Document**.
- A **Trusted Document** may be embedded and used as classification context.
- An **Inbox Document** must not be used as trusted classification context.
- A **Pipeline Run** may produce one **Review Suggestion** for a **Paperless Document**.
- A **Review Suggestion** is the **Classification Marker** that prevents automatic polling from repeatedly classifying the same **Inbox Document**.
- A **Webhook Delivery** may create or link to one **Pipeline Run** for a **Paperless Document**.
- A retired **Worker Job** must not be reintroduced or confused with a **Pipeline Run**, **Command**, or **Actor Execution**.

## Example dialogue

> **Dev:** "Can this **Inbox Document** be one of the examples in the LLM prompt?"
> **Domain expert:** "No. Only a **Trusted Document** — a Paperless document without the inbox tag — can be used as classification context."

## Flagged ambiguities

- "reviewed document" previously meant either an accepted ArchiBot review or a document outside the inbox. Resolved: for classification context, trust means absence of the configured inbox tag.
