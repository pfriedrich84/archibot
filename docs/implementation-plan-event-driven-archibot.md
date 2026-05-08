# Implementation Plan: Event-driven Archibot

## Zielbild

Archibot soll konsequent von einer gemischten CLI-/Subprocess-/Scheduler-Architektur zu einer event-driven Pipeline weiterentwickelt werden.

Die künftige Architektur trennt klar zwischen UI, dauerhafter Datenhaltung, Message Transport und Python Processing:

```text
Laravel UI / API
  -> PostgreSQL + pgvector als Source of Truth
  -> RabbitMQ als Dramatiq Broker
  -> Python Dramatiq Actors als Pipeline Engine
  -> LiteLLM-kompatibler LLM Adapter
```

Es wird **keine dauerhafte Kompatibilitätsstrategie** mit parallelem Legacy-Betrieb verfolgt. Die bisherige SQLite-/sqlite-vec- und Subprocess-basierte Worker-Schicht wird gezielt ersetzt, nicht langfristig neben der neuen Architektur weitergeführt.

## Architektur-Entscheidungen

### Message Broker

**Ziel:** RabbitMQ

Begründung:

- Passt besser zu event-driven Workflows als eine reine Job Queue.
- Unterstützt saubere Queue-Trennung, Routing, Dead Lettering und Worker-Pipelines.
- Dramatiq unterstützt RabbitMQ direkt.

### Fachliche Datenbank

**Ziel:** PostgreSQL + pgvector

Begründung:

- Gemeinsame robuste Datenbasis für Laravel und Python.
- Besser geeignet für parallele Worker als SQLite.
- pgvector ersetzt sqlite-vec für Embedding Search.
- Sauberer Ort für Pipeline Runs, Events, Actor Executions, Reviews, Audit Log und LLM Usage.

### Python Job Framework

**Ziel:** Dramatiq

Begründung:

- Event-/Actor-Modell passt besser zum langfristigen Ziel als ein großer Worker-Job.
- Jeder Pipeline-Schritt kann separat retrybar, beobachtbar und idempotent werden.
- Queue-Trennung pro Workload wird möglich: IO, OCR, Embedding, Classification, Review, Maintenance.

### LLM Routing

**Ziel:** LiteLLM-kompatibler Adapter

Ollama bleibt vorerst nutzbar, soll aber nicht hart in Pipeline-Actors verdrahtet sein.

```text
Actor -> app.llm.router -> provider adapter -> Ollama / LiteLLM / OpenRouter / local endpoint
```

## Zielarchitektur

```text
archibot
├── AGENTS.md
├── docs/
│   ├── implementation-plan-event-driven-archibot.md
│   ├── architecture/
│   ├── decisions/
│   └── governance/
│
├── laravel/
│   ├── UI
│   ├── Review Dashboard
│   ├── Command API
│   └── PostgreSQL access
│
├── app/
│   ├── actors/
│   │   ├── document.py
│   │   ├── ocr.py
│   │   ├── embedding.py
│   │   ├── classification.py
│   │   ├── review.py
│   │   └── maintenance.py
│   │
│   ├── events/
│   │   ├── types.py
│   │   ├── publish.py
│   │   └── handlers.py
│   │
│   ├── jobs/
│   │   ├── context.py
│   │   ├── progress.py
│   │   ├── locks.py
│   │   └── idempotency.py
│   │
│   ├── llm/
│   │   ├── router.py
│   │   ├── providers.py
│   │   └── usage.py
│   │
│   └── db/
│       ├── models.py
│       └── session.py
│
├── PostgreSQL + pgvector
└── RabbitMQ
```

Redis/Valkey kann später für Cache, Rate Limits oder spezielle Locking-Anforderungen ergänzt werden. Es ist aber kein Bestandteil des Kern-Zielbilds.

## Repository Governance

Der Umbau ist groß genug, dass Architektur, Agentenarbeit und Code-Änderungen explizit geführt werden müssen.

### Governance-Ziele

