# Safe automation eligibility research and experiment design

## Status and boundary

**Research/design deliverable complete; proposal unapproved; no automation is enabled.** This document defines a possible future path beyond [ADR-0018](../decisions/0018-suspend-model-confidence-auto-commit.md). `AUTO_COMMIT_CONFIDENCE` remains ineffective. Model or judge confidence, agreement, prose, chain-of-thought or document instructions cannot accept a suggestion, queue a commit or authorize a Paperless write.

The purpose is to define what evidence would be required before a separately approved, deterministic safe-automation implementation could even be proposed.

## Unit of eligibility

Eligibility is evaluated **per proposed field change**, then for the whole change set. One ineligible field makes the complete suggestion manual-review-only; automation must not partially apply a model-created bundle because that can hide context from the reviewer.

A deterministic gate consumes only versioned structured facts fetched after live authorization: current Paperless metadata, immutable proposal payload, entity-approval state, document/content identity, cohort configuration and prior operator-authored labels. It does not consume free-form model rationale as authorization evidence.

## Field-level proposal

| Field/change | Deterministic eligibility proposal | Prohibited changes |
| --- | --- | --- |
| Correspondent | Exact ID already exists, is approved/allowlisted, belongs to eligible cohort, and proposal is one replace-from-null operation. | Creating an entity; replacing a non-null value; ambiguous name-to-ID resolution; blacklisted/rejected entity. |
| Document type | Exact existing approved ID, eligible cohort, replace-from-null only. | Create, replace non-null, delete, or infer an unapproved ID. |
| Tags | Add-only set of exact existing approved IDs from a cohort-specific allowlist; preserve inbox and all existing tags. | Remove any tag; add inbox-removal marker; create/rename tag; exceed configured count; alter permissions/workflow-control tags. |
| Created date | **Manual only in initial proposal.** | Any automatic date change, timezone coercion or overwrite. |
| Title | **Manual only.** | Any automatic free-text replacement. |
| Storage path | Never eligible. | Set, replace, clear or derive a storage path. Existing value is authoritative. |
| Owner/permissions/custom fields/notes/content/files/versions | Never eligible. | Any change. OCR/content write-back and file/version mutation remain prohibited. |

The storage-path prohibition above is specific to safe automation. It does not remove the existing explicit manual-review seam, which may fill a storage path only after a fresh Paperless read proves the live value is `null`; a missing field or existing value fails closed. That manual exception cannot be called by dry-run or any future canary command.

Additional all-or-nothing gates:

- the explicitly delegated principal described below has current Paperless change permission immediately before durable command creation and again before the actor PATCH;
- source document/version/effective-content hash and current metadata exactly match the evaluated snapshot;
- document is in an explicitly configured eligible cohort and not in a holdout, quarantine or inbox/unreviewed state;
- every target entity was human-approved before the proposal was generated; no model-created approval;
- payload passes a strict field allowlist and canonical diff; unexpected keys fail closed;
- no prior rejection, conflicting pending suggestion, legal/retention hold, permission uncertainty or detected malformed/adversarial-content flag;
- one idempotency key ties eligibility decision, dry-run record, command and resulting PATCH.

## Principal and delegation model

Safe automation has **no ambient system, installation-owner, service-account, superuser or ArchiBot-admin fallback**. A missing, expired, revoked or ambiguous principal always abstains. Administrator status in Laravel does not grant Paperless document permission.

The prospective dry-run is deliberately incapable of mutation: it creates no Paperless write command, invokes no mutating client method and stores only a sanitized `would_apply`/`abstain` evaluation linked to the immutable suggestion snapshot. It may use the viewing operator's linked identity to evaluate permission, but that identity cannot be converted into an unattended writer.

Any future canary requires a separately approved, explicit delegation with all of these properties:

