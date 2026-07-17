# Architektur

Gesamtueberblick ueber den Aufbau und die Datenflussrichtung von ArchiBot.

## System-Kontext

```
                  ┌──────────────┐
                  │   Browser    │
                  └──────┬───────┘
                         │ HTTP
                         ▼
┌────────────────┐    ┌─────────────────────────────────┐    ┌──────────────┐
│ Paperless-NGX  │◀──▶│   ArchiBot App                  │◀──▶│ AI Provider   │
│                │    │   Laravel/Svelte + Python       │    │ AI Provider   │
│ - Dokumente    │    │   Workers/MCP                   │    │ - LLM calls  │
│ - Metadaten    │    │   Port 8088  (GUI/API)           │    │ - Embeddings │
│ - Tags         │    │   Port 3001  (MCP, optional)     │    │              │
└────────────────┘    └─────────────────────────────────┘    └──────────────┘
                                     │
                         ┌───────────┴───────────┐
                         ▼                       ▼
                  ┌──────────────────────┐ ┌──────────────┐
                  │ PostgreSQL + pgvector │ │ Laravel Queue│
                  │  (persistent volume)  │ │ database drv │
                  └──────────────────────┘ └──────────────┘
```

## Chat/RAG-Containment

Chat/RAG ist fuer Admins und Nicht-Admins deaktiviert. Laravel registriert weder Seite noch API-Routen und besitzt keinen Python-Bridge-Service; die Python-CLI registriert kein `chat-ask`; MCP registriert weder globale Suche/Volltext-Retrieval noch aehnlichkeitsbasierte Retrieval-Tools. Bestehende `chat_sessions`- und `chat_messages`-Zeilen sowie ihre Migration bleiben zur sicheren Datenerhaltung bestehen, werden aber nicht exponiert. [Issue #221](https://github.com/pfriedrich84/archibot/issues/221) ist der einzige Track fuer Redesign und moegliches Re-enable.

## Dokument-Lebenszyklus

Ein Dokument durchlaeuft folgende Stationen:

```
Paperless: Dokument hochgeladen → Tag "Posteingang" gesetzt
    │
    ▼
┌─────────────────────────────────────────────┐
│  Eingang (eine der drei Varianten)          │
│                                              │
│  1. Worker-Poll  (alle N Sekunden)           │
│  2. Webhook      (POST /api/webhooks/paperless) │
│  3. Laravel-GUI  (Worker Job / Reprocess)     │
└──────────────────┬──────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────┐
│  Durable Pipeline (pipeline_runs/items)      │
│                                              │
│  1. Start/Attach mit Dedupe-Key              │
│  2. Embedding-Readiness-Gate                 │
│  3. Paperless-Fetch durch Document Actor     │
│  4. OCR-Korrektur (optional)                 │
│  5. Kontext-Suche via document_embeddings    │
│  6. Klassifikation + optionaler Judge        │
│  7. Pending Review-Suggestion speichern      │
│  8. Kein Confidence-basierter Write (ADR-0018)│
└──────────────────┬──────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────┐
│  Review (nur manuelle Entscheidung)          │
│                                              │
│  - GUI /review: Annehmen / Ablehnen /        │
│    Editieren                                 │
│  - Confidence/Judge sind keine Write-        │
│    Autorisierung                             │
└──────────────────┬──────────────────────────┘
                   │ Accept
                   ▼
┌─────────────────────────────────────────────┐
│  Commit (committer.py)                       │
│                                              │
│  PATCH /api/documents/{id}/ →                │
│   - Titel, Datum, Korrespondent              │
│   - Dokumenttyp, Speicherpfad                │
│   - Tags (merge: bestehende + vorgeschlagene)│
│   - Posteingang-Tag: bleibt (default) oder   │
│     wird entfernt (KEEP_INBOX_TAG=false)     │
│   - Processed-Tag: wird gesetzt (optional)   │
└─────────────────────────────────────────────┘
```

## Einstiegspunkte fuer die Dokumentverarbeitung

Es gibt **fuenf Wege**, wie ein Dokument in die Pipeline gelangt:

| Einstiegspunkt | Ausloeser | Code | Blockiert bei Reindex? |
|---|---|---|---|
| **Worker-Poll** | Admin-/Scheduler-Poll-Reconciliation | Laravel `commands` → `RunPythonActorJob::pollReconciliation(<command-id>)` → festes Python-Actor-Kommando | Ja, ueberspringt mit Log |
| **Webhook** | POST von Paperless nach Consume | Laravel speichert `webhook_deliveries`; fuer Create/Process-Events startet es `pipeline_runs` und queued `RunPythonActorJob::documentPipeline(<pipeline-run-id>)`, fuer Refresh/Delete-Events queued es `RunPythonActorJob::webhookDelivery(<webhook-delivery-id>)` | Ja, Delivery bleibt durable/Run wird blockiert |
| **Maintenance-GUI** | Admin-Aktionen in Maintenance/Dashboard | Laravel `commands` oder `pipeline_runs` → feste `RunPythonActorJob` Actor-Kommandos | Ja, ueber Gate/Run-Status |
| **CLI** | `archibot <cmd>` / `python -m app.cli <cmd>` | `app/cli.py`; Ziel ist Delegation an Laravel durable Commands fuer produktive Operator-Aktionen | Ja fuer event-driven Starts/Reindex; manuelle Legacy-Pfade pruefen Guards |

## Inbox-Seite (`/inbox`)

Die Laravel/Svelte-Inbox-Seite zeigt alle Dokumente, die in Paperless den Inbox-Tag tragen:

- **Quelle:** `GET /api/documents/?tags__id__all=<inbox_tag_id>` gegen Paperless mit dem Token des angemeldeten Paperless-Benutzers
- **Status-Anreicherung:** Fuer jedes Dokument wird der aktuelle Laravel-Review-Status aus `review_suggestions` eingeblendet
- **Fehlerzustand:** Ist Paperless nicht erreichbar oder fehlt die Konfiguration, zeigt Laravel einen expliziten Fehler statt stale Berechtigungen zu erlauben
- **Verarbeitung:** Manuelle Admin-Verarbeitung startet durable `pipeline_runs` und `commands` aus Maintenance oder Review-Aktionen. `/operations-log` zeigt durable Commands, Pipeline Runs/Events/Items, Actor Executions, Webhooks und Audit-Logs. Die alte `/worker-jobs` Oberflaeche und der `worker_jobs` Backendpfad sind fuer Clean Installs entfernt; es gibt keine `/legacy-worker-jobs` Route, keine Migration alter Worker-Zeilen und keine Backend-Kompatibilitaet fuer historischen Worker-Job-State.

## Pipeline-Stufen im Detail

### 1. Idempotenz-Check

Der event-driven Poll laedt vor dem Pipeline-Start die dauerhaften Klassifikationsmarker aus PostgreSQL: Sobald fuer ein Paperless-Dokument ein `review_suggestions`-Eintrag existiert, ist die Klassifikation mindestens einmal erfolgreich abgeschlossen. Solche Inbox-Dokumente werden bei automatischen Polls unabhaengig von spaeteren Paperless-`modified`-Aenderungen uebersprungen. Das verhindert erneute LLM-Klassifikation nach Review/Commit, wenn `KEEP_INBOX_TAG=true` ist.

Fuer noch nicht markierte Dokumente koordinieren Poll und Webhook weiterhin ueber den gemeinsamen `pipeline_runs.pipeline_dedupe_key`. Explizite Force-Polls und manuelles Force-Reprocess umgehen den poll-spezifischen Marker und erzeugen neue Pipeline Runs; die Webhook-Action-Policy bleibt unberuehrt. `processed_documents` ist nur noch Idempotenzzustand des nicht automatisch gestarteten Legacy-Python-Pollpfads und darf fuer neue event-driven Funktionalitaet nicht erweitert werden.

### 2. OCR-Korrektur (optional)

Nur aktiv, wenn `OCR_MODE` auf `text`, `vision_light` oder `vision_full` gesetzt ist. Heuristik prueft ob der Text typische OCR-Artefakte enthaelt (viele `?`, einzelne Buchstaben-Woerter). Falls ja, wird der Text via LLM korrigiert — nur im Speicher, Paperless wird nicht veraendert. Bei `reindex-ocr --force` wird diese Clean-Text-Heuristik fuer `text` und `vision_light` bewusst umgangen; ein gesetzter `OCR_REQUESTED_TAG_ID` bleibt weiterhin bindend.

Die Laravel-OCR-Review-Oberfläche unter `/ocr-reviews` ist davon getrennt ein lokales Snapshot-Modul. Sie lädt zunächst nur lokale Review-/Paperless-IDs, prüft live mit dem Token des angemeldeten Nutzers die Paperless-Sichtberechtigung und paginiert erst danach. Detailinhalte werden erst nach erfolgreicher Prüfung geladen. Store, lokale Freigabe und Ablehnung prüfen unmittelbar vor der Mutation live die Paperless-Änderungsberechtigung. ArchiBot-Adminstatus ist kein Bypass; Authentifizierungs- und API-Fehler schließen den Zugriff. Laravel besitzt keinen Helper und keine OCR-Route mehr, die Paperless-Dokumentinhalt per PATCH schreibt oder wiederherstellt. Historische OCR-Statusfelder und Snapshots bleiben zur Retention lesbar.

### 3. Kontext-Suche

- Berechnet Embedding des Zieldokuments via konfiguriertem AI-Provider (`qwen3-embedding:4b` bzw. Provider-Alias wie `qwen3-embedding-4b-local`, Dim via `OLLAMA_EMBED_DIM`/Auto)
- KNN-Suche in `document_embeddings` (pgvector) findet die aehnlichsten aktuellen Embeddings pro Paperless-Dokument
- **Wichtig:** Dokumente die noch im Posteingang liegen werden als Kontext ausgeschlossen — als vertrauenswuerdiger Klassifikationskontext gelten Paperless-Dokumente ohne den konfigurierten Inbox-/Posteingang-Tag
- Kontext-Dokumente enthalten ihre vollstaendige Klassifikation (Korrespondent, Dokumenttyp, Tags, Speicherpfad)

### 4. Klassifikation

- System-Prompt: Built-in aus `prompts/classify_system.txt` oder Custom Override aus `/data/classify_system.txt`
- User-Prompt: Entity-Listen + Kontext-Dokumente mit Metadaten + Zieldokument
- Token-Budgetierung: 60% fuer Zieldokument, 40% fuer Kontext. Zu kleine Kontext-Dokumente werden gedroppt
- Provider-Aufruf ueber die neutrale AI-Provider-Schnittstelle mit JSON-Ausgabe (`format: "json"` beim nativen Ollama-Adapter, OpenAI-kompatible Chat-Completions bei `/v1`-Providern), liefert strukturiertes JSON
- Ergebnis: Titel, Datum, Korrespondent, Dokumenttyp, Speicherpfad, Tags (mit Confidence), Gesamt-Confidence, Reasoning

### 5. Tag-Whitelist

Vom LLM vorgeschlagene Tags werden gegen die existierenden Paperless-Tags abgeglichen:
- **Bekannte Tags:** Werden direkt mit ihrer ID gespeichert
- **Unbekannte Tags:** Landen in `tag_whitelist` mit Status `pending`. Muessen unter `/tags` manuell freigegeben werden. Bei Freigabe wird der Tag retroaktiv auf bereits committete Dokumente angewendet und in offenen Vorschlaegen voraufgeloest

### 6. Judge-Pass (optional)

Wenn `ENABLE_JUDGE_VERIFICATION=true`, laeuft nach der Klassifikation ein zweiter LLM-Pass ("Judge"), der die Erst-Klassifikation prueft. Gate:

- Initial-Confidence muss `< JUDGE_CONFIDENCE_THRESHOLD` sein (Default 85) — hohe Confidence wird durchgewunken.
- Es muessen Kontext-Dokumente vorhanden sein — sonst hat der Judge keine bessere Grundlage als der Erst-Pass.

Der Judge bekommt Zieldokument + Kontext + den Erst-Vorschlag und gibt einen `JudgeVerdict` zurueck: `agree`, `corrected`, `skipped` oder `error`. Bei `corrected` ersetzt das neue JSON die Erst-Klassifikation; der Original-Vorschlag wird als `original_proposed_json` in der Suggestion erhalten (Audit). Der Judge nutzt per Default dasselbe Modell (`OLLAMA_MODEL`) — kein zusaetzlicher GPU-Swap. Alternativ via `OLLAMA_JUDGE_MODEL`. Transport-/Parse-Fehler werden als `verdict="error"` geloggt; die Pipeline behaelt die Erst-Klassifikation.

Timing wird separat unter `phase='judge'` in `phase_timing` erfasst.

### 7. Manuelle Review-Commit-Grenze

ADR-0018 ist als Containment umgesetzt: `AUTO_COMMIT_CONFIDENCE` wird im Laravel-Runtime-Export und beim Python-Config-Load auf `0` gezwungen. Document Actor sowie legacy/phased Processing speichern auch bei adversarialem Inhalt, Modell-Confidence `100` oder Judge-Zustimmung nur einen pending Review-Vorschlag. Sie akzeptieren ihn nicht, erzeugen keinen `review_commit` Command und rufen keinen Paperless-PATCH aus Confidence auf.

Eine autorisierte manuelle Annahme bleibt unveraendert: Sie erzeugt einen dauerhaften `commands`-Eintrag vom Typ `review_commit` und queued `RunPythonActorJob::reviewCommit(<command-id>)`. Das feste Python-Kommando `python -m app.actor_runner commit-review --command-id <commands.id>` laedt die `review_suggestion_id` aus `commands.payload` und fuehrt den Paperless-PATCH in Python aus; Laravel bleibt Transport und Kontrollflaeche.

## Reindex

Embedding-Build, Reindex, Poll-Reconciliation, Review-Commit, Dokumentverarbeitung und nicht-prozessierende Webhook-Aktionen laufen ueber Laravel queued actor jobs mit festen Python-Actor-Kommandos. Der feste Python-Runner startet Embedding-Builds ueber `python -m app.actor_runner build-embedding-index --command-id <commands.id>` und laedt Optionen wie `limit` ausschliesslich aus `commands.payload`. Webhook-Refresh/Delete nutzt `python -m app.actor_runner handle-webhook --delivery-id <webhook_deliveries.id>`; Python laedt die von Laravel normalisierte Aktion aus der Delivery.

1. Laravel legt einen `commands`-Eintrag vom Typ `embedding_index_build` oder `reindex` an
2. Laravel queued `RunPythonActorJob::embeddingIndexBuild(<command-id>)` oder `RunPythonActorJob::reindex(<command-id>)` ueber die Laravel Database Queue
3. Der Laravel Queue Worker ruft das allowlistete Python-Kommando `python -m app.actor_runner build-embedding-index --command-id <commands.id>` auf
4. Python startet einen PostgreSQL/pgvector-Embedding-Build und setzt die Embedding-Gate-State auf `building`
5. Alle Paperless-Dokumente ohne konfigurierten Inbox-/Posteingang-Tag werden geladen
6. Fuer jedes vertrauenswuerdige Dokument wird ein neues Embedding mit Metadaten in PostgreSQL gespeichert
7. **Fortschritt:** Python schreibt Reindex-/Phase-Fortschritt in dauerhafte Pipeline-/Command-State-Tabellen; Laravel zeigt Status und Ergebnis aus PostgreSQL an
8. **Inbox-Blockade:** Waehrend des Reindex werden Poll/Webhook-Pfade blockiert, um Raceconditions mit teilweise aufgebauten Embeddings zu vermeiden

## Datenbank-Schema

| Tabelle | Zweck |
|---|---|
| `chat_sessions`, `chat_messages` | Erhaltene historische Chat-Daten; normale Produkt- und Chat-Oberflaechen lesen, zeigen oder loeschen diese Zeilen nicht. Nur der ausdruecklich bestaetigte vollstaendige Operator-Reset (`archibot reset` / `php artisan archibot:reset`) bleibt destruktiv und leert sie. |
| `review_suggestions` | Dauerhafte Review-Vorschlaege; ihre Existenz ist zugleich der Klassifikationsmarker fuer automatische Polls |
| `processed_documents` | Legacy-Python-Pollstatus; nicht Source of Truth fuer den event-driven Pfad |
| `suggestions` | Legacy-LLM-Vorschlaege (original vs. proposed, Status pending/committed/rejected) |
| `document_embeddings` | PostgreSQL/pgvector Embeddings mit Metadaten und `trusted_for_context` fuer Klassifikationskontext |
| `tag_whitelist` | Staging fuer unbekannte Tags (name, times_seen, approved) |
| `tag_blacklist` | Abgelehnte Tags — werden bei zukuenftigen Vorschlaegen ignoriert |
| `doc_ocr_cache` | Lokal gecachter korrigierter OCR-Text (nie zurueck nach Paperless) |
| `errors` | Fehler-Audit-Trail (stage, document_id, message) |
| `audit_log` | Aktions-Audit-Trail (commit, reject, prompt_update) |
| `poll_cycles` | Zusammenfassung pro `poll_inbox()`-Aufruf (started_at, finished_at, succeeded, failed, skipped) |
| `phase_timing` | Pro-Dokument-Pro-Phase Verarbeitungsdauer (poll_cycle_id, phase, duration_ms, success) |

## Docker-Deployment

- **Compose-Stack:** ein ArchiBot-App-Container plus PostgreSQL/pgvector; Laravel Database Queues laufen ohne separaten Broker-Service
- **Ports:** 8088 (Laravel GUI/API), 3001 (MCP, optional)
- **Volumes:** `archibot_postgres` fuer App-Datenbank, Embeddings, Pipeline-State und Laravel Queue-State; `archibot_data` fuer App-Key, Logs, Custom Prompts und importierte Legacy-Konfiguration
- **Start:** `entrypoint.sh` erzeugt/persistiert `APP_KEY`, migriert Laravel und startet Web-App, Laravel Queue Worker, `schedule:work`, Laravel-native Recovery sowie optional den Python MCP-Server. Supervisor startet keine Absurd Worker/Recovery-Prozesse mehr; verbleibender Absurd-Code und das Schema sind separates Cleanup-Delta.
- **Netzwerk:** App-Container muss Paperless, PostgreSQL und den konfigurierten AI-Provider (Ollama oder OpenAI-kompatibler Endpoint) erreichen koennen. Bei separaten Paperless/Ollama-Stacks: externe Netzwerke einkommentieren in `docker-compose.yml`
