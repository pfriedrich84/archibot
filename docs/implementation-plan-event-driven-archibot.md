# Implementierungsplan: Event-driven ArchiBot

## Status und Geltungsbereich

Dieses Dokument beschreibt die event-driven Teilmigration. Die uebergeordnete Security-, Ownership- und Delivery-Reihenfolge steht in [`implementation-plan-security-architecture-hardening.md`](implementation-plan-security-architecture-hardening.md); bei Konflikten gilt der neuere Hardening-Plan. Dieses Dokument ist kein historisches Phasenprotokoll.

Bei Widerspruechen gilt diese Reihenfolge:

1. [`AGENTS.md`](../AGENTS.md) fuer den Agenten-Arbeitsvertrag.
2. Akzeptierte ADRs in [`docs/decisions/`](decisions/), insbesondere ADR-0015, ADR-0016, [ADR-0017](decisions/0017-single-durable-orchestration-and-execution-ownership.md) und [ADR-0018](decisions/0018-suspend-model-confidence-auto-commit.md).
3. Der [Hardening-Plan](implementation-plan-security-architecture-hardening.md) fuer Security-, Ownership- und PR-Reihenfolge.
4. Detailvertraege in [`docs/architecture/`](architecture/).
5. Dieser Plan fuer event-driven Zielbild und Migrationsdetails.
6. [`docs/implementation-notes/event-driven-phase-status.md`](implementation-notes/event-driven-phase-status.md) fuer den revisionsgebundenen Ist-Stand.

Die urspruengliche Absurd-Transportentscheidung ist durch ADR-0015 abgeloest. Historische Begruendung bleibt im als superseded markierten [ADR-0013](decisions/0013-use-absurd-postgresql-queue.md) und in der Git-Historie erhalten; sie ist keine Implementierungsanweisung.

## Zielbild

ArchiBot wird als durable event-driven Pipeline betrieben:

```text
Paperless Webhooks / Laravel UI / 600-Sekunden-Reconciliation
  -> Laravel Webhook-, Command- und Pipeline-Services
  -> PostgreSQL + pgvector als fachliche Source of Truth
  -> Laravel Database Queue als Transport
  -> RunPythonActorJob mit kleinem Durable-ID-Payload
  -> fester, allowlisted `python -m app.actor_runner`-Befehl
  -> Python Processing Actors
  -> Paperless / Ollama-kompatible / OpenAI-kompatible Provider
```

Paperless Webhooks sind der primaere Trigger. Polling bleibt automatische Reconciliation und verwendet dieselbe Pipeline-Start-, Dedupe- und Lock-Logik. Laravel Queues transportieren Arbeit, enthalten aber nicht den fachlichen Zustand.

## Nicht verhandelbare Architekturregeln

- PostgreSQL ist die durable Source of Truth fuer Commands, Pipeline Runs, Events, Items, Actor Executions, Webhook Deliveries, Review Suggestions, Audit und Embedding-Status.
- Laravel Queue Jobs tragen nur einen allowlisted Actor-Namen und eine stabile durable ID. Actor-Optionen, beliebige Command-Strings oder allgemeine Python-Runner im Queue Payload sind verboten.
- Document Processing startet erst bei `embedding_index_state.status = complete`.
- Webhooks und Polling verwenden dieselbe Start-/Attach-/Dedupe-/Lock-Logik.
- Webhook Requests validieren, normalisieren, persistieren und dispatchen nur; OCR, Embeddings, Klassifikation und LLM-Aufrufe laufen nicht synchron im HTTP Request.
- Persistierte Webhook Deliveries bleiben bei Dispatch-Fehlern retrybar; wenn Paperless erneut zustellen soll, antwortet der Endpoint non-2xx.
- Actor-Verarbeitung ist idempotent und Retry darf weder Outputs noch Fortschritt doppelt zaehlen.
- Fortschritt, Retry, Recovery und Fehler sind aus PostgreSQL rekonstruierbar, nicht aus Logs oder In-Memory-Countern.
- Nur Admins steuern Jobs und Pipelines. Python entscheidet keine Benutzerautorisierung.
- Manuelle Review- und Berechtigungsgrenzen bleiben erhalten. ADR-0018 setzt `auto_commit_confidence` unabhaengig von Altwerten effektiv auf `0`; Confidence kann keinen Commit autorisieren.
- `worker_jobs` ist nach ADR-0016 fuer Clean Installs entfernt und darf nicht als Kompatibilitaets- oder Parallelmodell zurueckkehren.
- Die bestehende Laravel-Oberflaeche bleibt Operations Console; keine zweite Operations UI.

