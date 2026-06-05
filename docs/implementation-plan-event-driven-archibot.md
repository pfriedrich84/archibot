# Implementation Plan: Event-driven Archibot

## Zielbild

Archibot soll konsequent von einer gemischten CLI-/Subprocess-/Scheduler-Architektur zu einer event-driven Pipeline weiterentwickelt werden.

Die künftige Architektur trennt klar zwischen UI, dauerhafter Datenhaltung, Webhook/Event-Ingestion, Message Transport und Python Processing:

```text
Paperless Webhooks / Laravel UI / Scheduler
  -> Laravel Webhook + Command API
  -> PostgreSQL + pgvector als Source of Truth
  -> Absurd als PostgreSQL-backed Queue
  -> Python Absurd actors als Pipeline Engine
  -> Ollama-/OpenAI-kompatibler LLM Adapter
```

**Paperless Webhooks sind der primäre Trigger für neue oder geänderte Dokumente.** Polling bleibt nur ein Fallback/Reconciliation-Mechanismus, nicht der Hauptpfad.

Es wird **keine dauerhafte Kompatibilitätsstrategie** mit parallelem Legacy-Betrieb verfolgt. Die bisherige Subprocess-basierte Worker-Schicht wird gezielt ersetzt, nicht langfristig neben der neuen Architektur weitergeführt.

## Architektur-Entscheidungen

### Primary Trigger: Webhooks

**Ziel:** Paperless Webhooks lösen die Archibot Pipeline aus.

Begründung:

- Webhooks passen besser zu event-driven als periodisches Polling.
- Neue oder geänderte Dokumente werden zeitnah verarbeitet.
- Die Pipeline kann pro Dokument klein, idempotent und retrybar geschnitten werden.
- Polling wird auf Reconciliation reduziert: verlorene Webhooks, manuelle Reparatur, Initialscan, Reindex.

Webhook-Ingestion darf **keine schwere Verarbeitung synchron im HTTP Request** durchführen. Der Webhook Request validiert, normalisiert, persistiert und enqueued nur.

### Message Broker

**Ziel:** Absurd/PostgreSQL

Begründung:

- Passt besser zu event-driven Workflows als eine reine Job Queue.
- Unterstützt saubere Queue-Trennung, Routing, Wiederaufnahme und Worker-Pipelines.
- Absurd nutzt PostgreSQL direkt; es gibt keinen separaten Broker-Service.

### Fachliche Datenbank

**Ziel:** PostgreSQL + pgvector

Begründung:

- Gemeinsame robuste Datenbasis für Laravel und Python.
- Geeignet für parallele Worker und dauerhaftes Retry-/Progress-Tracking.
- pgvector ist der Standard für Embedding Search.
- Sauberer Ort für Webhook Deliveries, Pipeline Runs, Events, Actor Executions, Reviews, Audit Log und LLM Usage.

### Python Job Framework

**Ziel:** Absurd

Begründung:

- Event-/Actor-Modell passt besser zum langfristigen Ziel als ein großer Worker-Job.
- Jeder Pipeline-Schritt kann separat retrybar, beobachtbar und idempotent werden.
- Queue-Trennung pro Workload wird möglich: IO, OCR, Embedding, Classification, Review, Maintenance.

### LLM Routing

**Ziel:** Ollama-/OpenAI-kompatibler Adapter

Provider bleiben austauschbar und duerfen nicht hart in Pipeline-Actors verdrahtet sein.

```text
Actor -> app.llm.router -> provider adapter -> Ollama-compatible / OpenAI-compatible endpoint
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
│   ├── Webhook Ingestion API
│   ├── UI
│   ├── Review Dashboard
│   ├── Command API
│   └── PostgreSQL access
│
├── app/
│   ├── actors/
│   │   ├── webhook.py
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
└── Absurd/PostgreSQL
```

Redis/Valkey kann später für Cache, Rate Limits oder spezielle Locking-Anforderungen ergänzt werden. Es ist aber kein Bestandteil des Kern-Zielbilds.

## Event-Eingänge

