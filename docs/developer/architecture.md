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
│                │    │   Laravel/Svelte + Python       │    │ Ollama/LiteLLM│
│ - Dokumente    │    │   Workers/MCP                   │    │ - Chat (LLM) │
│ - Metadaten    │    │   Port 8088  (GUI/API)           │    │ - Embeddings │
│ - Tags         │    │   Port 3001  (MCP, optional)     │    │              │
└────────────────┘    └─────────────────────────────────┘    └──────────────┘
                                     │
                         ┌───────────┴───────────┐
                         ▼                       ▼
                  ┌──────────────────────┐ ┌──────────────┐
                  │ PostgreSQL + pgvector │ │   Absurd   │
                  │  (persistent volume)  │ │ Absurd     │
                  └──────────────────────┘ └──────────────┘
```

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
│  7. Review-Suggestion speichern              │
│  8. Optional Auto-Commit ueber Commit Actor   │
└──────────────────┬──────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────┐
│  Review (manuell oder automatisch)           │
│                                              │
│  - GUI /review:  Annehmen / Ablehnen /       │
│    Editieren                                 │
│  - Telegram: Accept / Reject Buttons         │
│  - Auto-Commit: wenn Confidence >=           │
│    AUTO_COMMIT_CONFIDENCE                    │
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
| **Laravel Worker Job** | UI-Aktion, Queue, Scheduler | `laravel/app/Jobs/RunPythonWorkerJob.php` → `app/cli.py` | Ja, via Python Guard |
| **Worker-Poll** | Python CLI/Worker-Kontrakt `poll` | `app/cli.py` → `poll_inbox()` | Ja, ueberspringt mit Log |
| **Webhook** | POST von Paperless nach Consume | Laravel speichert `webhook_deliveries`, Python `app.actors.webhook` startet/attached Pipeline | Ja, Delivery bleibt durable/Run wird blockiert |
| **Inbox-GUI** | Aktion in `/inbox` oder `/worker-jobs` | Laravel `worker_jobs` → Python CLI/Pipeline-Start | Ja, ueber denselben Gate/Run-Status |
| **CLI** | `archibot <cmd>` / `python -m app.cli <cmd>` | `app/cli.py` | Ja fuer event-driven Starts/Reindex; manuelle Legacy-Pfade pruefen Guards |

## Inbox-Seite (`/inbox`)

Die Laravel/Svelte-Inbox-Seite zeigt alle Dokumente, die in Paperless den Inbox-Tag tragen:

- **Quelle:** `GET /api/documents/?tags__id__all=<inbox_tag_id>` gegen Paperless mit dem Token des angemeldeten Paperless-Benutzers
- **Status-Anreicherung:** Fuer jedes Dokument wird der aktuelle Laravel-Review-Status aus `review_suggestions` eingeblendet
- **Fehlerzustand:** Ist Paperless nicht erreichbar oder fehlt die Konfiguration, zeigt Laravel einen expliziten Fehler statt stale Berechtigungen zu erlauben
- **Verarbeitung:** Manuelle Jobs laufen ueber `/worker-jobs`, Laravel `worker_jobs` und den Python CLI-JSON-Kontrakt

## Pipeline-Stufen im Detail

### 1. Idempotenz-Check

Prueft in `processed_documents` ob das Dokument bei diesem `updated_at`-Timestamp schon erfolgreich verarbeitet wurde. Dokumente mit Status `error` werden erneut versucht.

### 2. OCR-Korrektur (optional)

Nur aktiv, wenn `OCR_MODE` auf `text`, `vision_light` oder `vision_full` gesetzt ist. Heuristik prueft ob der Text typische OCR-Artefakte enthaelt (viele `?`, einzelne Buchstaben-Woerter). Falls ja, wird der Text via LLM korrigiert — nur im Speicher, Paperless wird nicht veraendert. Bei `reindex-ocr --force` wird diese Clean-Text-Heuristik fuer `text` und `vision_light` bewusst umgangen; ein gesetzter `OCR_REQUESTED_TAG_ID` bleibt weiterhin bindend.

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

### 7. Auto-Commit

Wenn `AUTO_COMMIT_CONFIDENCE > 0` und das LLM eine Confidence >= diesem Wert meldet, wird der Vorschlag ohne manuellen Review direkt committed. Bei Auto-Commit wird keine Telegram-Benachrichtigung gesendet. Bei aktivem Judge zaehlt die finale (ggf. korrigierte) Confidence.

## Reindex

Der Embedding-Index kann ueber einen Laravel Worker Job oder die Python CLI komplett neu aufgebaut werden:

1. Laravel legt einen `worker_jobs`-Eintrag vom Typ `reindex` an
2. Der Laravel Queue Worker ruft `python -m app.cli reindex --input <json> --output <json>` auf
3. Python startet einen PostgreSQL/pgvector-Embedding-Build und setzt die Embedding-Gate-State auf `building`
4. Alle Paperless-Dokumente ohne konfigurierten Inbox-/Posteingang-Tag werden geladen
5. Fuer jedes vertrauenswuerdige Dokument wird ein neues Embedding mit Metadaten in PostgreSQL gespeichert
6. **Fortschritt:** Python schreibt Reindex-/Phase-Fortschritt in die Worker-Daten; Laravel zeigt Jobstatus und Ergebnis an
7. **Inbox-Blockade:** Waehrend des Reindex werden Poll/Webhook-Pfade blockiert, um Raceconditions mit teilweise aufgebauten Embeddings zu vermeiden

## Datenbank-Schema

| Tabelle | Zweck |
|---|---|
| `processed_documents` | Verarbeitungsstatus pro Dokument (Idempotenz) |
| `suggestions` | LLM-Vorschlaege (original vs. proposed, Status pending/committed/rejected) |
| `document_embeddings` | PostgreSQL/pgvector Embeddings mit Metadaten und `trusted_for_context` fuer Klassifikationskontext |
| `tag_whitelist` | Staging fuer unbekannte Tags (name, times_seen, approved) |
| `tag_blacklist` | Abgelehnte Tags — werden bei zukuenftigen Vorschlaegen ignoriert |
| `doc_ocr_cache` | Lokal gecachter korrigierter OCR-Text (nie zurueck nach Paperless) |
| `errors` | Fehler-Audit-Trail (stage, document_id, message) |
| `audit_log` | Aktions-Audit-Trail (commit, reject, prompt_update) |
| `poll_cycles` | Zusammenfassung pro `poll_inbox()`-Aufruf (started_at, finished_at, succeeded, failed, skipped) |
| `phase_timing` | Pro-Dokument-Pro-Phase Verarbeitungsdauer (poll_cycle_id, phase, duration_ms, success) |

## Docker-Deployment

- **Compose-Stack:** ein ArchiBot-App-Container plus PostgreSQL/pgvector; Absurd laeuft als PostgreSQL-Schema/Queue-Tabellen ohne separaten Broker-Service
- **Ports:** 8088 (Laravel GUI/API), 3001 (MCP, optional)
- **Volumes:** `archibot_postgres` fuer App-Datenbank, Embeddings und Absurd Queue-State; `archibot_data` fuer App-Key, Logs, Custom Prompts und importierte Legacy-Konfiguration
- **Start:** `entrypoint.sh` erzeugt/persistiert `APP_KEY`, migriert Laravel, startet Laravel Queue, Absurd Worker/Recovery und optional den Python MCP-Server
- **Netzwerk:** App-Container muss Paperless, PostgreSQL und den konfigurierten AI-Provider (Ollama oder OpenAI-kompatibler Endpoint) erreichen koennen. Bei separaten Paperless/Ollama-Stacks: externe Netzwerke einkommentieren in `docker-compose.yml`
