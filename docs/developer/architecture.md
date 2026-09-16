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
                         ┌───────────┴────────────┐
                         ▼                        ▼
                  ┌──────────────────────┐ ┌──────────────────┐
                  │ PostgreSQL + pgvector │ │ Temporal Service │
                  │ Business/Projektionen│ │ Workflow-Historie│
                  └──────────────────────┘ └──────────────────┘
```

## Chat/RAG-Containment

Chat/RAG ist fuer Admins und Nicht-Admins deaktiviert. Laravel registriert weder Seite noch API-Routen und besitzt keinen Python-Bridge-Service; die Python-CLI registriert kein `chat-ask`; MCP registriert weder globale Suche/Volltext-Retrieval noch aehnlichkeitsbasierte Retrieval-Tools. Bestehende `chat_sessions`- und `chat_messages`-Zeilen sowie ihre Migration bleiben zur sicheren Datenerhaltung bestehen, werden aber nicht exponiert. [Issue #221](https://github.com/pfriedrich84/archibot/issues/221) ist der einzige Track fuer Redesign und moegliches Re-enable; der [Authorization-safe-RAG-Entwurf](../architecture/authorization-safe-rag-design.md) ist Forschung/Proposal und keine Freigabe.

## Dokument-Lebenszyklus

Ein Dokument durchlaeuft folgende Stationen:

```
Paperless: Dokument hochgeladen → Tag "Posteingang" gesetzt
    │
    ▼
┌─────────────────────────────────────────────┐
│  Eingang (eine der drei Varianten)          │
│                                              │
│  1. Temporal Poll (alle N Sekunden)           │
│  2. Webhook      (POST /api/webhooks/paperless) │
│  3. Laravel-GUI  (Temporal Outbox / Reprocess)│
└──────────────────┬──────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────┐
│  DocumentWorkflow je Dokument-ID/Generation  │
│                                              │
│  1. Start/Attach mit stabilem Workflow-ID    │
│  2. OCR nach Modus und eingefrorenem Tag      │
│  3. Embedding → Klassifikation → Judge        │
│  4. Review speichern und durable warten       │
│  5. Accept/Reject/Force-Reprocess verarbeiten │
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
│  Temporal Review-Commit Activity             │
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

Es gibt **vier Wege**, wie ein Dokument in die Pipeline gelangt:

| Einstiegspunkt | Ausloeser | Code | Blockiert bei Reindex? |
|---|---|---|---|
| **Temporal-Poll** | Admin-/Scheduler-Poll-Reconciliation | Laravel `commands` + transaktionaler Outbox-Intent → `PollReconciliationWorkflow` → globale `document_observations` → ein stabiler `DocumentWorkflow` je Dokument-ID | Der Start wird bis zum vollstaendigen Index fuer das konfigurierte Embedding-Modell reserviert; der Poll selbst blockiert nicht |
| **Webhook** | POST von Paperless nach Consume | Laravel-Middleware prueft Secret, Groesse und Rate-Limit und speichert die redigierte Delivery. Create/Process-Events schreiben `pipeline_runs` und Temporal-Start-Intent atomar. Refresh/Delete bleiben bis zu ihrer Cutover-Phase auf dem festen Legacy-Actor. | Ja, der Temporal-Workflow wartet durable |
| **Maintenance-GUI** | Admin-Aktionen in Maintenance/Dashboard | Embedding, Reindex, Poll und Dokument-Reprocess verwenden Laravel `commands`/`pipeline_runs` plus transaktionalen Temporal-Outbox-Intent; noch nicht migrierte Aktionen verwenden voruebergehend feste `RunPythonActorJob` Actor-Kommandos | Ja, ueber Temporal-Wait und Run-Projektion |
| **CLI** | `archibot <cmd>` / `python -m app.cli <cmd>` | `app/cli.py` delegiert alle Operator-Aktionen an Laravel durable Commands/Pipeline/Review; Review-Entscheidungen schreiben denselben Temporal-Intent wie die GUI | Ja; keine SQLite-Initialisierung oder JSON-Worker-Bridge |

## Inbox-Seite (`/inbox`)

Die Laravel/Svelte-Inbox-Seite zeigt alle Dokumente, die in Paperless den Inbox-Tag tragen:

- **Quelle:** `GET /api/documents/?tags__id__all=<inbox_tag_id>` gegen Paperless mit dem Token des angemeldeten Paperless-Benutzers
- **Status-Anreicherung:** Fuer jedes Dokument wird der aktuelle Laravel-Review-Status aus `review_suggestions` eingeblendet
- **Fehlerzustand:** Ist Paperless nicht erreichbar oder fehlt die Konfiguration, zeigt Laravel einen expliziten Fehler statt stale Berechtigungen zu erlauben
- **Verarbeitung:** Manuelle Admin-Verarbeitung startet durable `pipeline_runs` und `commands` aus Maintenance oder Review-Aktionen. `/operations-log` zeigt durable Commands, Pipeline Runs/Events/Items, Actor Executions, Webhooks und Audit-Logs. Die alte `/worker-jobs` Oberflaeche und der `worker_jobs` Backendpfad sind fuer Clean Installs entfernt; es gibt keine `/legacy-worker-jobs` Route, keine Migration alter Worker-Zeilen und keine Backend-Kompatibilitaet fuer historischen Worker-Job-State.

## Pipeline-Stufen im Detail

### 1. Idempotenz-Check

Die Temporal-Poll-Aktivitaet laedt vor dem Workflow-Start die dauerhaften Klassifikationsmarker aus PostgreSQL: Sobald fuer ein Paperless-Dokument ein `review_suggestions`-Eintrag existiert, ist die Klassifikation mindestens einmal erfolgreich abgeschlossen. Solche Inbox-Dokumente werden bei automatischen Polls uebersprungen. Das verhindert erneute LLM-Klassifikation nach Review/Commit, wenn `KEEP_INBOX_TAG=true` ist.

Fuer noch nicht markierte Dokumente koordinieren Poll, Webhook und manuelle Starts ueber `pipeline_runs.pipeline_dedupe_key` und den stabilen Temporal-Workflow-ID. Poll-Beobachtungen sind global und nicht an die Lebensdauer des Poll-Kommandos gebunden. Explizite Force-Polls und manuelles Force-Reprocess erzeugen absichtlich eine neue Version. Ein vorhandener pending/blocked Legacy-Run darf atomar uebernommen werden; queued/running Legacy-Runs werden wegen moeglicher Parallelausfuehrung nie adoptiert.

Normale Starts verwenden `archibot/document/{paperless_document_id}` und werden nach
erfolgreichem Abschluss nicht automatisch wiederholt. Ein autorisiertes Force-Reprocess
verwendet `archibot/document/{paperless_document_id}/reprocess/{generation}`. Ist der
Embedding-Index fuer das aktuell konfigurierte Embedding-Modell noch nicht vollstaendig,
bleibt die Identitaet reserviert, ohne einen DocumentWorkflow zu starten. Recovery gibt
den Start frei, sobald genau dieses Modell einen vollstaendigen Index besitzt.

Jeder neue `DocumentWorkflow` fuehrt seine eigene Sequenz aus: OCR wird entsprechend
Modus und konfiguriertem Tag ausgefuehrt oder sichtbar uebersprungen, danach folgen
Zieldokument-Embedding, Klassifikation, Judge und der dauerhafte Review-Wartezustand.
Provider, Rollenmodelle, OCR-Tag und Kontextfenster werden einmal pro Dokumentlauf
eingefroren. Die festen Activity-Queues `archibot-model-embedding`,
`archibot-model-ocr-text`, `archibot-model-ocr-vision`,
`archibot-model-classification`, `archibot-model-judge` und `archibot-paperless`
halten Modellrollen und Paperless-Zugriffe getrennt. Der fruehere singleton
`ModelPhaseSchedulerWorkflow` wurde nach dem Reset seiner Historien entfernt. Eine leere
Index-Generation endet ohne Provider-Aufruf terminal als `0/0 complete`.

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
- **Unbekannte Tags:** Landen in PostgreSQL `entity_approvals` mit Status `pending`. Muessen unter `/tags` manuell freigegeben werden. Bei Freigabe wird der Tag retroaktiv auf bereits committete Dokumente angewendet und in offenen Vorschlaegen voraufgeloest.

### 6. Judge-Pass (optional)

Wenn `ENABLE_JUDGE_VERIFICATION=true`, laeuft nach der Klassifikation ein zweiter LLM-Pass ("Judge"), der die Erst-Klassifikation prueft. Gate:

- Initial-Confidence muss `< JUDGE_CONFIDENCE_THRESHOLD` sein (Default 85) — hohe Confidence wird durchgewunken.
- Es muessen Kontext-Dokumente vorhanden sein — sonst hat der Judge keine bessere Grundlage als der Erst-Pass.

