# CLI Commands

Der Classifier stellt CLI-Befehle bereit, die im laufenden Container oder lokal
ausgefuehrt werden koennen. Sie sind nuetzlich fuer Wartung, Debugging und
manuelles Ausloesen der Pipeline-Phasen.

CLI-Aktionen, die sich mit der Laravel-GUI ueberschneiden, delegieren an denselben durable Laravel-Backendpfad wie Maintenance: `archibot poll`, `reindex`, `reindex-ocr`, `reindex-embed` und `process-doc` rufen intern `php artisan archibot:maintenance-command ...` auf. Dadurch entstehen dieselben durable `commands`/`pipeline_runs`, Laravel-Queue-Jobs und fixen Python-Actor-Kommandos wie bei GUI-Buttons. `worker_jobs` ist als Clean-Install-Break entfernt; es gibt keine Legacy-Datenmigration oder Backend-Kompatibilitaet fuer alte Worker-Zeilen.

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
1. Die CLI delegiert an `php artisan archibot:maintenance-command reindex`
2. Laravel erzeugt einen durable `reindex` Command, markiert die Embedding-Gate-State als stale und queued `RunPythonActorJob::reindex`
3. Der fixe Python-Actor laedt Optionen aus `commands.payload`, fuehrt OCR/Embedding-Reindex aus und schreibt Fortschritt in durable Pipeline-/Actor-Tabellen

**Wann nutzen:** Nach Wechsel des Embedding-Modells, bei beschaedigter Vektor-DB,
oder beim ersten Setup.

---

### `reindex-ocr` — Nur OCR-Korrektur

Fuehrt OCR-Korrektur ueber alle Paperless-Dokumente aus, ohne Embeddings
neu zu berechnen. Respektiert die `OCR_MODE`-Einstellung.

```bash
# Nur neue Dokumente (Cache wird respektiert)
archibot reindex-ocr

# Alle Dokumente neu korrigieren (Cache und Clean-Text-Heuristik ignorieren)
archibot reindex-ocr --force
```

**Flags:**
| Flag | Beschreibung |
|------|-------------|
| `--force` | OCR-Cache ignorieren und die Clean-Text-Heuristik fuer `text`/`vision_light` umgehen. Ohne dieses Flag werden bereits gecachte Korrekturen uebersprungen und sauber wirkende Texte nicht neu ans OCR-Modell gesendet. |

**Was passiert:**
- Die CLI delegiert an `php artisan archibot:maintenance-command reindex_ocr`
- Laravel erzeugt einen durable `reindex_ocr` Command und queued `RunPythonActorJob::reindexOcr`
- Der fixe Python-Actor laedt `force` aus `commands.payload`
- Ergebnisse landen in `doc_ocr_cache` (nie direkt in Paperless)
- Bereits gecachte Korrekturen werden uebersprungen (ausser mit `--force`)
- `--force` sendet auch sauber wirkende Texte erneut ans OCR-Modell; `OCR_REQUESTED_TAG_ID` gilt weiterhin

**Wann nutzen:** Nach Wechsel des OCR-Modells oder der OCR-Stufe.
Mit `--force` wenn vorhandene Korrekturen unbrauchbar sind und neu erzeugt werden sollen.

---

### `reindex-embed` — Nur Embeddings neu berechnen

Startet einen PostgreSQL/pgvector-Embedding-Build fuer vertrauenswuerdige Dokumente ohne Inbox-/Posteingang-Tag. Nutzt gecachte OCR-Texte aus `doc_ocr_cache` (falls vorhanden), fuehrt aber keine neue OCR-Korrektur durch.

```bash
archibot reindex-embed
```

**Was passiert:**
1. Die CLI delegiert an `php artisan archibot:maintenance-command reindex_embed`
2. Laravel erzeugt einen durable `embedding_index_build` Command und queued `RunPythonActorJob::embeddingIndexBuild`
3. Der fixe Python-Actor startet den PostgreSQL/pgvector-Build und speichert Embeddings in `document_embeddings`

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
1. Die CLI delegiert an `php artisan archibot:maintenance-command poll`
2. Laravel erzeugt einen durable `poll_reconciliation` Command und queued `RunPythonActorJob::pollReconciliation`
3. Der fixe Python-Actor nutzt denselben Pipeline-Start-/Dedupe-/Lock-Pfad wie Webhook- und GUI-Starts
4. Fortschritt, Events und Actor-Ausfuehrung werden in durable PostgreSQL-Tabellen sichtbar, insbesondere in `/operations-log` und `/pipeline-runs`.

**Wann nutzen:** Zum Testen der Pipeline oder wenn man nicht auf den
naechsten automatischen Poll warten will.

---

### `process-doc` — Einzelnes Dokument verarbeiten

Fuehrt die komplette Pipeline fuer genau ein Dokument aus (OCR-Korrektur,
Embedding, Klassifikation und Vorschlag speichern). Die aktuelle Runtime kann bis zur Umsetzung von ADR-0018 weiterhin Auto-Commit nach Konfiguration ausfuehren; ein veralteter effektiver Python-Export kann trotz Env/UI-Wert `0` aktiv bleiben. Es gibt keine verlaessliche reine Einstellungsminderung. `process-doc` und andere Dokumentklassifikationspfade duerfen bis Meilenstein 0.2 nicht ausgefuehrt werden.

```bash
# Ein Dokument verarbeiten
archibot process-doc 224

# Dokument erneut verarbeiten (Idempotency-Skip ignorieren)
archibot process-doc 224 --force
```

**Flags:**
| Flag | Beschreibung |
|------|-------------|
| `--force` | Erzwingt wie der Maintenance-Button einen neuen durable Pipeline Run (`manual_force`). |

**Was passiert:** Die CLI delegiert an `php artisan archibot:maintenance-command process_document --document-id=<id>`. Laravel startet denselben durable `pipeline_runs`-Pfad wie der Maintenance-Button.

**Wann nutzen:** Ideal fuer Debugging einzelner Faelle (z. B. fehlerhafte
Klassifikation oder Ollama-Probleme), ohne die gesamte Inbox zu starten.

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
| `--include-config` | Loescht zusaetzlich Laravel-Config/Setup-State sowie Legacy-`config.env` und Backups. Verbindungseinstellungen (Paperless-URL, login-derived Paperless user tokens, Ollama-URL) gehen dabei verloren. |

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