### 1. Paperless Webhook: Hauptpfad

Der wichtigste Eingang ist ein Webhook von Paperless bei Dokumentereignissen.

Beispiele:

- Dokument wurde erstellt.
- Dokument wurde geändert.
- Dokument wurde konsumiert/importiert.
- Tags, Titel, Dokumenttyp, Korrespondent oder Inhalt wurden geändert.

Webhook-Zielfluss:

```text
Paperless Webhook
  -> Laravel Webhook Endpoint
  -> validate + persist webhook_delivery
  -> normalize to internal event
  -> create pipeline_run if needed
  -> enqueue Absurd actor
  -> return HTTP 2xx quickly
```

### 2. Laravel UI / Command API

Manuelle Aktionen bleiben möglich:

- Dokument neu verarbeiten.
- Review committen.
- Reindex starten.
- Entity Approval synchronisieren.

Diese Aktionen erzeugen Commands und dann Events/Messages.

### 3. Scheduler / Reconciliation

Scheduler ist nur für Wartung und Reparatur zuständig:

- Verlorene Webhooks erkennen.
- Hängende Pipeline Runs reparieren.
- Nacht-/Wochen-Reindex starten.
- Webhook Delivery Retrys ausführen.

Polling ist **kein Haupttrigger** mehr.

## Webhook Ingestion Design

### Laravel Webhook Endpoint

Empfohlener Pfad:

```text
POST /api/webhooks/paperless
```

Aufgaben:

- Authentizität prüfen, soweit Paperless/Webserver-Konfiguration das erlaubt.
- Payload validieren.
- Rohpayload in `webhook_deliveries` persistieren.
- Dedupe-Key berechnen.
- Interne Eventklasse bestimmen.
- Schnelle Antwort liefern.
- Keine LLM-, OCR-, Embedding- oder Paperless-heavy-Calls im Request durchführen.

### Webhook Security

Wenn Paperless keine starke Signatur liefert, muss die Sicherheit über Infrastruktur und Shared Secret erfolgen:

- Nur interne Netzwerkpfade erlauben.
- Reverse Proxy ACLs nutzen.
- Optional Header Secret verwenden, falls konfigurierbar.
- Webhook Endpoint nicht öffentlich ohne Schutz betreiben.
- Alle Payloads auditierbar speichern.

### Webhook Dedupe

Webhook Events können mehrfach zugestellt werden oder in kurzer Folge eintreffen.

Mindest-Dedupe-Key:

```text
source + event_type + paperless_document_id + paperless_modified + payload_hash
```

Wenn Paperless keine stabile Event-ID liefert, wird ein eigener Hash über normalisierte Payload-Felder gebildet.

### Webhook Debounce / Coalescing

Mehrere Änderungen an demselben Dokument können kurz hintereinander kommen.

Regel:

- Webhook sofort persistieren.
- Verarbeitung pro Dokument über Document Lock und Idempotency Key schützen.
- Optional eine kurze Debounce-Verzögerung für `document.updated` einführen.
- Die Pipeline verarbeitet den neuesten Paperless-Zustand, nicht blind jeden einzelnen Zwischenstand.

### Webhook Failure Handling

- Ungültige Payloads werden mit Fehlerstatus gespeichert.
- Gültige, aber nicht verarbeitbare Payloads erzeugen ein `webhook.failed` Event.
- Actor-Enqueue-Fehler müssen sichtbar sein und retrybar bleiben.
- Webhook Delivery darf nicht verloren gehen, wenn Absurd/PostgreSQL kurz nicht erreichbar ist.

## Repository Governance

Der Umbau ist groß genug, dass Architektur, Agentenarbeit und Code-Änderungen explizit geführt werden müssen.

### Governance-Ziele

- Architekturentscheidungen nachvollziehbar machen.
- Große Umbauten in reviewbare Schritte schneiden.
- Python-, Laravel-, Datenbank- und Infrastruktur-Änderungen klar trennen.
- Agenten wie pi.dev/Codex über stabile Repo-Regeln führen.
- Keine dauerhaften Legacy-Pfade einschleppen.
- Webhooks als primäre Eventquelle schützen.
- Migration nicht über versteckte Seiteneffekte, sondern über dokumentierte Phasen durchführen.