Der Judge bekommt Zieldokument + Kontext + den Erst-Vorschlag und gibt einen `JudgeVerdict` zurueck: `agree`, `corrected`, `skipped` oder `error`. Bei `corrected` ersetzt das neue JSON die Erst-Klassifikation; der Original-Vorschlag wird als `original_proposed_json` in der Suggestion erhalten (Audit). Der Judge nutzt per Default dasselbe Modell (`OLLAMA_MODEL`) — kein zusaetzlicher GPU-Swap. Alternativ via `OLLAMA_JUDGE_MODEL`. Transport-/Parse-Fehler werden als `verdict="error"` geloggt; die Pipeline behaelt die Erst-Klassifikation.

### 7. Manuelle Review-Commit-Grenze

ADR-0018 ist als Containment umgesetzt: `AUTO_COMMIT_CONFIDENCE` wird im Laravel-Runtime-Export und beim Python-Config-Load auf `0` gezwungen. Der Document Actor speichert auch bei adversarialem Inhalt, Modell-Confidence `100` oder Judge-Zustimmung nur einen pending Review-Vorschlag. Sie akzeptieren ihn nicht, erzeugen keinen `review_commit` Command und rufen keinen Paperless-PATCH aus Confidence auf.

Eine autorisierte manuelle Entscheidung schreibt Review-Status und Temporal-Outbox-Intent atomar. Bei einem aktuellen Dokument-Workflow sendet der Relay ein stabiles `review_decision`-Signal; angenommene Vorschlaege ohne wartenden Dokument-Workflow starten `ReviewCommitWorkflow` mit stabiler ID. Ablehnung beendet den Dokument-Workflow ohne Paperless-Write. Force-Reprocess markiert einen noch offenen Vorschlag als stale, signalisiert dem bisherigen Workflow `force_reprocess` und startet eine getrennte immutable Workflow-Generation. Die Commit-Aktivitaet laedt die `review_suggestion_id` aus PostgreSQL und fuehrt den Paperless-PATCH idempotent aus. Ein Worker-Ausfall nach erfolgreichem PATCH erkennt beim Retry bereits passende Metadaten und schliesst die Projektion ohne zweiten Write. Der zentrale Client erlaubt nur die geprueften Metadatenfelder. Die Review-Seite laedt `storage_path` live aus Paperless und zeigt den aufgeloesten Namen; ein vorhandener Wert ist gesperrt und bleibt unveraenderlich. Nur ein live gemeldetes `null` darf ueber die Review-Naht zu einer positiven ID werden. OCR-/Content-/Datei-/Versionsfelder bleiben vor HTTP-Dispatch verboten.

## Reindex

Embedding-Build, Reindex, Poll-Reconciliation, Dokumentverarbeitung und Review-Commit werden von Temporal ausgefuehrt. OCR-Reindex und nicht-prozessierende Webhook-Aktionen verwenden bis zu ihrer jeweiligen Cutover-Phase weiterhin Laravel queued actor jobs mit festen Python-Actor-Kommandos. Webhook-Refresh/Delete nutzt `python -m app.actor_runner handle-webhook --delivery-id <webhook_deliveries.id>`; Python laedt die von Laravel normalisierte Aktion aus der Delivery.

1. Laravel legt einen `commands`-Eintrag vom Typ `embedding_index_build` oder `reindex` an
2. Laravel setzt das Gate unter einem exklusiven PostgreSQL-Fence auf `stale` und schreibt Command sowie unveraenderlichen Temporal-Outbox-Intent atomar
3. Der Python-Outbox-Relay startet das stabile `EmbeddingIndexWorkflow` genau einmal; der Laravel-Recovery-Scan ignoriert Temporal-eigene Commands. Erschoepft der Relay seine Zustellversuche, setzt derselbe PostgreSQL-Commit den betroffenen Command sichtbar auf `failed_permanent`
4. Eine idempotente Temporal-Aktivitaet startet oder uebernimmt die commandgebundene PostgreSQL/pgvector-Generation und setzt ihren Zustand auf `building`
5. Alle Paperless-Dokumente ohne konfigurierten Inbox-/Posteingang-Tag werden geladen
6. Fuer jedes vertrauenswuerdige Dokument wird ein neues Embedding mit Metadaten in PostgreSQL gespeichert
7. **Fortschritt:** Temporal schreibt nach jedem terminalen Dokument exakte Zaehler nach PostgreSQL; die Abschlussaktivitaet setzt Generation und Command gemeinsam auf terminal. Eine leere Instanz endet ohne Provider-Aufruf als `0/0 complete`
8. **Inbox-Blockade:** Das Gate ist bereits vor Commit des Start-Intents geschlossen und wird erst durch eine erfolgreiche Generation wieder geoeffnet. Lange Provider-Aufrufe senden Temporal-Heartbeats

## Diagnose- und Operations-Grenze