- Architekturentscheidungen nachvollziehbar machen.
- Große Umbauten in reviewbare Schritte schneiden.
- Python-, Laravel-, Datenbank- und Infrastruktur-Änderungen klar trennen.
- Agenten wie pi.dev/Codex über stabile Repo-Regeln führen.
- Keine dauerhaften Legacy-Pfade einschleppen.
- Migration nicht über versteckte Seiteneffekte, sondern über dokumentierte Phasen durchführen.

### Empfohlene Dokumentstruktur

```text
docs/
├── implementation-plan-event-driven-archibot.md
├── architecture/
│   ├── event-driven-architecture.md
│   ├── data-model.md
│   ├── actor-model.md
│   └── llm-routing.md
│
├── decisions/
│   ├── 0001-use-dramatiq.md
│   ├── 0002-use-postgresql-pgvector.md
│   ├── 0003-use-rabbitmq.md
│   └── 0004-no-legacy-compatibility-mode.md
│
└── governance/
    ├── repository-governance.md
    ├── agent-workflow.md
    └── review-checklist.md
```

### AGENTS.md

Das Repository soll eine zentrale `AGENTS.md` haben. Diese Datei ist der Einstiegspunkt für Coding Agents.

Sie soll enthalten:

- Zielbild der Architektur in wenigen Sätzen.
- Link auf diesen Implementation Plan.
- Link auf Architektur- und Governance-Dokumente.
- Regeln für Python Actors, Laravel UI, DB-Migrationen und Tests.
- Klare Anweisung: Keine neuen Legacy-Kompatibilitätsschichten ohne explizite Entscheidung.

Empfohlene Kurzregel für `AGENTS.md`:

```md
Archibot is being migrated to an event-driven architecture using Dramatiq, RabbitMQ, PostgreSQL and pgvector.
Do not extend the legacy Laravel-subprocess/Python-CLI worker path unless the task explicitly asks for a temporary removal step.
Prefer small, reviewable changes that move the system toward the target architecture described in docs/implementation-plan-event-driven-archibot.md.
```

### ADR-Regel

Jede größere Architekturentscheidung bekommt ein kurzes ADR unter `docs/decisions/`.

ADR Template:

```md
# ADR-NNNN: Title

## Status

Accepted | Proposed | Superseded

## Context

Why is this decision needed?

## Decision

What did we decide?

## Consequences

What gets easier?
What gets harder?
What must not be done anymore?
```

Pflicht-ADRs für diesen Umbau:

- `0001-use-dramatiq.md`
- `0002-use-postgresql-pgvector.md`
- `0003-use-rabbitmq.md`
- `0004-no-legacy-compatibility-mode.md`

### Branch- und PR-Regeln

Empfohlene Branch-Namen:

```text
arch/event-driven-foundation
arch/postgres-pgvector
arch/dramatiq-skeleton
pipeline/process-document
pipeline/inbox-poll
pipeline/reindex
cleanup/remove-legacy-worker
```

PRs sollen klein genug bleiben, um Architekturfolgen prüfen zu können.

Jeder PR soll enthalten:

- Ziel und Scope.
- Betroffene Schichten: Laravel, Python, DB, Infrastructure, Docs.
- Migration/Breaking Changes.
- Tests oder Smoke Commands.
- Hinweis, ob alte Worker-Pfade entfernt oder ersetzt werden.

### Review-Checkliste

Jede Änderung an der neuen Pipeline muss gegen diese Fragen geprüft werden:

- Ist der Actor idempotent?
- Gibt es einen stabilen `pipeline_run_id`?
- Gibt es einen Dedupe-Key für wiederholbare Outputs?
- Werden Events in PostgreSQL persistiert?
- Ist die Queue passend gewählt?
- Sind Retry- und Failure-Regeln explizit?
- Wird Laravel nicht mehr als Python-Prozess-Runner erweitert?
- Entsteht keine neue dauerhafte Legacy-Kompatibilitätsschicht?
- Gibt es Tests oder zumindest einen dokumentierten Smoke Test?