### Empfohlene Dokumentstruktur

```text
docs/
├── implementation-plan-event-driven-archibot.md
├── architecture/
│   ├── event-driven-architecture.md
│   ├── webhook-ingestion.md
│   ├── data-model.md
│   ├── actor-model.md
│   └── llm-routing.md
│
├── decisions/
│   ├── 0002-use-postgresql-pgvector.md
│   ├── 0004-no-legacy-compatibility-mode.md
│   ├── 0005-use-webhooks-as-primary-trigger.md
│   └── 0013-use-absurd-postgresql-queue.md
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
- Hinweis: Paperless Webhooks sind der Haupttrigger.
- Link auf diesen Implementation Plan.
- Link auf Architektur- und Governance-Dokumente.
- Regeln für Webhook-Ingestion, Python Actors, Laravel UI, DB-Migrationen und Tests.
- Klare Anweisung: Keine neuen Legacy-Kompatibilitätsschichten ohne explizite Entscheidung.

Empfohlene Kurzregel für `AGENTS.md`:

```md
Archibot is being migrated to an event-driven architecture using Paperless webhooks, Absurd, PostgreSQL and pgvector.
Paperless webhooks are the primary trigger for document processing; polling is only for reconciliation and maintenance.
Do not extend the legacy Laravel-subprocess/Python-CLI worker path unless the task explicitly asks for a temporary removal step.
Prefer small, reviewable changes that move the system toward the target architecture described in docs/implementation-plan-event-driven-archibot.md.
```

### ADR-Regel

Jede größere Architekturentscheidung bekommt ein kurzes ADR unter `docs/decisions/`.

Pflicht-ADRs für diesen Umbau:

- `0002-use-postgresql-pgvector.md`
- `0013-use-absurd-postgresql-queue.md`
- `0004-no-legacy-compatibility-mode.md`
- `0005-use-webhooks-as-primary-trigger.md`

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

### Branch- und PR-Regeln

Empfohlene Branch-Namen:

```text
arch/event-driven-foundation
arch/postgres-pgvector
arch/absurd-foundation
arch/paperless-webhook-ingestion
pipeline/process-document
pipeline/inbox-reconciliation
pipeline/reindex
cleanup/remove-legacy-worker
```

Jeder PR soll enthalten:

- Ziel und Scope.
- Betroffene Schichten: Laravel, Python, DB, Infrastructure, Docs.
- Migration/Breaking Changes.
- Tests oder Smoke Commands.
- Hinweis, ob alte Worker-Pfade entfernt oder ersetzt werden.
- Falls Webhooks betroffen sind: Security, Dedupe, Retry und Idempotency-Verhalten.

### Review-Checkliste

Jede Änderung an der neuen Pipeline muss gegen diese Fragen geprüft werden:

- Ist Webhook-Ingestion schnell und ohne schwere Verarbeitung im HTTP Request?
- Wird jede Webhook Delivery persistiert?
- Gibt es einen Dedupe-Key?
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

```text
Laravel:
- Webhook Ingestion API
- UI
- Review Dashboard
- Command API
- Lesen/Schreiben von Commands, Pipeline Runs, Events, Review Suggestions

Python:
- Absurd actors
- Paperless Integration
- LLM Routing
- Embeddings
- Pipeline Events schreiben

PostgreSQL:
- Gemeinsame Source of Truth
- Webhook Deliveries
- Keine getrennten Python-/Laravel-Statuswelten

