# Agent Autoresearch

Optional workflow for autonomous, metric-driven experiment loops. Use it only when the task has a measurable target and repeated iterations are useful.

## Good fits

- Performance optimization: pipeline latency, embedding throughput, OCR/rendering speed, Laravel/frontend build time.
- Prompt/model experiments: classification quality, judge thresholds, OCR correction quality, RAG answer quality.
- UI improvements with measurable outcomes: fewer clicks, clearer review flow, smaller bundle, faster page load, accessibility fixes with automated checks.
- Best-practice hardening: dependency/security posture, error handling, observability, test coverage, maintainability metrics.
- Regression hunting where each iteration can be validated by a stable command or benchmark.

## Poor fits

- Documentation-only cleanup.
- Broad refactors without a primary metric.
- Security-sensitive behavior changes without explicit review.
- Changes requiring real user documents or secrets in logs.
- Product decisions where the right answer is subjective and needs human choice first.

## Required setup

Before starting an experiment loop, define:

1. **Primary metric** — one number to optimize, with direction (`lower` or `higher`).
2. **Benchmark command** — deterministic enough to compare iterations.
3. **Safety checks** — tests/lints that must still pass.
4. **Rollback rule** — discard experiments that do not improve the primary metric or fail checks.
5. **Data boundary** — never log document contents, secrets, tokens, or private Paperless data.

## Suggested metrics

### Python pipeline

- Classification wall time per document.
- Embedding throughput in documents/second.
- OCR correction time per page.
- Context-builder latency.
- Test fixture classification accuracy.

### Laravel / Svelte UI

- Frontend bundle size.
- `npm run build` duration.
- Svelte type-check/lint failures.
- Review-flow click count for common tasks.
- Accessibility issues from automated checks when available.

### Quality / best practices

- Test coverage for the touched subsystem.
- Number of flaky or skipped tests.
- Static analysis findings.
- Dependency age/security findings.
- Error rate in controlled fixtures.

## Experiment rules

- Keep each iteration small and reviewable.
- Preserve ArchiBot invariants from [`RULES.md`](RULES.md).
- Run relevant checks from [`CHECKS.md`](CHECKS.md) after each kept result.
- Prefer fixture data over real user documents.
- Record failed ideas and why they failed, so they are not repeated.
- Do not keep a change solely because a secondary metric improved; optimize the declared primary metric.

## UI improvement guidance

For UI-focused autoresearch, prefer measurable, user-visible improvements:

- Make review decisions faster and harder to mis-click.
- Improve empty/error/loading states.
- Reduce visual noise without hiding audit-relevant information.
- Preserve keyboard accessibility and responsive layouts.
- Keep safety actions explicit: approve, reject, whitelist, and commit paths must remain clear.

If a UI change is subjective, stop after producing alternatives and ask for human selection instead of auto-optimizing indefinitely.
