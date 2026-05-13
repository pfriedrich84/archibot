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
│ Paperless-NGX  │◀──▶│   ArchiBot                      │◀──▶│ AI Provider   │
│                │    │   (Laravel + Inertia/Svelte)     │    │ Ollama/LiteLLM│
│ - Dokumente    │    │                                  │    │ - Chat (LLM) │
│ - Metadaten    │    │   Port 8088  (GUI/API)           │    │ - Embeddings │
│ - Tags         │    │   Port 3001  (MCP, optional)     │    │              │
└────────────────┘    └─────────────────────────────────┘    └──────────────┘
                                     │
                                     ▼
                              ┌──────────────────────┐
                              │ PostgreSQL + pgvector │
                              │  (persistent volume)  │
                              └──────────────────────┘
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
│  2. Webhook      (POST /webhook/paperless)   │
│  3. Laravel-GUI  (Worker Job / Reprocess)     │
└──────────────────┬──────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────┐
│  Verarbeitungs-Pipeline (_process_document)  │
│                                              │
│  1. Idempotenz-Check (schon verarbeitet?)    │
│  2. OCR-Korrektur  (optional, nur wenn noetig)│
│  3. Kontext-Suche  (aehnliche Dokumente via  │
│     Embedding-Similarity, pgvector)          │
│  4. Klassifikation (AI-Provider, JSON-Antwort)│
│  4b. Judge-Pass (optional, LLM-as-Judge      │
│      prueft Klassifikation, ggf. Korrektur)  │
│  5. Vorschlag speichern (suggestions-Tabelle)│
│  6. Telegram-Benachrichtigung (optional)     │
│  7. Auto-Commit (bei hoher Confidence)       │
│  8. Embedding speichern (fuer kuenftige      │
│     Kontext-Suchen)                          │
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
| **Webhook** | POST von Paperless nach Consume | Laravel-Orchestrierung bzw. Python-Kompatibilitaet | Ja, antwortet 503 |
| **Inbox-GUI** | Aktion in `/inbox` oder `/worker-jobs` | Laravel `worker_jobs` → Python CLI | Nein (manuell) |
| **CLI** | `archibot <cmd>` / `python -m app.cli <cmd>` | `app/cli.py` | Nein (manuell, blockiert bis fertig) |

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

Nur aktiv, wenn `OCR_MODE` auf `text`, `vision_light` oder `vision_full` gesetzt ist. Heuristik prueft ob der Text typische OCR-Artefakte enthaelt (viele `?`, einzelne Buchstaben-Woerter). Falls ja, wird der Text via LLM korrigiert — nur im Speicher, Paperless wird nicht veraendert.

### 3. Kontext-Suche

- Berechnet Embedding des Zieldokuments via konfiguriertem AI-Provider (`qwen3-embedding:4b` bzw. Provider-Alias wie `qwen3-embedding-4b-local`, Dim via `OLLAMA_EMBED_DIM`/Auto)
- KNN-Suche in `doc_embeddings` (pgvector) findet die aehnlichsten Dokumente
- **Wichtig:** Dokumente die noch im Posteingang liegen werden als Kontext ausgeschlossen — nur reviewte/bestaetigte Dokumente mit zuverlaessigen Metadaten dienen als Referenz
- Kontext-Dokumente enthalten ihre vollstaendige Klassifikation (Korrespondent, Dokumenttyp, Tags, Speicherpfad)

### 4. Klassifikation

- System-Prompt: Built-in aus `prompts/classify_system.txt` oder Custom Override aus `/data/classify_system.txt`
- User-Prompt: Entity-Listen + Kontext-Dokumente mit Metadaten + Zieldokument
- Token-Budgetierung: 60% fuer Zieldokument, 40% fuer Kontext. Zu kleine Kontext-Dokumente werden gedroppt
- Provider-Aufruf mit JSON-Ausgabe (`format: "json"` bei nativer Ollama-API, OpenAI-kompatible Chat-Completions bei `/v1`-Providern), liefert strukturiertes JSON
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
3. Python loescht `doc_embeddings` + `doc_embedding_meta`
4. Alle Dokumente werden aus Paperless geladen
5. Fuer jedes Dokument wird ein neues Embedding berechnet und gespeichert
6. **Fortschritt:** Python schreibt Reindex-/Phase-Fortschritt in die Worker-Daten; Laravel zeigt Jobstatus und Ergebnis an
7. **Inbox-Blockade:** Waehrend des Reindex werden Poll/Webhook-Pfade blockiert, um Raceconditions mit teilweise aufgebauten Embeddings zu vermeiden

## Datenbank-Schema

| Tabelle | Zweck |
|---|---|
| `processed_documents` | Verarbeitungsstatus pro Dokument (Idempotenz) |
| `suggestions` | LLM-Vorschlaege (original vs. proposed, Status pending/committed/rejected) |
| `doc_embeddings` | Virtuelle pgvector Tabelle fuer Vektor-Similarity (1024-dim) |
| `doc_embedding_meta` | Metadaten zu Embeddings (document_id, title, created_at) |
| `tag_whitelist` | Staging fuer unbekannte Tags (name, times_seen, approved) |
| `tag_blacklist` | Abgelehnte Tags — werden bei zukuenftigen Vorschlaegen ignoriert |
| `doc_ocr_cache` | Lokal gecachter korrigierter OCR-Text (nie zurueck nach Paperless) |
| `doc_fts` | FTS5 Volltext-Suchindex (title, content) fuer Hybrid-Suche |
| `errors` | Fehler-Audit-Trail (stage, document_id, message) |
| `audit_log` | Aktions-Audit-Trail (commit, reject, prompt_update) |
| `poll_cycles` | Zusammenfassung pro `poll_inbox()`-Aufruf (started_at, finished_at, succeeded, failed, skipped) |
| `phase_timing` | Pro-Dokument-Pro-Phase Verarbeitungsdauer (poll_cycle_id, phase, duration_ms, success) |

## Docker-Deployment

- **Ein Container:** Laravel/Svelte GUI/API + Laravel Queue Worker + Python Worker/MCP Runtime
- **Ports:** 8088 (Laravel GUI/API), 3001 (MCP, optional)
- **Volumes:** PostgreSQL-Volume fuer App-Datenbank und Embeddings; `/data` fuer App-Key, Logs, Custom Prompts und importierte Legacy-Konfiguration
- **Start:** `entrypoint.sh` erzeugt/persistiert `APP_KEY`, migriert Laravel, startet die Laravel Queue und optional den Python MCP-Server
- **Netzwerk:** Muss Paperless und den konfigurierten AI-Provider (Ollama oder OpenAI-kompatibler Endpoint) erreichen koennen. Bei separaten Compose-Stacks: externe Netzwerke einkommentieren in `docker-compose.yml`