Absurd/PostgreSQL:
- Transport für Messages
- Kein dauerhafter fachlicher Zustand
```

### Commit-Konvention

```text
docs:       Dokumentation, Governance, ADRs
arch:       Architektur-/Strukturänderungen
infra:      Docker, Absurd/PostgreSQL, PostgreSQL, Deployment
webhook:    Webhook-Ingestion, Dedupe, Delivery Handling
python:     Python Runtime, Actors, LLM, Pipeline
laravel:    UI/API/Models/Migrations in Laravel
pipeline:   konkrete Actor-Flows
cleanup:    Entfernen alter Worker-/CLI-Pfade
test:       Tests und Smoke Tests
```

## Begriffe

### Webhook Delivery

Eine einzelne empfangene HTTP-Zustellung von Paperless. Sie wird unverändert und normalisiert gespeichert, bevor daraus ein internes Event entsteht.

### Command

Ein expliziter Auftrag, meist aus Laravel/UI oder Scheduler.

Beispiele:

- `process_document`
- `reindex_all`
- `commit_review`
- `sync_entity_approval`

### Event

Ein unveränderbarer Fakt, der in der Pipeline passiert ist.

Beispiele:

- `webhook.received`
- `webhook.normalized`
- `document.discovered`
- `document.changed`
- `document.fetched`
- `ocr.corrected`
- `embedding.created`
- `classification.finished`
- `review_suggestion.created`
- `pipeline.failed`

### Actor Execution

Eine konkrete Ausführung eines Absurd actors.

Beispiele:

- `handle_paperless_webhook(delivery_id=123)`
- `fetch_document(document_id=123)`
- `correct_ocr(document_id=123)`
- `embed_document(document_id=123)`
- `classify_document(document_id=123)`

### Pipeline Run

Ein zusammenhängender Lauf über ein oder mehrere Dokumente.

Beispiele:

- Ein einzelnes Dokument durch Webhook neu verarbeiten.
- Ein manuell neu gestartetes Dokument.
- Reconciliation-Lauf über mehrere Dokumente.
- Vollständiger Reindex.

## Datenmodell-Ziel

### `webhook_deliveries`

Persistiert eingehende Paperless Webhook Deliveries.

Wichtige Felder:

- `id`
- `source`
- `event_type`
- `paperless_document_id`
- `dedupe_key`
- `payload_hash`
- `raw_payload`
- `normalized_payload`
- `headers`
- `status`
- `received_at`
- `processed_at`
- `error`

Empfohlene Constraints:

```text
unique(source, dedupe_key)
index(paperless_document_id)
index(status, received_at)
```

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
- `webhook_delivery_id`
- `type`
- `status`
- `scope`
- `paperless_document_id`
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
- `webhook_delivery_id`
- `event_type`
- `document_id`
- `level`
- `message`
- `payload`
- `created_at`

### `actor_executions`

Technische Ausführungshistorie für Absurd actors.

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

Ablöse für pgvector.

Wichtige Felder:

- `id`
- `paperless_document_id`
- `content_hash`
- `embedding_model`
- `dimensions`
- `embedding vector`
- `created_at`

Hinweis: pgvector-ANN-Indizes wie HNSW benötigen eine feste Vektordimension. Da ArchiBot die Embedding-Dimension pro Modell speichert, bleibt die Spalte in der Basismigration unbeschränkt. Dimensionsspezifische ANN-Indizes können später als partielle Expression-Indizes ergänzt werden, z. B. für 1024-dimensionale Modelle:

```sql
CREATE INDEX document_embeddings_embedding_1024_hnsw
ON document_embeddings
USING hnsw ((embedding::vector(1024)) vector_cosine_ops)
WHERE dimensions = 1024;
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
archibot.webhook
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

### Phase 1 Pipeline: Webhook zu Dokumentverarbeitung

```text
paperless_webhook_received
  -> normalize_webhook_delivery
  -> start_document_pipeline
  -> fetch_document
  -> correct_ocr
  -> embed_document
  -> classify_document
  -> create_review_suggestion
```

### Phase 2 Pipeline: Manuelles einzelnes Dokument

```text
process_document_command
  -> start_document_pipeline
  -> fetch_document
  -> correct_ocr
  -> embed_document
  -> classify_document
  -> create_review_suggestion
```

### Phase 3 Pipeline: Reconciliation statt Inbox Poll als Haupttrigger

```text
reconcile_documents_command
  -> discover_recent_or_unprocessed_documents
  -> start_document_pipeline for each missing/stale document
```