Globale Operations-Daten sind privilegierte Systemdiagnostik. Eine gemeinsame Laravel-Admin-Middleware laeuft vor dem Route Model Binding fuer Operations Log, Pipeline Runs, Webhook Deliveries, Actor Executions, Statistiken, Fehler, Embedding-Diagnostik, Maintenance und Audit. Dadurch liefern direkte Nicht-Admin-Aufrufe immer `403`, unabhaengig davon, ob eine angefragte Run- oder Delivery-ID existiert. Mutierende Controller pruefen den Admin-Status zusaetzlich unmittelbar vor der Zustandsaenderung.

Die Browser-Datenvertraege enthalten nur explizit erlaubte skalare Metadaten mit Labels. Webhook-Headers und rohe/normalisierte Payloads werden nicht dargestellt; unbekannte oder verschachtelte Metadaten werden verworfen. Freie Fehler-, Event- und Fortschrittstexte werden durch einen festen Redaktionshinweis ersetzt, damit Tokens, Authorization-Header, Dokument-/OCR-Inhalt oder Prompts nicht ueber Diagnoseansichten offengelegt werden. Provider-Typen und interne Fehlerklassen/-arten werden nur aus kanonischen, im Quellcode inventarisierten Mengen angezeigt; unbekannte Werte erhalten nicht rueckrechenbare Referenzen. Konfigurierbare Provider- und Modell-IDs werden ungeachtet ihrer Zeichenform nie woertlich ausgegeben, sondern als stabile Referenz dargestellt. Status, Fehlerart, IDs, Zaehler und Ereignis-Timelines bleiben fuer Retry- und Recovery-Diagnose erhalten.

## Datenbank-Schema

| Tabelle | Zweck |
|---|---|
| `chat_sessions`, `chat_messages` | Erhaltene historische Chat-Daten; normale Produkt- und Chat-Oberflaechen lesen, zeigen oder loeschen diese Zeilen nicht. Nur der ausdruecklich bestaetigte vollstaendige Operator-Reset (`archibot reset` / `php artisan archibot:reset`) bleibt destruktiv und leert sie. |
| `review_suggestions` | Dauerhafte Review-Vorschlaege; ihre Existenz ist zugleich der Klassifikationsmarker fuer automatische Polls |
| `document_embeddings` | PostgreSQL/pgvector Embeddings mit Metadaten und `trusted_for_context` fuer Klassifikationskontext |
| `entity_approvals` | PostgreSQL-eigene Staging-, Freigabe- und Blacklist-Grenze fuer unbekannte Tags, Korrespondenten und Dokumenttypen; produktive Klassifikation liest abgelehnte Namen ausschliesslich hier. |
| `document_ocr_corrections` | Gemeinsamer PostgreSQL-Cache fuer lokal korrigierten OCR-Text (nie zurueck nach Paperless). |
| `document_observations` | Globale, versionierte Poll-/Webhook-Beobachtungen mit Zuordnung zum unabhaengigen Temporal-Dokumentworkflow |
| `poll_candidates` | Nur noch Legacy-Retention fuer vor dem Cutover erzeugte Poll-Handoffs; Temporal-Polls schreiben keine neuen Kandidaten |

## Docker-Deployment

- **Compose-Stack:** ArchiBot, PostgreSQL/pgvector, privater Temporal-Server und getrennte Temporal-PostgreSQL-Persistenz. Noch nicht migrierte Ablaufe verwenden waehrend des zeitlich begrenzten Cutovers weiterhin Laravel Database Queues.
- **Ports:** 8088 (Laravel GUI/API), 3001 (MCP, optional), 8233 fuer die standardmaessig read-only und an Loopback gebundene Temporal UI. Temporal gRPC wird nicht am Host veroeffentlicht.
- **Volumes:** `archibot_postgres` fuer App-Datenbank und Produktprojektionen, `archibot_temporal_postgres` fuer Workflow-Historie und `archibot_data` fuer App-Key, Logs und Custom Prompts. Ein vorhandenes Legacy-`classifier.db` bleibt bei Upgrades inert.
- **Start:** Compose migriert zuerst die gepinnten Temporal-Schemata und legt den Namespace idempotent an. `entrypoint.sh` erzeugt/persistiert `APP_KEY`, migriert Laravel und startet Web-App, Temporal-Worker, Outbox-Relay sowie die noch benoetigten Legacy-Prozesse unter Supervisor.
- **Netzwerk:** App-Container muss Paperless, App-PostgreSQL, den privaten Temporal-Frontenddienst und den konfigurierten AI-Provider erreichen koennen. Bei separaten Paperless/Ollama-Stacks: externe Netzwerke einkommentieren in `docker-compose.yml`.
