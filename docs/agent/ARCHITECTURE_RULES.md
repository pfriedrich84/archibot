# Architecture Rules

## Hard invariants

1. Existing Paperless storage paths are immutable.
   ArchiBot may only set a storage path if the document has none.

2. Unknown entities must go through approval.
   New tags, correspondents and document types must not be silently created.

3. Inbox documents are not trusted context.
   Only reviewed/confirmed documents may be used as classification examples.

4. OCR corrections stay local.
   Corrected OCR text must never be written back to Paperless content.

5. The system must degrade gracefully.
   If OCR, embeddings, judge or Telegram fail, the pipeline should continue where safe.

6. Docker-first.
   The main deployment target is one container with persistent `/data`.

## Preferred design

- Python owns document processing, embeddings, Ollama and MCP runtime.
- Laravel/Svelte owns user-facing UI, setup, settings, review and worker jobs.
- SQLite is acceptable and preferred for local/self-hosted simplicity.
- Avoid splitting into many services unless there is a strong reason.
