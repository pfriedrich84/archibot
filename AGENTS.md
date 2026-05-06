# AGENTS.md — ArchiBot

Zentrale Einstiegsdatei fuer Coding-Agents in diesem Repository.

## Purpose
ArchiBot is a self-hosted AI classifier for Paperless-NGX documents.
It proposes metadata, stores suggestions in a review queue, and only writes to Paperless after approval or explicit auto-commit.

## Read first
Before larger changes, read:

- `docs/agent/PROJECT_BRIEF.md`
- `docs/agent/ARCHITECTURE_RULES.md`
- `docs/agent/WORKFLOW.md`
- `docs/agent/CHECKS.md`
- `docs/architecture.md`
- `docs/workflow.md`
- Ausfuehrlicher Projektkontext: [`docs/agent/project-guide.md`](docs/agent/project-guide.md)
- Architektur und Datenfluss: [`docs/architecture.md`](docs/architecture.md)
- Review- und Freigabe-Workflow: [`docs/workflow.md`](docs/workflow.md)
- Wiederverwendbare Check-/Update-Workflows: [`docs/agent/commands.md`](docs/agent/commands.md)
- Sicherheits- und Tool-Regeln: [`docs/agent/permissions.md`](docs/agent/permissions.md)

## Core rules / Wichtigste Regeln

- ArchiBot bleibt single-container und Docker-first.
- Paperless-Speicherpfade nie ueberschreiben, wenn am Dokument bereits gesetzt.
- Manueller Review bleibt der Standard-Sicherheitsweg.
- Inbox-/unreviewed Dokumente nicht als vertrauenswuerdigen Klassifikationskontext verwenden.
- Vorschlaege bleiben reviewpflichtig; neue Tags/Korrespondenten/Dokumenttypen nur ueber Approval-/Whitelist-Flow anlegen.
- Kleine, gut reviewbare Aenderungen bevorzugen.
- Dokumentation aktualisieren, wenn sich Verhalten aendert.
- Vor Abschluss mindestens die betroffenen Tests ausfuehren; fuer Python-Aenderungen normalerweise `ruff check app/ tests/` und `pytest`.
- Keine Secrets aus `.env` ausgeben oder veraendern.
- Keep ArchiBot single-container and Docker-first.
- Do not overwrite existing Paperless storage paths.
- Do not create new Paperless entities without approval/whitelist flow.
- Keep manual review as the default safety path.
- Do not use inbox/unreviewed documents as trusted classification context.
- Prefer small, reviewable changes.
- Update docs when behavior changes.

## Tech stack

- Python Worker/CLI fuer Paperless, Ollama, Embeddings und MCP.
- Laravel + Inertia/Svelte fuer UI/API.
- SQLite + sqlite-vec fuer State und Similarity Search.
- Paperless-NGX REST API als externe DMS-Quelle.
- Ollama fuer lokale LLMs und Embeddings.
- Python worker/CLI for Paperless, Ollama, embeddings, MCP
- Laravel + Inertia/Svelte for UI/API
- SQLite + sqlite-vec for state and similarity search
- Ollama for local LLM and embeddings 

## Validation

Before finishing code changes, run the relevant checks from:

`docs/agent/CHECKS.md`

If checks cannot be run, clearly state why.
Before finishing code changes, run the relevant checks from [`docs/agent/CHECKS.md`](docs/agent/CHECKS.md).