### Phase 4 Pipeline: Reindex

```text
reindex_command
  -> discover_all_documents
  -> reindex_document for each document
  -> rebuild_search_indexes
```

## Idempotenz-Regeln

Jeder Actor muss idempotent sein.

Mindestanforderungen:

- Jede Webhook Delivery erhält einen stabilen `dedupe_key`.
- Jeder Actor erhält `pipeline_run_id`.
- Dokumentbezogene Actors erhalten `paperless_document_id`.
- Dokumentbezogene Actors prüfen `content_hash` oder `modified` Timestamp.
- Ein Actor darf denselben Output bei Retry nicht doppelt erzeugen.
- Review Suggestions brauchen einen stabilen Dedupe-Key.

Beispiel Dedupe-Key für Actor Outputs:

```text
paperless_document_id + content_hash + actor_name + model_version + prompt_version
```

## Locking-Regeln

### Webhook Lock

Mehrere Webhooks für dasselbe Dokument dürfen nicht parallele widersprüchliche Pipelines erzeugen.

Lock-Key:

```text
archibot:webhook-document:{paperless_document_id}
```

### Reindex Lock

Ein Reindex blockiert:

- Webhook-getriggerte Dokumentverarbeitung
- Manuelle Dokumentverarbeitung
- Reconciliation
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

OpenAI-compatible Provider:

- Rate Limit pro Provider.
- Rate Limit pro Modell.
- Retry mit Backoff bei 429, 5xx und Timeouts.

## Laravel Integration

Laravel soll im Zielmodell:

- Paperless Webhooks empfangen.
- Webhook Deliveries persistieren.
- Commands erzeugen.
- Pipeline Runs anzeigen.
- Events anzeigen.
- Review Suggestions verwalten.
- PostgreSQL als gemeinsame Source of Truth nutzen.
- Keine Python-Prozesse mehr direkt starten.
- Keine separate Legacy-Worker-Schicht parallel pflegen.

Der bestehende Subprocess-Runner und die `worker_jobs`-basierte Steuerung werden durch Webhook Deliveries, Commands, Pipeline Runs und Absurd actors ersetzt.

## Migrationsplan

### Phase 0: Vorbereitung und Governance

- `AGENTS.md` als zentralen Agenten-Einstieg anlegen oder aktualisieren.
- Governance-Dokumente unter `docs/governance/` anlegen.
- ADRs unter `docs/decisions/` anlegen.
- ADR `0005-use-webhooks-as-primary-trigger.md` anlegen.
- Bestehende Worker-Flows dokumentieren.
- Bestehende oder gewünschte Paperless Webhook Payloads dokumentieren.
- Webhook Security- und Dedupe-Regeln dokumentieren.
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

- Docker Compose um PostgreSQL und Absurd/PostgreSQL erweitern.
- PostgreSQL als primäre Runtime-Datenbank ablösen.
- Python Dependencies ergänzen:
  - `absurd-sdk`
  - `absurd-sdk`
  - `sqlalchemy`
  - `psycopg`
  - `pgvector`
- Laravel auf PostgreSQL vorbereiten.
- Environment-Variablen dokumentieren.

Beispiel `.env` Zielwerte:

```env
DATABASE_URL=postgresql+psycopg://archibot:archibot@postgres:5432/archibot
ABSURD_DATABASE_URL=postgresql://archibot:archibot@postgres:5432/archibot
ARCHIBOT_QUEUE_PREFIX=archibot
PAPERLESS_WEBHOOK_SECRET=change-me
LLM_PROVIDER=ollama
LLM_BASE_URL=http://ollama:11434
```

### Phase 2: PostgreSQL Basismodell

- Neue Tabellen für Webhook Deliveries, Commands, Pipeline Runs, Pipeline Events und Actor Executions anlegen.
- Python DB Session Layer ergänzen.
- Laravel Models/Migrations für dieselben Tabellen ergänzen oder eine klare Ownership definieren.
- `worker_jobs` wird nicht als langfristige UI-/Audit-Quelle weiterentwickelt.

