# CLI Commands

Der Classifier stellt CLI-Befehle bereit, die im laufenden Container oder lokal
ausgefuehrt werden koennen. Sie sind nuetzlich fuer Wartung, Debugging und
manuelles Ausloesen der Pipeline-Phasen.

Worker-Jobs, die ueber Laravel (`/worker-jobs`, Scheduler oder Webhooks) laufen,
werden in der Tabelle `worker_jobs` persistiert. Die JSON-Worker-Ausfuehrung
schreibt Status, Fortschritt und strukturierte Logs; aktive Reindex-Jobs blockieren
andere Jobs, waehrend `poll` und mehrere `process-doc` Jobs parallel laufen koennen,
solange nicht dieselbe Paperless-Dokument-ID aktiv ist.

## Aufruf

```bash
# Installiert (Docker / pip install -e .)
archibot <command> [flags]

# Alternativ als Python-Modul
python -m app.cli <command> [flags]

# Im Docker-Container
docker exec -it archibot archibot <command> [flags]
```

## Befehle

### `reindex` — Voller Reindex

Baut den PostgreSQL/pgvector-Embedding-Index neu auf.
Fuehrt optional OCR-Korrektur durch (wenn `OCR_MODE != off`).

```bash
archibot reindex
```

**Was passiert:**
1. Die Embedding-Gate-State wird auf `building` gesetzt
2. Phase 0: OCR-Korrektur fuer alle Dokumente (wenn aktiviert), Ergebnisse in `doc_ocr_cache`
3. Phase 1: Embeddings fuer vertrauenswuerdige Dokumente ohne Inbox-/Posteingang-Tag berechnen und in PostgreSQL/pgvector schreiben

**Wann nutzen:** Nach Wechsel des Embedding-Modells, bei beschaedigter Vektor-DB,
oder beim ersten Setup.

---

### `reindex-ocr` — Nur OCR-Korrektur

Fuehrt OCR-Korrektur ueber alle Paperless-Dokumente aus, ohne Embeddings
neu zu berechnen. Respektiert die `OCR_MODE`-Einstellung.

```bash
# Nur neue Dokumente (Cache wird respektiert)
archibot reindex-ocr

# Alle Dokumente neu korrigieren (Cache ignorieren)
archibot reindex-ocr --force
```

**Flags:**
| Flag | Beschreibung |
|------|-------------|
| `--force` | OCR-Cache ignorieren und alle Dokumente neu korrigieren. Ohne dieses Flag werden bereits gecachte Korrekturen uebersprungen. |

**Was passiert:**
- Alle Dokumente aus Paperless werden geholt
- Fuer jedes Dokument wird `maybe_correct_ocr()` ausgefuehrt
- Ergebnisse landen in `doc_ocr_cache` (nie in Paperless)
- Bereits gecachte Korrekturen werden uebersprungen (ausser mit `--force`)

**Wann nutzen:** Nach Wechsel des OCR-Modells oder der OCR-Stufe.
Mit `--force` wenn vorhandene Korrekturen unbrauchbar sind und neu erzeugt werden sollen.

---

### `reindex-embed` — Nur Embeddings neu berechnen

Startet einen PostgreSQL/pgvector-Embedding-Build fuer vertrauenswuerdige Dokumente ohne Inbox-/Posteingang-Tag. Nutzt gecachte OCR-Texte aus `doc_ocr_cache` (falls vorhanden), fuehrt aber keine neue OCR-Korrektur durch.

```bash
archibot reindex-embed
```

**Was passiert:**
1. Ein dauerhafter Embedding-Build wird in PostgreSQL gestartet
2. Fuer jedes vertrauenswuerdige Dokument: OCR-Cache pruefen, dann Embedding berechnen und in `document_embeddings` speichern

**Wann nutzen:** Nach Wechsel des Embedding-Modells, wenn OCR-Cache
bereits aktuell ist.

---

### `poll` — Inbox verarbeiten

Fuehrt einen einzelnen Poll-Durchlauf aus — identisch zum automatischen
Scheduler-Job, aber manuell ausgeloest.

```bash
archibot poll

# Inbox-Dokumente erneut verarbeiten (Idempotency-Skip ignorieren)
archibot poll --force
```

**Flags:**
| Flag | Beschreibung |
|------|-------------|
| `--force` | Ignoriert den Idempotency-Skip (`processed_documents`) und verarbeitet Inbox-Dokumente erneut, auch wenn sich `modified` nicht geaendert hat. |

**Was passiert:**
1. Dokumente mit Inbox-Tag aus Paperless holen
2. Phase 1: OCR-Korrektur (wenn `OCR_MODE != off`)
3. Phase 2: Embedding berechnen + Kontext-Dokumente finden
4. Phase 3: Klassifikation via LLM, Vorschlaege speichern

