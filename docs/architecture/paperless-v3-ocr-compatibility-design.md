# Paperless-ngx 2.20/v3 OCR and API compatibility research

## Status and scope

**Research deliverable complete; compatibility implementation is not started.** This is the design record for [Issue #222](https://github.com/pfriedrich84/archibot/issues/222) and hardening plan 4.2. Every integration choice below is a **proposal** until separately approved and implemented. It does not enable remote OCR, install a parser plugin, upload a file version, or permit ArchiBot to write Paperless document `content`.

The invariant remains: ArchiBot OCR corrections are local review data. Paperless is authoritative for source files, versions, metadata, permissions and effective content. No new cloud data flow is approved.

## Upstream evidence

Reviewed 2026-07-18 against Paperless-ngx `v2.20.15` and the upstream `v3.0.0-beta.rc1` prerelease:

- [v3.0.0-beta.rc1 release notes](https://github.com/paperless-ngx/paperless-ngx/releases/tag/v3.0.0-beta.rc1) announce remote OCR, document file versions, the parser-plugin framework, decoupled OCR/archive controls, use of effective content for matching/suggestions, removal of API v1 compatibility and removal of API versions below 9.
- [v2.20.15 API versioning documentation](https://github.com/paperless-ngx/paperless-ngx/blob/v2.20.15/docs/api.md#api-versioning) documents Accept-header negotiation and `X-Api-Version`/`X-Version`; its changelog documents API v9, while an omitted version deliberately falls back to v1 for old-client compatibility.
- [v3 API versioning documentation](https://github.com/paperless-ngx/paperless-ngx/blob/v3.0.0-beta.rc1/docs/api.md#api-versioning) lists versions 9 and 10, a default of 10, `406` for unsupported versions and the authenticated response-header compatibility probe.
- [v3 document-version API](https://github.com/paperless-ngx/paperless-ngx/blob/v3.0.0-beta.rc1/docs/api.md#document-versions) says root metadata/permissions are shared while file, checksums, archive data and extracted content are version-specific; reads default to the latest version.
- [v3 parser-plugin documentation](https://github.com/paperless-ngx/paperless-ngx/blob/v3.0.0-beta.rc1/docs/advanced_usage.md#installing-third-party-parser-plugins) describes separately installed Python entry-point packages and explicitly says third-party plugins are unsupported by upstream.
- [v3 remote-OCR configuration](https://github.com/paperless-ngx/paperless-ngx/blob/v3.0.0-beta.rc1/docs/configuration.md#remote-ocr) currently names Azure AI, requires credentials/endpoint, defaults off, and notes that its parser always creates a searchable archive PDF.

The v3 evidence is prerelease evidence, not a stable compatibility guarantee. Recheck the stable 3.x release notes and schemas before implementation.

## Compatibility matrix

| Concern | Paperless 2.20.x | Paperless 3.x candidate | ArchiBot proposed contract |
| --- | --- | --- | --- |
| Supported baseline | Stable 2.20 line; API v9 available | v3 prerelease supports API v9/v10; versions below 9 removed | Support tested 2.20.x and stable 3.x only after conformance passes; pin API v9 as their deliberate common contract. |
| Version detection | Authenticated response supplies `X-Api-Version` and `X-Version` | Same documented probe | Probe once per configured origin/token context; validate both headers. Never infer compatibility from HTTP success alone. |
| Document DTO | One effective document file/content model | Root metadata plus latest/selected version content | Normalize into an explicit `{root_document_id, selected_version_id|null, modified, content_hash, effective_content}` contract; never let a missing version identifier silently mean an old cached file. |
| File versions | No v3 version API | Latest version is implicit; a version can be selected; update/delete endpoints exist | Read latest only in the initial compatibility implementation. Record selected version identity in pipeline/index provenance. Do not call update-version/delete-version endpoints. |
| Effective content | `content` is the processing/indexing input | Matching/suggestions use effective/latest-version content | Hash and embed the exact effective content fetched under the selected version. A changed version/effective-content hash triggers normal dedupe/reprocess; a metadata-only event does not pretend content is unchanged if identity is unavailable. |
| Webhooks | Existing document events and reconciliation | Version changes can update the root; upstream adds version-oriented workflow behavior | Treat webhook as a hint, then perform an authorized/current API read. Reconciliation uses the same normalization. Never trust a webhook version/content field as authoritative. |
| OCR controls | Paperless-owned local OCR/archive behavior | OCR and archive generation decoupled | ArchiBot does not configure Paperless OCR. Its local correction lifecycle remains unchanged. |
| Remote OCR | Not part of this baseline | Optional Azure AI parser; private files cross a new provider boundary | Unsupported by ArchiBot. Do not configure, invoke or proxy it. Operators who independently enable it in Paperless own that separate flow; ArchiBot merely reads Paperless's effective content. |
| Parser plugins | No proposed ArchiBot seam | Third-party package inside Paperless consumer process | Do not build an ArchiBot parser plugin now. It would couple release/dependency/security ownership and could mutate authoritative content during consumption. Reconsider only through supply-chain/threat review and an ADR. |
| OCR/content writes | Paperless API can accept content changes | Content PATCH targets latest/selected version | Prohibited in both. Metadata patch allowlists must reject `content`, files and version selectors. Local OCR records never invoke these endpoints. |
| Permissions | Per-document Paperless permissions | Root permissions are shared by versions | Always authorize the root document live before any local OCR/version content read or mutation; version selection never expands access. Fail closed. |
| Rollback | Existing v2 behavior | New version identity may exist in durable provenance | Rollback may ignore optional version columns only after export; it must not overwrite/delete Paperless versions or local OCR history. |

## Proposed API-version negotiation

1. Both Laravel and Python must consume one version-policy value and contract fixture; neither chooses independently.
2. Make an authenticated, bounded, no-redirect request to a harmless endpoint using `Accept: application/json; version=9`.
3. Require success plus parseable `X-Api-Version` and `X-Version`. Require negotiated API version `9`; reject `406`, missing/malformed headers, a different negotiated version, and server versions outside the approved 2.20.x/stable-3.x ranges.
4. Cache only a non-secret capability result scoped to canonical Paperless origin and credential context, with bounded lifetime. Re-probe after authentication/session change and after a `406` or incompatible DTO.
5. Send API v9 on every JSON request. Binary download/preview requests may use `Accept: */*`, but their authorization, origin and response bounds remain identical.
6. Do **not** downgrade to v5, omit the header, or retry through lower versions. A future move to v10 is a separately reviewed contract change.

This replaces the current hard-coded v5 behavior only when implementation is approved. Until then, this document changes no request.

## Required contract and integration tests before support is claimed

| Test | Required proof |
| --- | --- |
| v2.20 negotiation | A representative server accepts v9, returns expected headers and baseline DTO/pagination shapes. |
| v3 negotiation | A representative stable v3 server accepts v9 even when its default is newer. |
| fail closed | `406`, redirects, absent/invalid headers, unsupported server version, HTML/error bodies and oversized responses produce a compatibility error and no pipeline dispatch. |
| no downgrade | Request capture proves no v5/headerless retry follows failure. Laravel and Python emit the same result. |
| pagination | Relative next links work; cross-origin, protocol-relative and malformed next links are rejected. |
| DTO variants | v2 root and v3 root/latest/explicit-version fixtures normalize deterministically without private real-document data. Unknown fields are ignored only when required identity/content fields remain valid. |
| version races | Latest version changing between metadata and content fetch causes retry/reconciliation, never mixed provenance. |
| webhook/reconciliation | Duplicate, reordered and version-added events converge on one content state and one ordinary run; explicit force reprocess still creates a new run. |
| indexing | Embedding provenance includes root ID, selected version identity (when available), effective-content hash and API contract version; stale content cannot remain eligible. |
| permissions | Access revocation between event, metadata fetch and content fetch prevents local OCR/content loading and leaves no snippet/log disclosure. |
| mutation guard | Request capture proves local OCR approve/reject performs no Paperless PATCH; all metadata PATCH payloads reject `content`, file/version fields and existing storage-path replacement. |
| cloud-flow guard | No test or runtime path sends a document to Azure/remote OCR or installs/loads a Paperless parser plugin. |

Use synthetic fixtures for both API generations and an upstream container matrix for the final claim. Prerelease fixtures may inform development but cannot certify stable v3.

## Recommendation and gates

**Recommendation:** retain ArchiBot's fully local OCR correction module, adopt API v9 negotiation and version-aware read provenance only, and defer parser-plugin/remote-OCR integration. File versions are an input-identity concern, not a write seam.

Implementation requires all of the following:

- stable Paperless 3.x documentation/schema recheck and maintainer compatibility approval;
- contract tests in both clients plus representative 2.20/stable-3.x integration runs;
- security review of permission timing, provenance, webhook races and metadata-write allowlists;
- migration/rollback plan for any stored version identity;
- explicit ADR and trust-boundary update before any parser plugin, remote OCR, content PATCH or new cloud flow.

## Open questions

- Which stable 3.x release becomes the minimum certified target, and does it retain API v9 for the support window?
- Which stable v3 field is the durable selected-version identity in list/detail/webhook payloads?
- Can one atomic endpoint provide root metadata, selected-version identity and effective content, or is an optimistic recheck required?
- Which version-change webhook fields survive into stable v3, and what ordering guarantees exist?