Detailregeln stehen in:

- [Webhook/Polling Coordination](architecture/webhook-polling-coordination.md)
- [Embedding Readiness Gate](architecture/embedding-readiness-gate.md)
- [Failure, Retry and Recovery](architecture/failure-retry-recovery.md)
- [Progress Tracking](architecture/progress-tracking.md)
- [Observability and Logging](architecture/observability-logging.md)
- [Authorization and Job Control](architecture/authorization-job-control.md)
- [Reprocess Triggers](architecture/reprocess-triggers.md)
- [Current Job-Control Model](architecture/job-control-model.md)

## Komponenten- und Datenownership

### Laravel

Laravel besitzt:

- Webhook Ingestion und Payload-Normalisierung;
- Admin-Autorisierung und UI-Aktionen;
- Erzeugung und Dispatch von Commands und Pipeline Runs;
- Laravel Database Queue und Recovery-Redispatch;
- Operations-, Audit-, Progress- und Fehleransichten aus PostgreSQL.

Der Transportvertrag ist [`RunPythonActorJob`](../laravel/app/Jobs/RunPythonActorJob.php) -> [`PythonActorRunner`](../laravel/app/Services/Actors/PythonActorRunner.php) -> `python -m app.actor_runner`.

### Python

Python besitzt:

- Paperless- und AI-Provider-Aufrufe;
- OCR Correction, Embeddings, Klassifikation und Review Suggestion Generation;
- Review Commit und Maintenance Processing;
- Actor-spezifische Idempotenz, Retry-Klassifikation und durable Statusupdates.

[`app/actor_runner.py`](../app/actor_runner.py) ist die feste CLI-Grenze fuer Laravel Queue Jobs. Optionen werden aus durable Records geladen; die Queue uebergibt nur den allowlisted Actor-Namen und die zugehoerige durable ID.

### PostgreSQL und pgvector

Aktive durable Records umfassen:

- `commands`
- `pipeline_runs`
- `pipeline_events`
- `pipeline_items`
- `actor_executions`
- `webhook_deliveries`
- `review_suggestions`
- `embedding_index_state`
- `document_embeddings`
- Audit- und LLM-Call-Daten

Status- und Feldvertraege gehoeren in die Architekturdocs und Migrationsdateien, nicht als zweite Schemaquelle in diesen Plan.

## Kernfluesse

### Paperless Webhook

```text
POST /api/webhooks/paperless oder /webhook
  -> Security + Payload Validation
  -> webhook_delivery persistieren und deduplizieren
  -> Event/Run erzeugen oder an bestehenden Run anhaengen
  -> Laravel Actor Job mit Delivery-/Run-ID dispatchen
  -> Python Actor verarbeitet durable State
```

Mehrere Events fuer dasselbe Dokument werden ueber Dedupe, Document Lock und Coalescing kontrolliert. Relevant geaenderte Dokumente duerfen einen automatischen Reprocess ausloesen; unveraenderte oder doppelte Deliveries erzeugen keinen zweiten Run.

### Manuelle Aktionen

Maintenance, Review, Entity Approval und Pipeline Controls schreiben zuerst durable Commands oder Runs. Danach dispatcht Laravel einen allowlisted Actor Job. CLI-Aktionen mit GUI-Entsprechung delegieren an denselben Laravel-Backendpfad.

Ein expliziter Force Reprocess:

