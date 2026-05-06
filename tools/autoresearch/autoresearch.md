# Autoresearch: ArchiBot agent readiness and safety contracts

## Objective
Improve ArchiBot in small, metric-driven iterations while preserving the project safety boundaries around Paperless writes, manual review, whitelists, OCR, and local-only data handling.

## Metrics
- **Primary**: `archibot_agent_readiness_score` (unitless, higher is better) — static readiness score from deterministic safety-contract probes in `autoresearch.sh`.
- **Secondary**:
  - `failed_invariants` (count, lower is better)
  - `python_test_files` (count, higher usually indicates better regression coverage)
  - `frontend_source_files` (count, monitoring only)
  - `unit_seconds` (s, lower is better)

## How to Run
From the repository root:

```bash
./autoresearch.sh
```

Safety checks used by the autoresearch harness:

```bash
./autoresearch.checks.sh
```

## Files in Scope
- `app/` — Python worker, CLI, MCP, Paperless/Ollama integration, safety gates.
- `tests/` — regression tests for Python contracts and agent-readiness invariants.
- `laravel/` — UI/API code when an experiment targets measurable frontend quality.
- `docs/agent/` — durable agent rules, checks, workflows, and autoresearch guidance.
- `tools/autoresearch/` plus root wrappers — benchmark/check harness.

## Off Limits
- Benchmark cheating or fake `METRIC` output.
- Logging document contents, secrets, tokens, or private Paperless data.
- Bypassing manual review, tag/entity whitelists, or storage-path preservation.
- Broad unrelated refactors in a single iteration.

## Constraints
- Keep ArchiBot single-container and Docker-first.
- Prefer fixture data and deterministic static/probe checks over real user documents.
- Keep changes small and reviewable.
- Do not keep a change solely because a secondary metric improved.
- Run `./autoresearch.checks.sh` before keeping an experiment.

## What's Been Tried
- Baseline harness installed with a deterministic static safety-contract probe.