### Ownership-Regeln

Empfohlene Ownership:

```text
Laravel:
- UI
- Review Dashboard
- Command API
- Lesen/Schreiben von Commands, Pipeline Runs, Events, Review Suggestions

Python:
- Dramatiq Actors
- Paperless Integration
- LLM Routing
- Embeddings
- Pipeline Events schreiben

PostgreSQL:
- Gemeinsame Source of Truth
- Keine getrennten Python-/Laravel-Statuswelten

RabbitMQ:
- Transport für Messages
- Kein dauerhafter fachlicher Zustand
```

### Commit-Konvention

Empfohlene Commit Prefixes:

```text
docs:       Dokumentation, Governance, ADRs
arch:       Architektur-/Strukturänderungen
infra:      Docker, RabbitMQ, PostgreSQL, Deployment
python:     Python Runtime, Actors, LLM, Pipeline
laravel:    UI/API/Models/Migrations in Laravel
pipeline:   konkrete Actor-Flows
cleanup:    Entfernen alter Worker-/CLI-Pfade
test:       Tests und Smoke Tests
```

## Begriffe

### Command

Ein expliziter Auftrag, meist aus Laravel/UI oder Scheduler.

Beispiele:

- `process_document`
- `poll_inbox`
- `reindex_all`
- `commit_review`
- `sync_entity_approval`

### Event

Ein unveränderbarer Fakt, der in der Pipeline passiert ist.

Beispiele:

- `document.discovered`
- `document.fetched`
- `ocr.corrected`
- `embedding.created`
- `classification.finished`
- `review_suggestion.created`
- `pipeline.failed`

### Actor Execution

Eine konkrete Ausführung eines Dramatiq Actors.

Beispiele:

- `fetch_document(document_id=123)`
- `correct_ocr(document_id=123)`
- `embed_document(document_id=123)`
- `classify_document(document_id=123)`

### Pipeline Run

Ein zusammenhängender Lauf über ein oder mehrere Dokumente.

Beispiele:

- Ein einzelnes Dokument manuell neu verarbeiten.
- Inbox Poll mit mehreren Dokumenten.
- Vollständiger Reindex.

## Datenmodell-Ziel

### `commands`

Speichert explizite Benutzer- oder Systemaufträge.

Wichtige Felder:

- `id`
- `type`
- `status`
- `payload`
- `created_by_user_id`
- `created_at`
- `started_at`
- `finished_at`
- `error`

### `pipeline_runs`

Speichert zusammenhängende Verarbeitungsläufe.

Wichtige Felder:

- `id`
- `command_id`
- `type`
- `status`
- `scope`
- `started_at`
- `finished_at`
- `progress_done`
- `progress_total`
- `error`

### `pipeline_events`

Event Log für UI, Debugging und Audit.

Wichtige Felder:

- `id`
- `pipeline_run_id`
- `event_type`
- `document_id`
- `level`
- `message`
- `payload`
- `created_at`

### `actor_executions`

Technische Ausführungshistorie für Dramatiq Actors.

Wichtige Felder:

- `id`
- `pipeline_run_id`
- `actor_name`
- `message_id`
- `queue_name`
- `status`
- `attempt`
- `started_at`
- `finished_at`
- `duration_ms`
- `error`

### `document_embeddings`

Ablöse für sqlite-vec.

Wichtige Felder:

- `id`
- `paperless_document_id`
- `content_hash`
- `embedding_model`
- `dimensions`
- `embedding vector(...)`
- `created_at`

Empfohlener Index:

```sql
CREATE INDEX document_embeddings_embedding_hnsw
ON document_embeddings
USING hnsw (embedding vector_cosine_ops);
```

### `llm_calls`

Provider- und Kosten-/Fehlerhistorie.

Wichtige Felder:

- `id`
- `pipeline_run_id`
- `document_id`
- `provider`
- `model`
- `purpose`
- `input_tokens`
- `output_tokens`
- `duration_ms`
- `status`
- `error`
- `created_at`

