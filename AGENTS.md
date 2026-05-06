# AGENTS.md — ArchiBot

Zentrale Einstiegsdatei fuer Coding-Agents in diesem Repository.

## Schnellstart

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

## Tech stack

- Python Worker/CLI fuer Paperless, Ollama, Embeddings und MCP.
- Laravel + Inertia/Svelte fuer UI/API.
- SQLite + sqlite-vec fuer State und Similarity Search.
- Paperless-NGX REST API als externe DMS-Quelle.
- Ollama fuer lokale LLMs und Embeddings.

## Validation

Before finishing code changes, run the relevant checks from [`docs/agent/CHECKS.md`](docs/agent/CHECKS.md).
