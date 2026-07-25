# ADR-0021: Use Globally Staged Document Batches

## Status

Accepted.

## Context

The document actor previously performed OCR, classification and judge verification back-to-back for one document. Poll reconciliation created one independently dispatched actor per target document. That allowed a judge request for an early document while later target documents had not yet completed OCR or classification, and it repeatedly switched provider model roles.

The embedding readiness gate already prevents downstream document processing until the global trusted-context index is complete, but it did not order model-role work within a poll target set.

## Decision

A poll reconciliation target set is processed by one durable `staged_document_batch` command after Laravel has consumed all poll candidates through `DocumentPipelineStarter`.

Laravel remains the sole orchestration owner:

- poll discovery must finish successfully before its target set is sealed or any candidate is consumed;
- poll candidates create or coalesce document Pipeline Runs through the shared start seam;
- newly created poll Pipeline Runs defer their individual actor dispatch, including runs blocked by embedding readiness;
- Laravel links those runs to one idempotent staged-batch command and dispatches the fixed `process_staged_document_batch` Python actor;
- batch-linked child runs are excluded from individual document recovery dispatch;
- Laravel recovery redispatches the batch command and releases the whole batch together when embedding readiness reopens;
- per-child retry and cancellation controls fail closed because either operation would bypass or invalidate the target-set barrier.

The Python batch actor holds the shared embedding/readiness lease for the target set and executes these global phases in order:

1. generate and persist current embeddings for every target document, retaining the inbox-tag trust classification;
2. run configured OCR correction for every eligible target document;
3. classify every target document using the precomputed target embedding and trusted context;
4. run or skip judge verification for every target document according to the existing judge-enabled and confidence-threshold settings;
5. publish Review Suggestions only after the global judge phase has completed.

A later phase never starts until the preceding phase has visited the complete target set. Phase items, progress and `pipeline.batch.phase.completed` events are durable evidence of the boundary. Webhook and manual single-document runs use the same phase implementation with a target set of one; independently arriving webhooks are not combined into an artificial batch.

## Consequences

- Poll processing no longer enters judge document-by-document while classification for the same target set is incomplete.
- Provider roles are grouped, reducing avoidable model switching.
- Inbox Document embeddings may be persisted for target lookup but remain `trusted_for_context = false`; the trusted-context boundary is unchanged.
- A poll target set occupies one worker invocation and one shared embedding lease for its full staged lifecycle. Higher-priority work can run before the batch starts, but running work is not preempted.
- Failure to generate or persist any required target embedding stops the batch before OCR. Failure of a required classification step stops it before judge. OCR, context lookup and judge retain their documented graceful-degradation behavior.
- Batch commands and child Pipeline Runs must be recovered together; dispatching a batch-linked child through the singleton actor would violate the phase barrier.

## References

- [ADR-0006: Require a Complete Embedding Index Before Document Processing](0006-require-complete-embedding-index-before-document-processing.md)
- [ADR-0015: Use Laravel Database Queues for Event Transport](0015-use-laravel-database-queues-for-event-transport.md)
- [ADR-0017: Use One Durable Orchestration and Execution Ownership Model](0017-single-durable-orchestration-and-execution-ownership.md)
- [Embedding readiness gate](../architecture/embedding-readiness-gate.md)
- [Webhook and polling coordination](../architecture/webhook-polling-coordination.md)