## Queue-Modell

Startvariante:

```text
archibot.default
archibot.io
archibot.llm
archibot.embedding
archibot.blocking
```

Spätere Erweiterung:

```text
archibot.paperless
archibot.ocr
archibot.classification
archibot.review
archibot.maintenance
archibot.llm.local
archibot.llm.remote
```

## Actor-Schnitt

### Phase 1 Pipeline: Einzelnes Dokument

```text
process_document_command
  -> fetch_document
  -> correct_ocr
  -> embed_document
  -> classify_document
  -> create_review_suggestion
```

### Phase 2 Pipeline: Inbox Poll

```text
poll_inbox_command
  -> discover_inbox_documents
  -> process_document for each document
```

### Phase 3 Pipeline: Reindex

```text
reindex_command
  -> discover_all_documents
  -> reindex_document for each document
  -> rebuild_search_indexes
```

## Idempotenz-Regeln

Jeder Actor muss idempotent sein.

Mindestanforderungen:

- Jeder Actor erhält `pipeline_run_id`.
- Dokumentbezogene Actors erhalten `paperless_document_id`.
- Dokumentbezogene Actors prüfen `content_hash` oder `modified` Timestamp.
- Ein Actor darf denselben Output bei Retry nicht doppelt erzeugen.
- Review Suggestions brauchen einen stabilen Dedupe-Key.

Beispiel Dedupe-Key:

```text
paperless_document_id + content_hash + actor_name + model_version + prompt_version
```

## Locking-Regeln

### Reindex Lock

Ein Reindex blockiert:

- Poll
- Einzelne Dokumentverarbeitung
- Embedding Rebuilds

### Document Lock

Ein Dokument darf nicht parallel mehrfach verarbeitet werden.

Lock-Key:

```text
archibot:document:{paperless_document_id}
```

### LLM/Provider Rate Limits

Ollama/local model:

- Anfangs maximal 1 schwerer LLM Actor gleichzeitig.

LiteLLM/Remote Provider:

- Rate Limit pro Provider.
- Rate Limit pro Modell.
- Retry mit Backoff bei 429, 5xx und Timeouts.

## Laravel Integration

Laravel soll im Zielmodell:

- Commands erzeugen.
- Pipeline Runs anzeigen.
- Events anzeigen.
- Review Suggestions verwalten.
- PostgreSQL als gemeinsame Source of Truth nutzen.
- Keine Python-Prozesse mehr direkt starten.
- Keine separate Legacy-Worker-Schicht parallel pflegen.

Der bestehende Subprocess-Runner und die `worker_jobs`-basierte Steuerung werden durch Commands, Pipeline Runs und Dramatiq Actors ersetzt.

## Migrationsplan

### Phase 0: Vorbereitung und Governance

- `AGENTS.md` als zentralen Agenten-Einstieg anlegen oder aktualisieren.
- Governance-Dokumente unter `docs/governance/` anlegen.
- ADRs unter `docs/decisions/` anlegen.
- Bestehende Worker-Flows dokumentieren.
- Aktuelle CLI-Kommandos erfassen:
  - `poll`
  - `reindex`
  - `reindex-ocr`
  - `reindex-embed`
  - `process-document`
  - `commit-review`
  - `sync-entity-approval`
- Bestehende Progress-/Event-Ausgaben erfassen.
- Bestehende Funktionalität durch Tests absichern, damit sie gezielt in Actors übertragen werden kann.

### Phase 1: Infrastruktur ersetzen

- Docker Compose um PostgreSQL und RabbitMQ erweitern.
- SQLite als primäre Runtime-Datenbank ablösen.
- Python Dependencies ergänzen:
  - `dramatiq`
  - `pika` oder passender RabbitMQ Support über Dramatiq Extras
  - `sqlalchemy`
  - `psycopg`
  - `pgvector`
- Laravel auf PostgreSQL vorbereiten.
- Environment-Variablen dokumentieren.

Beispiel `.env` Zielwerte:

```env
DATABASE_URL=postgresql+psycopg://archibot:archibot@postgres:5432/archibot
DRAMATIQ_BROKER_URL=amqp://guest:guest@rabbitmq:5672/
ARCHIBOT_QUEUE_PREFIX=archibot
LLM_PROVIDER=ollama
LLM_BASE_URL=http://ollama:11434
```

### Phase 2: PostgreSQL Basismodell

- Neue Tabellen für Commands, Pipeline Runs, Pipeline Events und Actor Executions anlegen.
- Python DB Session Layer ergänzen.
- Laravel Models/Migrations für dieselben Tabellen ergänzen oder eine klare Ownership definieren.
- `worker_jobs` wird nicht als langfristige UI-/Audit-Quelle weiterentwickelt.

### Phase 3: Dramatiq Skeleton

- `app/actors/` anlegen.
- Broker-Konfiguration zentralisieren.
- Worker-Bootstrap ergänzen.
- Ersten Dummy Actor mit Event Logging implementieren.
- Lokalen Worker Start dokumentieren.

Beispiel:

```bash
python -m app.workers.dramatiq_worker
```

oder, falls Dramatiq CLI verwendet wird:

```bash
dramatiq app.actors.document app.actors.ocr app.actors.embedding app.actors.classification
```

### Phase 4: Einzelnes Dokument ersetzen

Zuerst `process_document` event-driven bauen und den alten Prozesspfad dafür entfernen.

Actors:

- `fetch_document`
- `correct_ocr`
- `embed_document`
- `classify_document`
- `create_review_suggestion`

Akzeptanzkriterien:

- Ein Dokument kann vollständig über Dramatiq verarbeitet werden.
- Progress ist in PostgreSQL sichtbar.
- Fehler werden als Events geschrieben.
- Retry erzeugt keine doppelten Suggestions.
- Laravel kann den Status anzeigen.
- Der alte Subprocess-Pfad für diese Funktion ist entfernt oder deaktiviert, nicht parallel weitergeführt.

### Phase 5: Inbox Poll ersetzen

- `poll_inbox` erzeugt nur noch Commands/Events für einzelne Dokumente.
- Die eigentliche Verarbeitung läuft über die Dokument-Pipeline.
- Poll selbst bleibt leichtgewichtig.
- Alter Scheduler-/Subprocess-Pfad wird entfernt.

Akzeptanzkriterien:

- Poll blockiert nicht unnötig lange.
- Einzelne Dokumentfehler stoppen nicht den gesamten Poll.
- UI zeigt Gesamtfortschritt und Detailfehler.
- Es gibt nur noch den Dramatiq-basierten Poll-Pfad.

### Phase 6: Reindex ersetzen

- Reindex wird in Discover + viele Dokument-Actors + Abschlussphase geteilt.
- Reindex Lock einführen.
- Embedding Rebuild in pgvector schreiben.
- Alter sqlite-vec Reindex-Pfad wird entfernt.

Akzeptanzkriterien:

- Reindex ist abbrechbar oder pausierbar.
- Einzelne Dokumentfehler führen zu `partially_failed`, nicht zu Totalabbruch.
- Embedding Search läuft über pgvector.
- Es gibt keinen aktiven sqlite-vec Reindex-Pfad mehr.

### Phase 7: LLM Router abstrahieren

- `app.llm.router` einführen.
- Ollama Client hinter Provider Interface legen.
- LiteLLM-kompatiblen Provider vorbereiten.
- LLM Calls in `llm_calls` loggen.

Akzeptanzkriterien:

- Actors kennen keinen konkreten Provider.
- Provider/Model ist konfigurierbar.
- Fehler und Kosten/Token werden nachvollziehbar gespeichert.

### Phase 8: Alte Worker-Schicht entfernen