- a named, least-privilege Paperless user and dedicated revocable token, restricted to the canary cohort rather than a Paperless superuser or shared installation token;
- one linked Laravel principal and immutable delegation ID; the command, evaluation, audit event and PATCH all carry that principal/delegation identity;
- a live per-document Paperless change-permission check when eligibility is evaluated, immediately before command creation, and immediately before PATCH; revocation or an unavailable check fails closed without retrying under another identity;
- bounded field/cohort scope, expiry, daily budget and purpose in a versioned delegation record; no implicit widening from Laravel roles, queue-worker identity, deployment credentials or token lookup fallback;
- auditable grant, use, denial, revocation and rotation events containing stable references but never the token; dual-control approval for grant/rotation and immediate kill/revocation support;
- rotation creates a new delegation version and invalidates queued work under the old version. Tokens are write-only, encrypted at rest, never copied into command payloads/logs, and old tokens are destroyed after the documented rollback window.

Required negative tests prove: no principal and ambient admin/system identities abstain; dry-run dispatches no command and performs no HTTP mutation; a cross-user document is denied; permission revoked before command or PATCH prevents the write; delegation expiry/scope/budget mismatch fails closed; token rotation invalidates stale queued work; and audit records identify the exact delegation version without secret material. These tests and an independent security review are approval gates, not deferred canary follow-ups.

## Evidence independent of model confidence

Confidence is neither a feature nor a label for authorization. Candidate evidence must be derived from operator-confirmed outcomes and deterministic strata:

- compare immutable proposed field IDs with later human decisions on the same snapshot;
- label exact-match true positive, false positive, abstention and stale/conflict independently per field;
- separate first-time assignment from replacement (replacement remains prohibited initially);
- stratify by cohort, field, language, MIME/source type, OCR quality band, entity frequency and adversarial flag;
- use a time-ordered holdout collected after rule freeze; documents from one logical series/sender must not cross train/calibration/test partitions;
- report binomial confidence intervals for harmful-write rate and recall/coverage; zero observed failures is not zero risk;
- manually adjudicate a blinded random sample plus every disagreement, stale snapshot and proposed automated case;
- treat judge/model agreement only as descriptive telemetry, never eligibility.

**Proposed statistical gate (open for maintainer/risk-owner approval):** per field/cohort cell, at least 1,000 independently adjudicated eligible opportunities, at least 200 proposed-positive cases, and zero harmful false-positive writes in the locked prospective holdout; additionally, the one-sided 95% upper confidence bound for harmful false-positive rate must be below the approved field-specific risk budget. Because zero failures in 1,000 still has an upper bound of roughly 0.3%, a materially lower risk budget requires a larger sample. No cells may be pooled after results are seen.

These numbers are experiment minima, not accepted product risk. The maintainer/security owner must define the actual harm budget before data collection.

## Cohorts and rollout proposal

A cohort is a versioned allowlist of Paperless instance, field, existing target entity IDs, source/workflow type, language and MIME family. It must never be inferred by a model.

1. **Offline shadow cohort:** historical synthetic/redacted or operator-labeled data; no writes.
2. **Prospective dry-run cohort:** live suggestions evaluated and logged as `would_apply`/`abstain`, but all remain pending manual review.
3. **Canary cohort (future approval only):** smallest low-impact field/entity cell, bounded daily write budget and immediate kill switch.
4. **Expanded cohort:** one pre-registered cell at a time after a fresh review period.

At least 20% of otherwise eligible cases remain a permanent randomized holdout during dry-run/canary evaluation. Rare entities, new entities, changed rules and low-volume cells remain manual-only until they independently meet minima.

## Adversarial matrix