- ist admin-only;
- erzeugt immer einen neuen Pipeline Run;
- setzt den manuellen Trigger und Reprocess-Metadaten;
- respektiert Embedding Gate und Document Lock.

### Polling / Reconciliation

Polling laeuft automatisch alle 600 Sekunden als Reparaturpfad. Es entdeckt fehlende oder stale Dokumentarbeit und ruft dieselbe Start-/Attach-Logik wie Webhooks auf. Ein separater konkurrierender Processing-Pfad ist nicht erlaubt.

### Retry und Recovery

Laravel-native Recovery scannt durable Records und redispatcht sichere pending/retrying Arbeit. Queue-Zustand allein darf keine fachliche Recovery-Entscheidung treffen. Stale Actor Executions, Cancel Requests und einzelne fehlgeschlagene Pipeline Items bleiben durable und auditierbar.

## Aktueller Migrationsstand

Die revisionsgebundene Detailansicht steht in [`event-driven-phase-status.md`](implementation-notes/event-driven-phase-status.md). Zusammengefasst:

- PostgreSQL/pgvector, Webhook Ingestion und durable Pipeline-Tabellen sind vorhanden.
- Laravel Database Queue, `RunPythonActorJob`, der allowlisted PHP Runner und `app.actor_runner` sind fuer die zentralen Actor-Flows implementiert.
- Laravel `schedule:work` prueft jede Minute, ob die konfigurierte 600-Sekunden-Reconciliation faellig ist, und erzeugt einen deduplizierten durable Poll Command.
- Actor Executions sind mit Command, Pipeline Run oder Webhook Delivery verknuepft; Laravel Recovery behandelt stale/rertryable Attempts, Cancellation und Entity Sync ueber diese Quelle.
- Supervisor startet ausschliesslich Laravel Queue, Scheduler und Recovery. Nur autorisierte manuelle Annahme erzeugt einen `review_commit` Command; Confidence-Auto-Commit ist gemaess ADR-0018 entfernt.
- `worker_jobs`-Runtime, Routen und Kompatibilitaet sind fuer Clean Installs entfernt.
- Der fruehere Python Queue-Transport, SDK, Konfiguration, Clean-Install-Schema und zugehoerige Tests sind im Step-11-Kandidaten entfernt. Bestehende historische Schemaobjekte bleiben fuer Retention/Rollback inert. Full-Reindex- und Runtime-Timeout-Luecken bleiben offen.

## Verbleibende Migration

### 1. Laravel-Transport und Recovery im Runtimepfad verifizieren

- Webhook, Embedding Build, Document Pipeline, Review Commit, Poll Reconciliation, Reindex, OCR Reindex und Entity Sync mit fokussierten Tests und Docker-Smokes abdecken.
- PostgreSQL-Restart, stale Actor Recovery, bounded Attempts, Cancellation und Scheduler-Dedupe live pruefen.
- Laravel Queue Worker, Timeout, Retry und Failure-Verhalten im Docker-Runtimepfad pruefen.
- Endliche, begruendete Actor-/Process-Timeouts, Heartbeats und kooperative Cancellation definieren und testen; `timeout = 0` und unbeschraenkte Child Processes sind kein abgeschlossenes Runtime-Modell.
- Full Reindex ueber den Namen hinaus funktional herstellen; der aktuelle Reindex Actor baut nur den Embedding Index neu.
- Verbleibende GUI-ueberlappende CLI-Aktionen auf denselben Laravel-/PostgreSQL-Backendpfad bringen.

### 2. Queue-Transport-Cleanup — Implementierung abgeschlossen, Acceptance pending

Der Step-11-Kandidat entfernt SDK, Konfiguration, Adapter, Event-/Recovery-Worker, Decorators, vendored SQL, Installationsmigration und transportbezogene Tests. Python Processing Actors bleiben als plain Functions hinter dem festen Laravel Runner erhalten. Acceptance erfordert noch die Full-Suite-, Clean-Install-Docker- und Image-Security-Gates aus dem Hardening-Plan; Upgrade und Rollback stehen in den [Step-11-Notizen](implementation-notes/absurd-removal.md).