- Laravel Subprocess Runner entfernen.
- Alte `app.cli` Worker-Kommandos entfernen oder auf reine Admin-/Debug-Kommandos ohne produktive Worker-Steuerung reduzieren.
- APScheduler entfernen oder auf reine Command-Erzeugung ohne eigene Verarbeitung reduzieren.
- `worker_jobs` durch Commands, Pipeline Runs und Pipeline Events ersetzen.
- Doku und AGENTS.md aktualisieren.

## Risiken

### Doppelverarbeitung

Mit Event-driven steigt das Risiko für doppelte Messages.

Gegenmaßnahme:

- Idempotency Keys.
- Document Locks.
- Unique Constraints für Suggestions und Actor Outputs.

### Zu früher Big Bang

PostgreSQL, RabbitMQ, Dramatiq und Pipeline-Umbau gleichzeitig ist riskant.

Gegenmaßnahme:

- Trotzdem kein dauerhafter Legacy-Pfad.
- Umbau in Phasen.
- Pro Phase alte Pfade entfernen, sobald der neue Pfad akzeptiert ist.
- Tests und Smoke Commands pro Phase.

### UI/Backend Drift

Laravel und Python könnten unterschiedliche Statusmodelle entwickeln.

Gegenmaßnahme:

- Gemeinsame Statuswerte definieren.
- Pipeline Events als Source of Truth für UI-Fortschritt.
- Klare Tabellen-Ownership dokumentieren.

### Agenten ändern zu viel auf einmal

Coding Agents könnten mehrere Schichten gleichzeitig umbauen.

Gegenmaßnahme:

- AGENTS.md verweist auf Plan, Governance und Review-Checkliste.
- PR-Scope klein halten.
- Architekturänderungen brauchen ADR.
- Kein neuer Legacy-Kompatibilitätsmodus ohne ADR und explizite User-Entscheidung.

## Definition of Done

Der Umbau gilt als erfolgreich, wenn:

- Python Jobs nicht mehr über Laravel Subprocess gestartet werden.
- Dokumentverarbeitung event-driven über Dramatiq läuft.
- PostgreSQL die gemeinsame Source of Truth ist.
- Embedding Search über pgvector läuft.
- Laravel Pipeline Runs, Events, Fehler und Review Suggestions anzeigen kann.
- Retry und Cancel kontrolliert funktionieren.
- LiteLLM/Ollama austauschbar über einen Adapter angebunden sind.
- Legacy Worker-Pfade entfernt sind.
- Repository Governance dokumentiert ist.
- AGENTS.md Coding Agents auf das Zielbild und die Regeln verpflichtet.

## Empfohlene erste Umsetzung für pi.dev/Codex

```md
Implement Phase 0-3 of the event-driven Archibot migration.

Read `docs/implementation-plan-event-driven-archibot.md` first.

Scope:
- Add or update `AGENTS.md` as the central coding-agent entrypoint.
- Add repository governance docs under `docs/governance/`.
- Add ADRs under `docs/decisions/` for Dramatiq, PostgreSQL/pgvector, RabbitMQ, and no legacy compatibility mode.
- Add PostgreSQL and RabbitMQ to the local development stack.
- Add Python dependencies for Dramatiq, PostgreSQL access and pgvector.
- Add initial `app/events`, `app/jobs`, and `app/actors` package structure.
- Add a Dramatiq broker configuration.
- Add database models or migrations for commands, pipeline_runs, pipeline_events and actor_executions.
- Add one dummy actor that writes an actor execution and pipeline event.
- Add documentation for starting the worker locally.

Non-goals:
- Do not migrate the full document processing pipeline yet.
- Do not keep or design a parallel legacy compatibility mode.
- Do not extend the existing Laravel-subprocess/Python-CLI worker path.

Acceptance criteria:
- Governance docs and ADRs exist.
- AGENTS.md points coding agents to the implementation plan and governance rules.
- New infrastructure can be started locally.
- Dummy actor can be enqueued and processed.
- Actor execution and event are persisted.
- Tests or smoke commands document the new path.
- No new feature flag such as `ARCHIBOT_WORKER_BACKEND=legacy|dramatiq` is introduced.
```