| Class | Examples | Required deterministic outcome |
| --- | --- | --- |
| Prompt injection | “Ignore policy”, fake JSON/system messages, instructions to set tags/path | Abstain/manual; no gate input is taken from instructions or rationale. |
| Malformed OCR | Empty/truncated text, encoding noise, repeated pages, mixed scripts, OCR/model mismatch | Abstain when quality/identity rules fail; no OCR write-back. |
| Conflicting context | Body vs filename, multiple correspondents/dates, trusted neighbors disagree, duplicate versions | Abstain; context agreement cannot authorize. |
| Permission race | View/change revoked after suggestion or eligibility check | No command/PATCH; stale record with canonical reason. |
| Snapshot race | Metadata, file version, effective content or tags change | No PATCH; re-evaluate only through a new suggestion/run. |
| Entity attack | Homoglyph/duplicate names, unknown ID, recently created/rejected/blacklisted entity | Abstain; exact pre-approved ID required. |
| Field smuggling | Nested/unexpected keys, null coercion, tags remove+add, mass assignment | Reject whole payload before queueing. |
| Safety-boundary change | Existing storage path, permissions, owner, content, file/version, title/date | Prohibited regardless of evidence. |
| Cohort gaming | Near-threshold rare entity, post-hoc cell merge, repeated duplicate documents | Abstain or deduplicate; retain pre-registered strata. |
| Provider failure | Timeout, malformed response, judge disagreement | Pending manual review; no eligibility default. |

Tests use synthetic canary values and assert durable command/Paperless HTTP side effects are absent.

## Dry-run records, metrics and operator reasons

Every evaluation emits a structured, sanitized record tied to policy version and snapshot. Allowed canonical reasons include:

- `eligible_existing_approved_entity_add_only`
- `manual_field_not_eligible`
- `prohibited_field`
- `entity_not_approved`
- `replacement_not_allowed`
- `document_not_in_cohort`
- `inbox_or_untrusted_document`
- `adversarial_or_malformed_content`
- `snapshot_changed`
- `permission_unverified`
- `conflicting_suggestion`
- `holdout_assignment`
- `daily_budget_exhausted`

Operators see a field-by-field diff, rule/policy version, `would apply` or `manual review`, canonical localized reasons and current cohort/holdout state. They do not see raw model JSON, chain-of-thought or private content in diagnostics.

Required metrics by pre-registered field/cohort cell:

- opportunities, proposed positives, eligible, abstained, would-apply and manually reviewed counts;
- exact-match true/false positives, harmful false positives, stale/conflict/permission denials;
- precision, coverage, abstention rate and one-sided confidence bounds;
- drift by time/language/MIME/entity frequency; permission and snapshot race rates;
- adversarial-suite pass count and prohibited-PATCH count (must remain zero);
- operator override/reject reasons and time-to-detection;
- canary daily budget, kill-switch events and rollback completeness (future phase only).

Metrics must not include document text, OCR, prompts, titles or raw identifiers. Low-count cells are suppressed from shared diagnostics.

## Approval and rollback gates

No implementation may make the threshold effective. A future implementation requires:

1. maintainer/product/security acceptance of a new ADR defining allowed fields, risk budgets, sample independence, cohorts and accountable owner;
2. deterministic gate implementation with deny-by-default payload schema and independent security review;
3. prospective dry-run completion at the pre-registered minima, with complete adverse outcomes and no post-hoc cohort pooling;
4. all adversarial, principal/delegation, permission-race, snapshot-race and prohibited-field tests passing, including proof that dry-run has no command or HTTP mutation sink;
5. the explicit least-privilege Paperless user/token and linked Laravel delegation design receives security approval, with no ambient admin/system fallback and tested revocation/rotation;
6. operator UI for reasons, metrics, bounded budget, immediate global kill switch and audit export;
7. rollback proving automation returns to pending manual suggestions without reverting safety migrations or losing audit evidence;
8. a second explicit product/security approval for a named canary cohort and delegation. Design approval and dry-run approval do not authorize writes.

Any policy/config change, new entity, drift alert, harmful false positive, permission anomaly or missing metric automatically suspends the affected cell to manual review. Model confidence cannot override suspension.

## Open questions

- What harmful-write risk budget is acceptable for each field, and who signs it?
- Are correspondent/document-type assignments from null sufficiently reversible and low-impact for any canary?
- What constitutes an independent sample for recurring document series?
- Which deterministic OCR-quality/adversarial flags are reliable enough to require abstention without becoming model gates?
- How long must prospective dry-run and canary observation windows remain open across seasonal drift?
