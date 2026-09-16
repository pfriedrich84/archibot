# ADR-0024: Own the Complete Document Lifecycle in One Temporal Workflow

## Status

Accepted. Supersedes the global document model-phase scheduling decision in
[ADR-0023](0023-drain-document-work-in-temporal-model-phases.md). Temporal ownership,
transactional outbox, persistence, retry, heartbeat and security decisions from
[ADR-0022](0022-use-temporal-for-durable-workflow-orchestration.md) remain active.

## Context

The global model-phase scheduler executed OCR, embedding, classification and judge
activities on behalf of many documents. Although each document had a waiting Temporal
workflow, its history did not show its productive work. Operators had to correlate a
document workflow, scheduler cycle, phase projections and review signal to understand one
document. A force reprocess could start a replacement generation while leaving the prior
document workflow waiting indefinitely for a review that was no longer current.

Model-affine batching reduces provider model changes, but the operational ambiguity and
cross-document coupling are a worse fit for ArchiBot's review-oriented lifecycle.

## Decision

Each newly started `DocumentWorkflow` owns and visibly schedules the complete lifecycle for
one pipeline run:

1. wait for the required trusted embedding index to be ready;
2. run OCR when enabled and when the configured OCR tag permits it, otherwise record it as
   skipped;
3. create or reuse the target-document embedding;
4. classify the document;
5. run or skip the judge according to the frozen configuration;
6. persist one idempotent Review Suggestion;
7. wait durably for an authorized accept, reject or force-reprocess signal;
8. on acceptance, commit the allowlisted metadata to Paperless and finish; on rejection,
   finish without a Paperless write; on force reprocess, finish as superseded while a new
   immutable workflow generation owns the replacement run.

The workflow freezes provider, model, OCR tag and context-window configuration before model
work begins. The OCR activity reads the current Paperless document tags but evaluates them
against that frozen tag ID. OCR correction remains local. The target embedding uses the
locally corrected OCR text when one was produced.

Normal workflow identity remains `archibot/document/{paperless_document_id}`. An explicit
force action starts `archibot/document/{paperless_document_id}/reprocess/{generation}` and
atomically records a `force_reprocess` signal for the workflow associated with the old
review. The old pending suggestion becomes stale immediately, so it cannot be accepted while
the replacement is running.

The singleton `ModelPhaseSchedulerWorkflow`, its signal handlers, global projection and
replay branches were removed after the affected Temporal histories and application state
were explicitly reset. The embedding-index maintenance workflow remains independent from
the per-document review lifecycle.

All embedding, OCR, classification and judge activities use one common model task queue.
The standard worker exposes one activity slot for that queue and a process-wide capacity
guard, so an embedding build and document workflows cannot call the configured model
provider concurrently. Compatibility pollers for histories created before the queue change
share the same guard and are removed after those histories drain.

## Consequences

- Temporal UI shows OCR, embedding, classification, judge, review wait and Paperless commit
  under the document workflow that owns them.
- One slow or failed document does not hold a global review-release barrier for other
  documents.
- Exhausted productive activities leave the owning document workflow visibly failed in
  Temporal after the failure projection is persisted.
- Local providers receive one model activity at a time. Documents remain independent
  workflows, but their model phases queue behind the single provider-capacity slot.
- A document pipeline run remains active while waiting for review and becomes terminal only
  after accept, reject, force reprocess, cancellation or permanent failure.
- Temporal histories must be drained or reset before deploying this removal; the migration
  deliberately provides no replay compatibility for retired scheduler histories.

## References

- [ADR-0006: Require Complete Embedding Index Before Document Processing](0006-require-complete-embedding-index-before-document-processing.md)
- [ADR-0018: Suspend Model-confidence Auto-commit](0018-suspend-model-confidence-auto-commit.md)
- [ADR-0019: Separate Review Decisions from Admin Job Control](0019-separate-review-decisions-from-admin-job-control.md)
- [ADR-0022: Use Temporal for Durable Workflow Orchestration](0022-use-temporal-for-durable-workflow-orchestration.md)
- [ADR-0023: Drain Document Work in Temporal Model Phases](0023-drain-document-work-in-temporal-model-phases.md)
