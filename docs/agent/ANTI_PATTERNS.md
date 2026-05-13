# Agent Anti-Patterns

Repo-specific approaches to avoid.

- Broad unrelated refactors or mass formatting while fixing a focused issue.
- Weakening review queues, whitelist flows, audit logging, authentication, or MCP token checks for convenience.
- Writing OCR-corrected text back to Paperless content.
- Treating inbox/unreviewed documents as trusted classification examples.
- Showing raw numeric IDs or raw JSON as the primary UI representation for user-facing metadata.
- Introducing cloud services, hosted databases, or telemetry into the local-first architecture without explicit approval.
- Removing explicit OpenAI-compatible embedding parameters and relying on library defaults that may send `encoding_format: null`.
- Adding dependency or image `latest` pins, or bypassing the 3-day dependency age check without a documented security exception.
- Reading, printing, or storing `.env` secrets or private document contents in docs, logs, tests, or memory files.