### 3. Runtime- und End-to-End-Nachweis

- Clean Install mit PostgreSQL, Laravel Queue und pgvector.
- Webhook -> durable Delivery -> Laravel Queue -> Python Actor -> Pipeline/Review Result.
- Restart/Recovery fuer pending und retrying Arbeit.
- Automatische Polling-Reconciliation nach 600 Sekunden ueber denselben Pipeline-Startpfad.
- Embedding Gate, Reprocess, Retry, Cancel und Berechtigungen.
- Docker Health/Readiness und Operations UI ohne superseded Queue-/`worker_jobs`-Runtime.

### 4. Dokumentationsabschluss

Nach dem Runtime-Cutover alle aktiven User-, Developer-, Operations- und Governance-Dokumente auf Laravel-only Transport pruefen. Historische Absurd- oder `worker_jobs`-Erklaerungen muessen als superseded/retired markiert oder entfernt sein.

## Risiken und Gegenmassnahmen

| Risiko | Gegenmassnahme |
| --- | --- |
| Dual Dispatch erzeugt doppelte Verarbeitung | Ein Transport-Owner pro Flow, durable Dedupe Keys, fokussierte Dispatch-Tests |
| Reconciliation regressiert beim Queue-Cleanup | Laravel Scheduler-/Due-/Dedupe-Tests und Docker-Smoke als Cleanup-Gate behalten |
| Queue Payload wird zur zweiten State Source | Nur IDs transportieren; Optionen aus PostgreSQL laden |
| Langer Python Actor blockiert Worker | Timeouts, Heartbeats, Cancel und Recovery gegen reale Laufzeiten pruefen |
| Webhooks gehen bei Dispatch-Fehler verloren | Erst persistieren, Fehler durable markieren, non-2xx fuer Paperless Retry |
| Fortschritt driftet bei Retry | Aus `pipeline_items` und durable Outputs ableiten statt blind inkrementieren |
| Docs beschreiben wieder superseded Transport | ADR-Link-, Markdown- und gezielte Drift-Checks in der Validierung |

## Definition of Done

Die Migration ist abgeschlossen, wenn:

- Laravel Database Queues der einzige produktive Event-Transport sind.
- Kein superseded Queue-SDK, Clean-Install-Schema, Worker, Recovery-Prozess, Environment Contract oder aktiver Codepfad verbleibt; historische Upgrade-Schemaobjekte sind inert dokumentiert.
- `worker_jobs` nicht als Tabelle, Modell, Route, UI oder Backend-Kompatibilitaet existiert.
- Webhooks primaer und automatische 600-Sekunden-Reconciliation reparierend ueber denselben Startpfad arbeiten.
- Alle Queue Jobs nur allowlisted Actor-Namen und durable IDs verwenden.
- PostgreSQL alle fachlichen Status-, Progress-, Retry-, Recovery- und Auditdaten traegt.
- Embedding Gate, Idempotenz, Retry, Cancel, Reprocess und Berechtigungen getestet sind.
- Actor-Laufzeiten durch dokumentierte Timeouts, Heartbeats, kooperative Cancellation und Recovery begrenzt beziehungsweise ueberwachbar sind.
- Laravel Operations UI und CLI-Ueberlappungen denselben durable Backendpfad verwenden.
- Docker Clean Install, Queue Worker, Recovery und relevante End-to-End-Smokes ohne superseded Runtime bestehen.
- Aktive Dokumentation und ADR-Status widerspruchsfrei sind.

## Validierung

Waehle die kleinsten relevanten Checks aus [`docs/agent/CHECKS.md`](agent/CHECKS.md) und fuehre den final relevanten Satz nach dem letzten materiellen Patch aus. Ergebnisstatus, Freshness und unvollstaendige Coverage folgen [`docs/agent/CONTEXT_AND_EVIDENCE.md`](agent/CONTEXT_AND_EVIDENCE.md).
