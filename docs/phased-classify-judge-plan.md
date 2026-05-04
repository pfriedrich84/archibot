# Plan: Fully Phased Classification + Judge Pipeline

## Goal

Refactor document processing so model-heavy work is grouped by phase:

1. Fetch candidate documents
2. OCR all documents
3. Embed/context all documents
4. Classify all documents
5. Judge all classifications
6. Store suggestions
7. Notify / auto-commit
8. Store final embeddings / finish

This avoids model thrashing when classifier and judge use different models, improves observability, and aligns backend, CLI, GUI, and Telegram around clear job phases.

## Current State

The batch pipeline is partially phased:

- `phase_ocr(...)` processes all documents.
- `phase_embed(...)` processes all documents.
- `phase_classify(...)` currently loops per document and interleaves:
  - classify
  - judge
  - store suggestion
  - Telegram notification
  - auto-commit
  - progress/event updates
  - final embedding storage

Current shape:

```text
OCR all docs
Embed all docs
For each doc:
  classify
  judge
  store
  notify/commit
  store embedding
```

If judge uses a separate model, this can cause repeated swaps:

```text
classifier -> judge -> classifier -> judge -> ...
```

## Target Shape

```text
OCR all docs
Embed all docs
Classify all docs
Judge all classifications
Store all suggestions
Notify / auto-commit all suggestions
Store embeddings
```

Expected model lifecycle:

- OCR model loaded/used/unloaded once for the OCR phase.
- Embedding model loaded/used/unloaded once for the embedding phase.
- Classifier model loaded/used/unloaded once for the classification phase.
- Judge model loaded/used/unloaded once for the judge phase.

## Proposed Data Structures

Add intermediate dataclasses in `app/pipeline/processing_models.py`.

### ClassificationDraft

Represents the result of the classifier before judge/store.

Fields:

- `document: PaperlessDocument`
- `context_docs: list[PaperlessDocument]`
- `similar_results: list[SimilarDocument]`
- `initial_result: ClassificationResult | None`
- `raw_response: str | None`
- `error: str | None`

### JudgedDraft

Represents the final reviewed classification before persistence.

Fields:

- `document: PaperlessDocument`
- `context_docs: list[PaperlessDocument]`
- `similar_results: list[SimilarDocument]`
- `initial_result: ClassificationResult`
- `raw_response: str`
- `judge: JudgeOutcome`
- `error: str | None`

### StoredSuggestionResult

Represents a stored suggestion and post-processing decision.

Fields:

- `document: PaperlessDocument`
- `suggestion: SuggestionRow | None`
- `result: ClassificationResult | None`
- `will_auto_commit: bool`
- `error: str | None`

## Refactor Steps

### 1. Split current `phase_classify`

Replace current monolithic `phase_classify` with separate functions:

- `phase_classify(...) -> list[ClassificationDraft]`
- `phase_judge(...) -> list[JudgedDraft]`
- `phase_store_suggestions(...) -> list[StoredSuggestionResult]`
- `phase_postprocess_suggestions(...) -> tuple[classified, auto_committed, errored]`
- `phase_store_embeddings(...) -> None`

### 2. Keep idempotency and pending marking unchanged

`process_batch(...)` should still:

- skip unchanged documents unless `force=True`
- mark selected documents as pending
- create one `poll_cycles` row
- update progress counters

### 3. Classification phase

For each document:

- build context docs from `embed_results`
- call `classifier.classify(...)`
- record timing for `classify`
- emit job event:
  - `classify_started`
  - `classify_done`
  - `classify_failed`
- do **not** judge, store, notify, or commit here

After phase:

- unload classifier model if applicable

### 4. Judge phase

For each successful `ClassificationDraft`:

- call `maybe_run_judge(...)`
- record timing for `judge`
- emit job event:
  - `judge_started`
  - `judge_agreed`
  - `judge_corrected`
  - `judge_skipped`
  - `judge_failed`
- produce `JudgedDraft`

Important: if a separate judge model is introduced, configure and unload it here, not in the classify loop.

### 5. Store suggestions phase

For each successful `JudgedDraft`:

- call `store_suggestion(...)`
- determine `will_auto_commit`
- emit job event:
  - `suggestion_stored`
- do **not** notify or commit yet

### 6. Post-processing phase

For each stored suggestion:

- if `will_auto_commit`:
  - build `ReviewDecision`
  - call `commit_suggestion(...)`
  - emit `auto_commit_started` / `auto_committed` / `auto_commit_failed`
- else:
  - call `notify_suggestion(...)`
  - emit `notification_sent` or `pending_review`

This phase is allowed to use Telegram/Paperless I/O, but it should not invoke LLM models.

### 7. Embedding storage phase

Move final `context_builder.store_embedding(...)` out of classify loop.

For each document with precomputed embedding:

- store embedding
- emit `embedding_stored` or `embedding_store_failed`

### 8. Progress accounting

Current progress counts should remain document-based.

Recommended behavior:

- `progress.total = len(batch)`
- increment `progress.done` once per document after post-processing completes or after a terminal error
- `progress.succeeded` increments for documents that reach pending review or committed
- `progress.failed` increments for terminal document failures
- `progress.skipped` already counts idempotency skips

Need to avoid double-counting failures across classify/judge/store/postprocess.

### 9. Job event phases

Use these phase names consistently:

- `prepare`
- `ocr`
- `embed`
- `classify`
- `judge`
- `store`
- `postprocess`
- `finalize`

GUI can render these directly.

### 10. Tests to add/update

Add unit tests for:

1. Classification happens for all docs before judge starts.
2. Judge happens for all successful classifications before store starts.
3. Store happens before notify/auto-commit.
4. Failures in one doc do not prevent other docs from continuing.
5. Progress counters are correct.
6. Job events are emitted in phase order.
7. Auto-commit still works.
8. Telegram notification still only happens for non-auto-committed suggestions.

Suggested test style:

- mock `classifier.classify`
- mock `maybe_run_judge`
- mock `store_suggestion`
- mock `commit_suggestion`
- assert call order and event order

### 11. Backward compatibility

Keep existing public function names where possible:

- `process_batch(...)` should remain the main entry point.
- CLI and worker should not need major changes.
- API endpoints should continue to consume `PollProgress` and `job_events`.

### 12. Risks / Attention Points

- `maybe_run_judge(...)` currently records timing internally; avoid double timing if moving responsibility.
- `JudgeOutcome` contains the corrected/final result; store phase must use `judge.result`, not always `initial_result`.
- `raw_response` should stay the original classifier response, even if judge corrected the result.
- `original_proposed_json` must be preserved when judge corrected.
- Auto-commit confidence should use final judged result confidence.
- Telegram suggestion notification needs the stored suggestion ID.
- Embedding storage currently happens even if classification fails; decide whether to keep this behavior. Recommendation: keep it if embedding succeeded.

## Implementation Order

1. Add dataclasses.
2. Extract classify-only phase while preserving behavior.
3. Extract judge phase.
4. Extract store phase.
5. Extract postprocess phase.
6. Extract embedding storage phase.
7. Adjust progress/events.
8. Add/adjust tests.
9. Run full targeted test suite and frontend build.

## Success Criteria

- No model-heavy classify/judge interleaving remains.
- Pipeline logs/events clearly show separate `classify` and `judge` phases.
- Existing review suggestions still look the same to users.
- Auto-commit and Telegram behavior remain intact.
- Processing UI shows clearer phase progression.
- Tests cover phase ordering and failure isolation.
