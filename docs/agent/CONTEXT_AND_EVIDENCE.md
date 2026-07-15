# Agent Context and Evidence Discipline

This document is the canonical contract for managing task context, validation evidence, delegated findings, compaction, and recovery. The governing principles are:

> Keep conversational context compact without losing evidence.

> Use context for decisions and findings; use safe local files for raw evidence.

## Load context deliberately

Start with `AGENTS.md` and its applicable read-first documents. Read focused sections first, then expand around unresolved questions, suspicious output, warnings, failures, or changed behavior. A self-selected read window or response target is an initial budget, not a cap on relevant evidence or findings.

Keep lasting architecture decisions, shared contracts, and phase status in their canonical repository documents. Keep task-local checkpoints, command output, and review artifacts out of committed documentation unless they are intentionally part of the reviewed deliverable.

## Maintain three information layers

### 1. Active context

Keep only what is needed for the next safe decision:

- objective, scope, constraints, and permissions;
- repository, branch, `HEAD`, and current patch identity;
- decisions and concise rationale;
- confirmed findings, warnings, and unresolved questions;
- changed files and current validation/review disposition;
- next safe action.

Do not fill active context with complete logs, transcripts, broad diffs, or tool histories. Summarize them and retain complete safe evidence in the appropriate local layer.

### 2. Revision-bound evidence index

Maintain a compact ledger for substantial validation, review, delegation, or long-running work. Each entry must identify:

- repository path, branch, `HEAD`, and whether relevant changes are staged, unstaged, or untracked;
- when the tree is not clean, an exact candidate identity such as base `HEAD` plus a content digest/manifest covering all relevant staged, unstaged, and untracked file contents, or an equivalently immutable safe patch artifact; a prose description alone is insufficient;
- command, working directory, review scope, or manual procedure;
- exit code when applicable and one result state from this document;
- relevant totals, failures, skips, warnings, anomalies, and uncertainties;
- safe local raw-evidence path, collection time, and freshness for the current patch.

A compact entry can use this form:

```markdown
### EV-<number> — <check or review>
- Identity: <repository>; branch <branch>; HEAD <commit>; patch <content digest/manifest or immutable artifact>
- Procedure: <command, cwd, check, review scope, or manual procedure>
- Exit / state: <code or n/a> / <result state>
- Counts: <pass/fail/skip/findings/coverage totals or n/a>
- Warnings/anomalies: <complete compact list or none observed>
- Evidence: <safe local non-committed path>; access <confirmed recipients>
- Freshness: <time>; <current for patch or reason it is STALE>
```

Compactness must never hide a finding, warning, failure, skip, anomaly, uncertainty, suspicious count, or evidence that invalidates an earlier conclusion.

### 3. Local non-committed raw evidence

Store complete safe output outside the repository or under the ignored `.agent-evidence/` directory. Tool-managed local artifacts such as `.pi-subagents/` must also remain ignored. These paths are for logs, complete finding lists, focused diff captures, and recovery checkpoints; they are not product source and must not be committed.

Use an owner-controlled location with restrictive local access where the environment supports it; avoid shared or world-readable directories. Ignore rules are not a security boundary. Never collect or retain secrets, credentials, tokens, keys, certificates, `.env` contents, private Paperless document data, full document/OCR/LLM content, production endpoints, or other sensitive runtime data. Prevent collection or redact at the source. If materially required evidence cannot be retained safely, report `INCONCLUSIVE` rather than storing unsafe data or claiming success.

Retain raw evidence only while it is needed for the active review, recovery, or agreed audit purpose. After that need ends, remove it through an approved cleanup that follows [`SAFETY.md`](SAFETY.md); do not accumulate indefinite local archives.

Before handoff, verify that the responsible reviewer or recovery actor can access every referenced artifact. If filesystems are not shared, provide the complete compact evidence through an approved non-committed channel. Do not commit or upload raw evidence just to make it shareable. The corresponding trust boundary is documented in [`../governance/trust-boundaries.md`](../governance/trust-boundaries.md).

## Use explicit result states

Use these states for checks, reviews, and validation milestones:

- `PASS` — required scope completed and expected semantics were confirmed with current evidence.
- `PASS_WITH_WARNINGS` — required scope completed and expected semantics were confirmed, but stated non-invalidating warnings or follow-up risks remain.
- `FAIL` — current evidence shows that a requirement, check, or expected behavior was not met.
- `INCONCLUSIVE` — coverage or evidence is incomplete, truncated beyond recovery, timed out, unavailable, or semantically unclear. This is not approval and must not be reported as “no findings.”
- `STALE` — evidence applied to an earlier revision or patch, and a relevant later change may affect it. It cannot support the current conclusion until affected checks are rerun.

Exit code zero alone does not establish `PASS`. Inspect expected semantics, executed scope, counts, skips, warnings, truncation, and user-visible behavior. For example, zero tests, an unavailable required tool, a skipped required scan, forbidden output, or an incomplete changed-path selection can make a zero-exit command `FAIL` or `INCONCLUSIVE`.

## Detect truncation and incomplete coverage

Treat tool limits and truncation markers as evidence conditions. Recover omitted relevant output through chunked reads, focused queries, or safe redirected capture while preserving the command result. Never infer success from a visible tail or partial transcript. If complete relevant evidence cannot be recovered, record the missing coverage and use `INCONCLUSIVE`.

No context budget or response-length goal may suppress a finding, failed or skipped check, anomaly, uncertainty, or invalidating observation.

## Keep evidence fresh

After any material edit, identify which evidence it can affect and mark those entries `STALE` immediately. Rerun affected checks before using them to support completion. Changes to behavior, configuration, dependencies, generated inputs, validation logic, or reviewed text are material to corresponding evidence; a working-tree edit can invalidate evidence even when `HEAD` is unchanged.

Use progressive validation:

1. run the narrowest check that answers the current question;
2. rerun affected checks after material edits;
3. broaden validation as integration risk grows;
4. run the final relevant suite after the last material patch.

Do not repeat broad suites when no relevant state changed, but do not skip required final validation to save time or context.

## Delegate without losing coverage

Give each subagent or reviewer an explicit scope, repository identity, constraints, output contract, and validation expectations. Their result must report:

- assigned scope and files or claims inspected;
- revision/patch identity;
- commands or procedures and result states;
- complete findings with locations and severity;
- omitted or incomplete coverage, residual risks, and artifact paths.

Timeout, budget exhaustion, missing output, or truncated coverage is `INCONCLUSIVE`, never approval. The parent agent must reconcile every delegated scope, preserve the complete finding set, disposition unresolved findings, and distinguish independently reviewed work from work that was only delegated.

When a complete finding list is too large for active context, store the complete numbered list as safe local evidence, verify recipient access, surface every high- and medium-severity finding in the active response, and report the total lower-severity count and artifact path. If complete findings cannot be delivered, the review is `INCONCLUSIVE`.

## Checkpoint before compaction or interruption

For long-running, delegated, or interrupted tasks, write a local recovery checkpoint before manual context compaction or handoff. Include:

```markdown
## Verified recovery checkpoint
- Objective / scope:
- Identity: repository / branch / HEAD / patch identity
- Permissions and approval boundaries:
- Decisions and rationale:
- Findings, warnings, and unresolved questions:
- Changed files and purpose:
- Validation entries, states, counts, and freshness:
- Review disposition, finding totals, and artifact paths:
- Next safe action:
- Checkpoint verification time and method:
```

Compact only from a verified checkpoint. Do not collapse unresolved competing hypotheses unless each hypothesis and its evidence remains explicit. On resume, verify repository and patch identity, inspect current status/diff, mark affected evidence `STALE`, and continue from the recorded next safe action rather than trusting conversation memory.

## Completion and handoff

A compact final handoff must identify:

- changed files and purpose;
- current validation/review states, commands, counts, and warnings;
- skipped or incomplete checks and why;
- unresolved findings, risks, assumptions, and follow-ups;
- commit and push state;
- whether referenced evidence is accessible and current for the final patch.

Do not claim completion while a required gate is `FAIL`, `INCONCLUSIVE`, or `STALE`. If full validation cannot complete, report the actual non-approval state and the next safe action.
