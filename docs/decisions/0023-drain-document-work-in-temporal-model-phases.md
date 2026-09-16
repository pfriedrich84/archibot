# ADR-0023: Drain Document Work in Temporal Model Phases

## Status

Superseded by [ADR-0024](0024-own-the-complete-document-lifecycle-in-one-temporal-workflow.md).
This decision previously superseded the document identity and independent scheduling details in
[ADR-0022](0022-use-temporal-for-durable-workflow-orchestration.md). The Temporal ownership,
outbox, persistence, retry and security decisions in ADR-0022 remain active.

## Context

One independent document workflow per content version prevents poll ownership and duplicate
processing, but allowing every workflow to call its next model immediately interleaves
embedding, classification and judge requests. A local provider may have to unload and reload
large models for every document. It also makes a global embedding command appear complete
while unrelated document workflows immediately request the embedding model again.

ArchiBot still needs isolation and durable recovery per Paperless document. Reintroducing a
Laravel-owned poll batch would restore the split ownership and stale recovery failures that
Temporal replaces.

## Decision

Temporal runs one singleton `ModelPhaseSchedulerWorkflow` per installation and one normal
productive `DocumentWorkflow` per Paperless document ID.

Normal workflow identity is stable:

```text
archibot/document/{paperless_document_id}
```

Duplicate poll, webhook and normal manual discoveries signal or reuse this workflow and never
start a parallel productive execution. A completed normal workflow is not automatically
reprocessed. This superseded decision gave an explicitly authorized force action a separate
workflow ID:

```text
archibot/document/{paperless_document_id}/reprocess/{generation}
```

ADR-0024 replaces that identity rule: force reprocessing now keeps the stable document
Workflow ID and advances to a new Run with Continue-As-New.

The document workflow owns its snapshot, phase results, review wait and Paperless commit. It
registers each model-backed requirement with the phase scheduler and waits durably for a
phase grant. It never dispatches provider work for an inactive phase.

The phase scheduler owns the global phase and cycles through model work in this order:

1. embedding;
2. configured OCR model phases, when enabled;
3. classification;
4. judge;
5. review release.

OCR may add text or vision phases between embedding and classification, but it cannot weaken
the required ordering: all eligible embeddings finish before any classification, and all
eligible classifications finish before any judge request.

For each active phase the scheduler announces the phase and fixed model configuration, grants
work to every currently eligible document workflow, and waits until the phase has no queued or
in-flight work. Every granted item reaches a terminal phase result: `completed`, `skipped` or
`failed_permanent`. Bounded activity retries occur before a permanent result. A failed item is
visible and cannot leave the scheduler permanently at 100 percent and `running`.

Registration and completion signals carry stable intent IDs. The scheduler stores queued,
granted and terminal item keys in Temporal history so duplicate signals and worker restarts
cannot grant or count work twice. ArchiBot PostgreSQL contains only idempotent progress and
business projections; it does not decide phase transitions.

The phase work set is dynamic only until its boundary closes:

- embedding requirements arriving while embedding is active join the current cycle;
- requirements for a phase whose boundary has closed wait for the next cycle;
- a document arriving during classification or judge waits for the next embedding phase;
- the scheduler never moves backward to load an earlier model;
- when review release completes, the scheduler starts the next embedding cycle if work waits,
  otherwise it waits without occupying a worker.

The trusted-context embedding index and target-document embeddings participate in the same
embedding phase. Classification is released only after the required index generation and all
target embeddings in that cycle are terminal. An empty installation closes embedding as
`0/0` and advances immediately.

At phase activation the scheduler freezes the provider endpoint, role model ID and relevant
configuration revision for that phase. Settings changed through the admin UI apply on the next
activation of that phase, never to already granted work. Dedicated activity task queues keep
model affinity explicit:

```text
archibot-model-embedding
archibot-model-ocr-text
archibot-model-ocr-vision
archibot-model-classification
archibot-model-judge
archibot-paperless
```

Multiple activities may run concurrently within the active phase, but each activity handles
one document and remains independently retryable and idempotent. Polling a task queue does not
load a model; only an activity dispatched by the active phase may call its provider role.

After every judge item is terminal, the scheduler releases reviews for that cycle. Document
workflows then persist their individual Review Suggestion idempotently and wait for an
authorized accept or reject signal. Review decisions and Paperless commits remain independent
of later model cycles.

## Consequences

- Local providers process a contiguous run of requests for one role instead of swapping models
  document by document.
- Every Paperless document retains an isolated, inspectable and retryable Temporal lifecycle.
- Poll commands discover IDs only and cannot reserve documents or own phase completion.
- New work may wait for the next cycle rather than interrupting the active model phase.
- Reviews for one cycle appear after its judge barrier instead of immediately after each
  classification.
- The singleton scheduler is durable coordination state and requires replay and signal
  deduplication tests in addition to document workflow tests.
- Explicit force reprocessing remains possible without weakening normal once-only identity.

## References

- [ADR-0006: Require Complete Embedding Index Before Document Processing](0006-require-complete-embedding-index-before-document-processing.md)
- [ADR-0018: Suspend Model-confidence Auto-commit](0018-suspend-model-confidence-auto-commit.md)
- [ADR-0020: Use One AI Provider per Installation](0020-use-one-ai-provider-per-installation.md)
- [ADR-0022: Use Temporal for Durable Workflow Orchestration](0022-use-temporal-for-durable-workflow-orchestration.md)