### Phase 3: Webhook Ingestion Skeleton

- Laravel Webhook Endpoint für Paperless anlegen.
- Payload validieren und in `webhook_deliveries` speichern.
- Dedupe-Key berechnen.
- Dummy `webhook.received` / `webhook.normalized` Event schreiben.
- Noch keine schwere Dokumentverarbeitung ausführen.
- Tests für gültige, ungültige und doppelte Webhooks ergänzen.

### Phase 4: Absurd Skeleton

- `app/actors/` anlegen.
- `app/actors/webhook.py` anlegen.
- Broker-Konfiguration zentralisieren.
- Worker-Bootstrap ergänzen.
- Ersten Dummy Actor mit Event Logging implementieren.
- Webhook Endpoint enqueued Dummy Actor.
- Lokalen Worker Start dokumentieren.

Beispiel:

```bash
python -m app.event_worker start-workers
```

oder, falls Absurd worker CLI verwendet wird:

```bash
python -m app.event_worker start-workers
```

### Phase 5: Webhook-getriggerte Dokumentverarbeitung ersetzen

Zuerst `process_document` als Webhook-getriebene Pipeline bauen und den alten Prozesspfad dafür entfernen.

Actors:

- `normalize_webhook_delivery`
- `start_document_pipeline`
- `fetch_document`
- `correct_ocr`
- `embed_document`
- `classify_document`
- `create_review_suggestion`

Akzeptanzkriterien:

- Ein Paperless Webhook kann ein Dokument vollständig über Absurd verarbeiten.
- Progress ist in PostgreSQL sichtbar.
- Fehler werden als Events geschrieben.
- Retry erzeugt keine doppelten Suggestions.
- Laravel kann den Status anzeigen.
- Doppelte Webhooks erzeugen keine doppelte Verarbeitung.
- Der alte Subprocess-Pfad für diese Funktion ist entfernt oder deaktiviert, nicht parallel weitergeführt.

### Phase 6: Reconciliation statt Inbox Poll als Hauptpfad

- `poll_inbox` wird durch `reconcile_documents` ersetzt.
- Reconciliation sucht fehlende/stale Dokumente, die keinen Webhook-Lauf bekommen haben.
- Die eigentliche Verarbeitung läuft über die Dokument-Pipeline.
- Alter Scheduler-/Subprocess-Pfad wird entfernt.

Akzeptanzkriterien:

- Reconciliation ist optional und reparierend, nicht Haupttrigger.
- Einzelne Dokumentfehler stoppen nicht den gesamten Reconciliation-Lauf.
- UI zeigt Gesamtfortschritt und Detailfehler.
- Es gibt keinen periodischen Poll als primären Verarbeitungspfad mehr.

### Phase 7: Reindex ersetzen

- Reindex wird in Discover + viele Dokument-Actors + Abschlussphase geteilt.
- Reindex Lock einführen.
- Embedding Rebuild in pgvector schreiben.
- Alter pgvector Reindex-Pfad wird entfernt.

Akzeptanzkriterien:

- Reindex ist abbrechbar oder pausierbar.
- Einzelne Dokumentfehler führen zu `partially_failed`, nicht zu Totalabbruch.
- Embedding Search läuft über pgvector.
- Es gibt keinen aktiven pgvector Reindex-Pfad mehr.

### Phase 8: LLM Router abstrahieren

- `app.llm.router` einführen.
- Ollama Client hinter Provider Interface legen.
- OpenAI-kompatiblen Provider vorbereiten.
- LLM Calls in `llm_calls` loggen.

Akzeptanzkriterien:

- Actors kennen keinen konkreten Provider.
- Provider/Model ist konfigurierbar.
- Fehler und Kosten/Token werden nachvollziehbar gespeichert.

### Phase 9: Alte Worker-Schicht entfernen

- Laravel Subprocess Runner entfernen.
- Alte `app.cli` Worker-Kommandos entfernen oder auf reine Admin-/Debug-Kommandos ohne produktive Worker-Steuerung reduzieren.
- APScheduler entfernen oder auf reine Reconciliation-/Maintenance-Command-Erzeugung reduzieren.
- `worker_jobs` durch Webhook Deliveries, Commands, Pipeline Runs und Pipeline Events ersetzen.
- Doku und AGENTS.md aktualisieren.