**Wann nutzen:** Zum Testen der Pipeline oder wenn man nicht auf den
naechsten automatischen Poll warten will.

---

### `process-doc` — Einzelnes Dokument verarbeiten

Fuehrt die komplette Pipeline fuer genau ein Dokument aus (OCR-Korrektur,
Embedding, Klassifikation, Vorschlag speichern / Auto-Commit nach Konfiguration).

```bash
# Ein Dokument verarbeiten
archibot process-doc 224

# Dokument erneut verarbeiten (Idempotency-Skip ignorieren)
archibot process-doc 224 --force
```

**Flags:**
| Flag | Beschreibung |
|------|-------------|
| `--force` | Loescht den bestehenden Eintrag in `processed_documents` fuer diese Dokument-ID und erzwingt dadurch eine Neuverarbeitung. |

**Wann nutzen:** Ideal fuer Debugging einzelner Faelle (z. B. fehlerhafte
Klassifikation oder Ollama-Probleme), ohne die gesamte Inbox zu starten.

---

### `jobs` — Persistente Worker-Jobs beobachten

```bash
archibot jobs list
archibot jobs status <job_id>
archibot jobs stop <job_id>
archibot jobs retry <job_id>
```

Diese Befehle lesen bzw. aktualisieren die Laravel-Worker-Job-Datenbank. `stop`
setzt laufende Jobs auf `cancelling` bzw. noch nicht gestartete Jobs direkt auf
`cancelled`; `retry` legt einen neuen `queued` Job mit derselben Payload an.

---

### Worker-Job Lifecycle

Die Admin-UI unter `/worker-jobs` kann folgende persistente Jobs starten:

- `poll`
- `process_document` mit Paperless-Dokument-ID
- `reindex`
- `reindex_ocr`
- `reindex_embed`

Statuswerte sind `queued`, `running`, `cancelling` (UI: „wird abgebrochen“),
`cancelled`, `succeeded`, `failed` und `partially_failed`. Stop ist kooperativ:
bei laufenden Jobs wird zuerst `cancelling` gespeichert und der Python-Prozess
per Interrupt aufgefordert, an vorhandenen Checkpoints sauber abzubrechen. Retry
legt einen neuen Job mit gleicher Payload an; wenn persistierte Dokumentfehler
vorhanden sind, wird die Retry-Payload auf diese Paperless-IDs eingeschraenkt.

---

### `reset` — Container zuruecksetzen

Setzt die ArchiBot-Laufzeitdaten ueber den kanonischen Laravel/PostgreSQL-Pfad zurueck. Der Python-CLI-Einstieg `archibot reset` bleibt fuer Operatoren erhalten, delegiert im Hintergrund aber an `php artisan archibot:reset` und entfernt alte Python-SQLite-Dateien nur noch als Legacy-Cleanup nach erfolgreichem PostgreSQL-Reset.

```bash
# PostgreSQL/Laravel-Laufzeitdaten zuruecksetzen (Config behalten)
archibot reset --yes

# PostgreSQL/Laravel-Laufzeitdaten + Config/Setup-State zuruecksetzen
archibot reset --yes --include-config
```

**Flags:**
| Flag | Beschreibung |
|------|-------------|
| `--yes` | **Pflicht.** Bestaetigt den Reset (keine interaktive Abfrage). |
| `--include-config` | Loescht zusaetzlich Laravel-Config/Setup-State sowie Legacy-`config.env` und Backups. Verbindungseinstellungen (Paperless-URL, Token, Ollama-URL) gehen dabei verloren. |

**Was passiert:**
1. `archibot reset` ruft im Hintergrund `php artisan archibot:reset --yes` auf
2. Laravel leert die PostgreSQL/Laravel-Laufzeittabellen, inklusive Job-, Pipeline-, Embedding-, Audit-, Chat-, Session- und Cache-State
3. Optional: Laravel-Config/Setup-State sowie Legacy-`config.env` und `config.bak.*` Backups werden geloescht
4. Alte `classifier.db`, `-wal` und `-shm` Dateien werden nur als Legacy-Cleanup entfernt

**Was NICHT geloescht wird:**
- `.env` (Docker-Compose Umgebungsvariablen)
- Prompt-Dateien in `prompts/`
- Daten in Paperless-NGX selbst (Dokumente, Tags, etc.)

**Wann nutzen:** Bei einem Neustart von Grund auf, nach schwerwiegenden
Datenbank-Problemen, oder beim Wechsel der gesamten Klassifikationsstrategie.

## Hilfe

```bash
archibot --help
```

Zeigt alle verfuegbaren Befehle mit Kurzbeschreibung an.
