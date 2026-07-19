# Authorization-safe RAG redesign

## Status and non-enablement boundary

**Design research complete; proposal unapproved; Chat/RAG remains disabled.** This document is the design record for [Issue #221](https://github.com/pfriedrich84/archibot/issues/221). It is not an implementation decision or re-enable authorization. Existing chat rows remain preserved and unexposed; no page, API/CLI command, MCP retrieval tool, prompt/provider setting or compatibility route may be registered.

## Security objective

For every answer, an authenticated Paperless user may influence or observe only documents they are permitted to view **at the time of retrieval and again before response release**. Authorization must occur before document text/snippets reach an AI provider. ArchiBot administrator status is not a Paperless document-access grant. Failure or ambiguity is denial.

Threats include cross-user nearest-neighbor hits, stale grants, revoked permissions during a request, source-title/snippet leakage, answer inference from excluded documents, chat-history replay, logs/diagnostics, embedding inversion, prompt injection and a privileged service token accidentally replacing requesting-user identity.

## Proposed request and identity contract

1. Laravel authenticates the ArchiBot session/MCP token and resolves the requesting ArchiBot user.
2. Laravel loads that user's write-only Paperless token context. No admin/service token fallback is allowed.
3. Laravel creates a short-lived, single-request retrieval authorization envelope containing an opaque request ID, ArchiBot user ID, Paperless principal binding, canonical Paperless origin, purpose, issued/expiry time and nonce. It contains no reusable Paperless token in queue payloads/logs.
4. Python receives the envelope through an allowlisted, authenticated Laravel-owned seam and cannot substitute identity. Laravel remains the authorization owner under ADR-0019; Python applies the resulting candidate allowlist before reading PostgreSQL content.
5. Audit records identify actor/principal, request, decision counts and policy version without query, document text, snippets, token or prompt content.

**Open design question:** use a same-process/short-lived capability or a Laravel callback for candidate authorization. A signed bearer capability is acceptable only after replay, key rotation and audience review. A durable queue message carrying a reusable user token is prohibited.

## Retrieval and live ACL algorithm

The shared embedding index may remain a coarse candidate generator only if the implementation proves that vectors and metadata are never exposed directly and filtering precedes content access:

1. Embed the user's query with the approved installation-wide provider. Do not fetch document text yet.
2. Query PostgreSQL only for an over-fetched set of opaque Paperless document IDs and distances. The query must not select title, content, OCR, snippet, filename or user-facing metadata.
3. In bounded batches, Laravel checks each candidate with the requesting user's Paperless context. A live authorized document fetch (or a documented Paperless permission endpoint that is equivalently authoritative) is the grant. `403`, `404`, timeout, malformed response, rate limit, redirect or partial batch is denial.
4. Return a request-scoped allowlist with per-document authorization epoch/expiry. Python fetches content only for those IDs. Inbox/unreviewed documents remain excluded as trusted context even when viewable.
5. Immediately before model submission, verify the capability is current and every source remains allowlisted. Build the prompt solely from allowed, redacted sources.
6. Immediately before response release, revalidate all cited/influential document IDs. Any denial, error, expiry or permission change discards the generated answer and sources; no partial answer is released. Retry begins from candidate generation with a fresh authorization context.
7. Persist chat history only after release authorization succeeds. Persist source references as opaque IDs plus policy epoch, not copied snippets/content. Every history read reauthorizes source documents and redacts/removes inaccessible turns before any model call or browser response.

A permission cache, if later proposed, may cache **denials** briefly. Cached grants are not sufficient for model submission or release. The initial implementation should use live checks.

## Revocation and indexing behavior

- Permission changes do not require deleting shared vectors to become effective; live pre-content and pre-release checks are the enforcement boundary.
- Revocation during a request invalidates the whole generated answer because excluded text may already have influenced it.
- Deleted/inaccessible IDs are tombstoned asynchronously for index hygiene, but tombstoning is not authorization.
- Group membership, ownership and superuser changes invalidate any local grant cache by expiry; webhook hints may accelerate invalidation but cannot grant access.
- Existing chat sessions are quarantined on re-enable. Their titles/previews/messages/sources are not migrated into visible history until each row has ownership provenance and every source is reauthorized. Rows without provenance remain preserved but unavailable.

## Source and output redaction

Only fields required for the answer may enter the model prompt. Apply deterministic redaction before provider access and again before response presentation:

- omit Paperless internal IDs unless needed as opaque server-side keys;
- omit storage paths, original filenames, owner/group lists, custom fields, notes and OCR snapshots unless a separately approved use case requires them;
- cap and label snippets; never expose embeddings, distances, hidden prompts or excluded candidate counts;
- citations resolve server-side after live authorization and render a safe title or generic `Authorized document` label;
- if a source loses access, remove the complete citation and any source-derived answer rather than leave a placeholder that confirms existence;
- logs, traces, diagnostics and metrics use counts, stable non-reversible references and canonical reason codes only.

Provider output is untrusted. It cannot add a citation that was not in the final allowlist, request tools, change authorization or recover redacted fields.

## Trust boundaries

| Boundary | Permitted data | Required control |
| --- | --- | --- |
| Browser/MCP → Laravel | Query and authenticated session/token | CSRF/token validation, rate/size limits, user binding; no global/admin fallback. |
| Laravel → Paperless | Requesting user's Paperless token and candidate IDs | Canonical pinned origin, no redirects, bounded calls, live view checks, secret never logged/persisted in jobs. |
| PostgreSQL vector search | Query vector, opaque document IDs/distances | No content/snippet projection before ACL allowlist; database is not an authorization oracle. |
| Laravel → Python | Short-lived identity/capability and allowed IDs | Audience/purpose/expiry/replay protection; fixed interface; sanitized audit. |
| Python → AI provider | Query plus content from currently allowed/redacted sources | Approved installation-wide provider only; local-first; a cloud endpoint requires explicit documented approval. |
| Python/Laravel → browser | Answer and currently authorized citations | Final ACL recheck, citation allowlist, deterministic redaction, no partial release after failure. |
| Chat storage | Authorized post-release history and opaque provenance | Per-owner access, reauthorization on every read, retention/deletion policy, no old-row exposure by default. |

The corresponding runtime table in [trust boundaries](../governance/trust-boundaries.md) remains authoritative and must be updated when a concrete flow is approved.

## Required negative and cross-user tests

Use synthetic documents with distinguishable canary facts; no private fixtures.

| Scenario | Required result |
| --- | --- |
| User A only / User B only / shared documents in nearest-neighbor results | Each answer and citation contains only requester's allowed canaries; excluded canaries cannot alter answer, refusal wording or counts. |
| ArchiBot admin denied by Paperless | Same denial as non-admin; no bypass. |
| Service/admin Paperless token exists | Request capture proves it is never used for user RAG. |
| Permission revoked before candidate check | Candidate denied; content is never loaded. |
| Revoked after content load but before provider call | Prompt is not sent. |
| Revoked during provider call or before release | Entire answer/sources discarded and history not persisted. |
| Paperless timeout/429/5xx/malformed/partial batch | Fail closed without existence, count, title or timing-specific disclosure. |
| Deleted document/stale vector | No content/citation; safe tombstone scheduling only. |
| Source-title/filename/storage-path canaries | Prohibited fields absent from prompt, answer, citation, log and diagnostics. |
| Prompt injection requests other documents/tools | No authorization expansion or unauthorized tool call. |
| Existing session owned by another user or missing provenance | `404`/unavailable without content or existence leakage. |
| Group membership changes and simultaneous requests | Fresh authorization per request; no cross-request allowlist/cache bleed. |
| MCP, web and any future CLI seam | Identical identity, ACL, audit and denial semantics. |

Tests must assert provider request bodies and persistence side effects, not only HTTP status. Include concurrency tests for revocation and capability replay/expiry.

## Explicit approval gates

Re-enable requires all of these, in order:

1. maintainer acceptance of intended users, identity/capability design, provider boundary and shared-index threat model in a new or amended ADR;
2. implementation behind no public route, followed by independent security review;
3. all negative/cross-user/revocation tests above passing against representative Paperless permission behavior;
4. migration review for historical sessions and a rollback that returns to no routes/tools without deleting rows;
5. trust-boundary, user/admin documentation and operator-visible disabled/rollback state;
6. explicit product **and** security approval to register each web/API/MCP surface. Approval of design or tests alone is not approval to re-enable.

## Open questions

- Is answer generation needed for non-admins, or should the first approved cohort be narrower while preserving the same Paperless ACL rules?
- Which Paperless endpoint/batch shape provides authoritative live view decisions without leaking inaccessible IDs?
- Is a shared embedding index acceptable after an embedding-inversion threat review, or is encryption/segmentation required?
- What retention and deletion semantics should apply to newly created chat history?
- May the installation-wide AI provider receive document text, and how is operator consent represented?