## Risiken

### Webhook-Ausfall oder verlorene Zustellung

Wenn Paperless Webhooks nicht zugestellt werden, fehlen Pipeline-Läufe.

Gegenmaßnahme:

- Webhook Deliveries persistieren.
- Reconciliation-Lauf für fehlende/stale Dokumente.
- Monitoring auf ausbleibende Webhooks.
- Manuelle Neuverarbeitung über Laravel UI.

### Doppelte Webhooks / Doppelverarbeitung

Mit Webhooks und Event-driven steigt das Risiko für doppelte Messages.

Gegenmaßnahme:

- Webhook Dedupe Keys.
- Idempotency Keys.
- Document Locks.
- Unique Constraints für Suggestions und Actor Outputs.

### Zu früher Big Bang

PostgreSQL, Absurd, Webhooks und Pipeline-Umbau gleichzeitig ist riskant.

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

- Paperless Webhooks der primäre Trigger für Dokumentverarbeitung sind.
- Webhook Deliveries persistiert, dedupliziert und auditierbar sind.
- Python Jobs nicht mehr über Laravel Subprocess gestartet werden.
- Dokumentverarbeitung event-driven über Absurd läuft.
- PostgreSQL die gemeinsame Source of Truth ist.
- Embedding Search über pgvector läuft.
- Laravel Pipeline Runs, Events, Fehler und Review Suggestions anzeigen kann.
- Retry und Cancel kontrolliert funktionieren.
- OpenAI-kompatible/Ollama-kompatible Provider austauschbar über einen Adapter angebunden sind.
- Polling nur noch als Reconciliation/Maintenance existiert.
- Legacy Worker-Pfade entfernt sind.
- Repository Governance dokumentiert ist.
- AGENTS.md Coding Agents auf das Zielbild und die Regeln verpflichtet.

## Empfohlene erste Umsetzung für pi.dev/Codex

```md
Implement Phase 0-4 of the event-driven Archibot migration.

Read `docs/implementation-plan-event-driven-archibot.md` first.

Scope:
- Add or update `AGENTS.md` as the central coding-agent entrypoint.
- Add repository governance docs under `docs/governance/`.
- Add ADRs under `docs/decisions/` for Absurd, PostgreSQL/pgvector, Absurd/PostgreSQL, no legacy compatibility mode, and Paperless webhooks as primary trigger.
- Add PostgreSQL and Absurd/PostgreSQL to the local development stack.
- Add Python dependencies for Absurd, PostgreSQL access and pgvector.
- Add Laravel/PHP model and migration for webhook_deliveries.
- Add database models or migrations for commands, pipeline_runs, pipeline_events and actor_executions.
- Add a Paperless webhook endpoint that validates, persists and deduplicates webhook deliveries.
- Add initial `app/events`, `app/jobs`, and `app/actors` package structure.
- Add `app/actors/webhook.py` and a dummy actor for webhook processing.
- Add a Absurd queue configuration.
- Wire the webhook endpoint to enqueue the dummy actor.
- Add documentation for configuring the Paperless webhook and starting the worker locally.

Non-goals:
- Do not migrate the full document processing pipeline yet.
- Do not keep or design a parallel legacy compatibility mode.
- Do not extend the existing Laravel-subprocess/Python-CLI worker path.
- Do not do heavy processing synchronously inside the webhook HTTP request.

Acceptance criteria:
- Governance docs and ADRs exist.
- AGENTS.md points coding agents to the implementation plan and governance rules.
- New infrastructure can be started locally.
- A Paperless webhook can be received, stored, deduplicated and acknowledged quickly.
- A dummy webhook actor can be enqueued and processed.
- Actor execution and event are persisted.
- Tests or smoke commands document the new webhook path.
- No new feature flag such as `ARCHIBOT_WORKER_BACKEND=legacy|absurd` is introduced.
```
