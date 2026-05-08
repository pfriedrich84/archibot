# Implementation Plan: Event-driven Archibot

## Zielbild

Archibot soll von einer gemischten CLI-/Subprocess-/Scheduler-Architektur zu einer event-driven Pipeline weiterentwickelt werden.

Die künftige Architektur trennt klar zwischen UI, dauerhafter Datenhaltung, Message Transport und Python Processing:

```text
Laravel UI / API
  -> PostgreSQL + pgvector als Source of Truth
  -> RabbitMQ als Dramatiq Broker
  -> Python Dramatiq Actors als Pipeline Engine
  -> LiteLLM-kompatibler LLM Adapter
```

Die aktuelle SQLite-/sqlite-vec-Lösung darf während der Migration weiter existieren, soll aber nicht das langfristige Zielmodell bleiben.

## Architektur-Entscheidungen

### Message Broker

**Ziel:** RabbitMQ

Begründung:

- Passt besser zu event-driven Workflows als eine reine Job Queue.
- Unterstützt saubere Queue-Trennung, Routing, Dead Lettering und Worker-Pipelines.
- Dramatiq unterstützt RabbitMQ direkt.

**Übergangsoption:** Redis/Valkey ist für eine frühe Phase möglich, wenn Betriebseinfachheit wichtiger ist als das finale Broker-Modell.

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
├── RabbitMQ
└── Redis/Valkey optional
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
- Inbox Poll mit 17 Dokumenten.
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

Kurzfristig kann `worker_jobs` als Kompatibilitätsschicht bleiben.

Langfristig soll Laravel:

- Commands erzeugen.
- Pipeline Runs anzeigen.
- Events anzeigen.
- Review Suggestions verwalten.
- Keine Python-Prozesse mehr direkt starten.

Aktueller Subprocess-Runner soll schrittweise ersetzt werden.

## Migrationsplan

### Phase 0: Vorbereitung

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
- Tests für vorhandenes Verhalten ergänzen, bevor Logik verschoben wird.

### Phase 1: Infrastruktur ergänzen

- Docker Compose um PostgreSQL, RabbitMQ und optional Redis/Valkey erweitern.
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
- UI kann vorerst weiterhin `worker_jobs` nutzen.

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

### Phase 4: Einzelnes Dokument migrieren

Zuerst nur `process_document` event-driven bauen.

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

### Phase 5: Inbox Poll migrieren

- `poll_inbox` erzeugt nur noch Events/Commands für einzelne Dokumente.
- Die eigentliche Verarbeitung läuft über die Dokument-Pipeline.
- Poll selbst bleibt leichtgewichtig.

Akzeptanzkriterien:

- Poll blockiert nicht unnötig lange.
- Einzelne Dokumentfehler stoppen nicht den gesamten Poll.
- UI zeigt Gesamtfortschritt und Detailfehler.

### Phase 6: Reindex migrieren

- Reindex wird in Discover + viele Dokument-Actors + Abschlussphase geteilt.
- Reindex Lock einführen.
- Embedding Rebuild in pgvector schreiben.

Akzeptanzkriterien:

- Reindex ist abbrechbar oder pausierbar.
- Einzelne Dokumentfehler führen zu `partially_failed`, nicht zu Totalabbruch.
- Embedding Search läuft über pgvector.

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

- Laravel Subprocess Runner deaktivieren oder entfernen.
- Alte `app.cli` Kommandos entweder entfernen oder als Debug-/Sync-Wrappers behalten.
- APScheduler nur noch als Enqueue-Scheduler verwenden oder komplett ersetzen.
- `worker_jobs` entweder migrieren oder als Legacy-Kompatibilität belassen.

## Kompatibilitätsstrategie

Während der Migration sollen beide Wege koexistieren können:

```text
Legacy:
Laravel worker_jobs -> Python CLI Subprocess

Neu:
Laravel command -> PostgreSQL command -> Dramatiq message -> Python Actor Pipeline
```

Feature Flag:

```env
ARCHIBOT_WORKER_BACKEND=legacy|dramatiq
```

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

- Erst Infrastruktur und Skeleton.
- Dann nur `process_document` migrieren.
- Erst danach Poll und Reindex.

### UI/Backend Drift

Laravel und Python könnten unterschiedliche Statusmodelle entwickeln.

Gegenmaßnahme:

- Gemeinsame Statuswerte definieren.
- Pipeline Events als Source of Truth für UI-Fortschritt.
- Klare Tabellen-Ownership dokumentieren.

## Definition of Done

Der Umbau gilt als erfolgreich, wenn:

- Python Jobs nicht mehr über Laravel Subprocess gestartet werden müssen.
- Dokumentverarbeitung event-driven über Dramatiq läuft.
- PostgreSQL die gemeinsame Source of Truth ist.
- Embedding Search über pgvector läuft.
- Laravel Pipeline Runs, Events, Fehler und Review Suggestions anzeigen kann.
- Retry und Cancel kontrolliert funktionieren.
- LiteLLM/Ollama austauschbar über einen Adapter angebunden sind.
- Legacy CLI weiterhin für Debugging nutzbar ist oder bewusst entfernt wurde.

## Empfohlene erste Umsetzung für pi.dev/Codex

```md
Implement Phase 1-3 of the event-driven Archibot migration.

Read `docs/implementation-plan-event-driven-archibot.md` first.

Scope:
- Add PostgreSQL, RabbitMQ and optional Redis/Valkey to the local development stack.
- Add Python dependencies for Dramatiq, PostgreSQL access and pgvector.
- Add initial `app/events`, `app/jobs`, and `app/actors` package structure.
- Add a Dramatiq broker configuration.
- Add database models or migrations for commands, pipeline_runs, pipeline_events and actor_executions.
- Add one dummy actor that writes an actor execution and pipeline event.
- Add documentation for starting the worker locally.

Non-goals:
- Do not migrate the full document processing pipeline yet.
- Do not remove existing CLI or Laravel worker_jobs yet.
- Do not change current production behavior without a feature flag.

Acceptance criteria:
- Existing app still starts.
- New infrastructure can be started locally.
- Dummy actor can be enqueued and processed.
- Actor execution and event are persisted.
- Tests or smoke commands document the new path.
```
