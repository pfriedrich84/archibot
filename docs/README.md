# ArchiBot Documentation

Die Dokumentation ist nach Zielgruppe gruppiert.

## User / Betrieb

- [`user/installation.md`](user/installation.md) — Quickstart, Docker-Setup, lokale Entwicklung.
- [`user/configuration.md`](user/configuration.md) — Umgebungsvariablen und Runtime-Settings.
- [`user/workflow.md`](user/workflow.md) — Klassifikation, Review, Tag-/Entity-Freigaben.
- [`user/webhooks.md`](user/webhooks.md) — Paperless-Webhooks fuer sofortige Verarbeitung.
- [`user/deployment.md`](user/deployment.md) — Dockhand, Reverse Proxy, Backup.
- [`user/paperless-auth.md`](user/paperless-auth.md) — Anmeldung, Token-Kontext und Sicherheitsmodell.
- [`operations/event-driven-pipeline.md`](operations/event-driven-pipeline.md) — Betrieb des event-driven Webhook-/Queue-/Recovery-Pipelinepfads.

## Developer

- [`developer/architecture.md`](developer/architecture.md) — Architektur, Datenfluss, Komponenten.
- [`developer/cli.md`](developer/cli.md) — CLI-Kommandos fuer Pipeline, Reindex, Reset.
- [`developer/mcp.md`](developer/mcp.md) — MCP-Server, Tools, Auth und Integration.
- [`decisions/`](decisions/) — Architecture Decision Records.

## Event-driven Migration

- [`implementation-plan-event-driven-archibot.md`](implementation-plan-event-driven-archibot.md) — Zielarchitektur und Migrationsplan.
- [`architecture/`](architecture/) — Detailkonzepte fuer Webhooks, Polling, Progress, Retry, Recovery, Observability und Authorization.
- [`decisions/`](decisions/) — akzeptierte Architekturentscheidungen.
- [`governance/repository-governance.md`](governance/repository-governance.md) — Repository-Governance fuer den Umbau.
- [`governance/trust-boundaries.md`](governance/trust-boundaries.md) — Runtime-, CI-, Tooling- und Integrationsgrenzen.
- [`governance/release-governance.md`](governance/release-governance.md) — Release-, Rollback- und Provenance-Erwartungen.
- [`governance/agent-workflow.md`](governance/agent-workflow.md) — Agenten- und Subagenten-Workflow.
- [`governance/review-checklist.md`](governance/review-checklist.md) — Review-Checkliste fuer Migrationaenderungen.

## Agent Instructions

- [`agent/RULES.md`](agent/RULES.md) — Nicht verhandelbare Projektregeln.
- [`agent/CONSTRAINTS.md`](agent/CONSTRAINTS.md) — Laufzeit-, Daten- und Kompatibilitaetsgrenzen.
- [`agent/PROJECT.md`](agent/PROJECT.md) — Kurzbrief fuer Coding-Agents.
- [`agent/CODING.md`](agent/CODING.md) — Coding-Konventionen und Implementierungshinweise.
- [`agent/REVIEW.md`](agent/REVIEW.md) — Review-Fokus und PR-Pruefpunkte.
- [`agent/CHECKS.md`](agent/CHECKS.md) — Validierungsbefehle.
- [`agent/CONTEXT_AND_EVIDENCE.md`](agent/CONTEXT_AND_EVIDENCE.md) — Kontextbudgets, Evidenzstatus/-frische, Kompaktierung und Wiederaufnahme.
- [`agent/TOOLING.md`](agent/TOOLING.md) — MCP-/Tooling-Policy fuer Coding-Agents.
- [`agent/WORKFLOWS.md`](agent/WORKFLOWS.md) — Wiederverwendbare Workflows.
- [`agent/SAFETY.md`](agent/SAFETY.md) — Sicherheitsregeln fuer Agenten.
- [`agent/SUPPLY_CHAIN.md`](agent/SUPPLY_CHAIN.md) — Dependency- und Container-Sicherheitsregeln.
- [`agent/MEMORY.md`](agent/MEMORY.md) — Dauerhafte, nicht geheime Repo-Erinnerungen.
- [`agent/DECISIONS.md`](agent/DECISIONS.md) — Leichtgewichtiger Entscheidungslog.
- [`agent/ANTI_PATTERNS.md`](agent/ANTI_PATTERNS.md) — Repo-spezifische Anti-Patterns.
- [`agent/DEFINITION_OF_DONE.md`](agent/DEFINITION_OF_DONE.md) — Abschlusskriterien fuer Aufgaben.
- [`agent/ASSESSMENT.md`](agent/ASSESSMENT.md) — Governance-Assessment und naechste Schritte.
- [`agent/CHANGELOG_AGENT.md`](agent/CHANGELOG_AGENT.md) — Aenderungen an Agent-Governance.
