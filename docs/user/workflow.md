# Review-Workflow

So funktioniert die Klassifikation von der Dokumenterfassung bis zum Commit
in Paperless-NGX.

## Ablauf

```
1. Dokument hochladen          Paperless-NGX vergibt Tag "Posteingang"
         |
2. Worker erkennt Dokument      Naechster Poll oder Webhook-Trigger
         |
3. OCR-Korrektur (optional)     Nur wenn OCR_MODE != off
         |
4. Embedding berechnen          qwen3-embedding:4b (Default), gespeichert in pgvector
         |
5. Kontext-Suche                KNN: aehnlichste bereits klassifizierte Dokumente finden
         |
6. Klassifikation               LLM bekommt Zieldokument + Kontext, liefert JSON
         |
6b. Judge-Pass (optional)       Zweiter LLM-Pass prueft/korrigiert bei niedriger
                                Confidence + vorhandenem Kontext
         |
7. Vorschlag speichern          Status: "pending" in der suggestions-Tabelle
         |
   ┌─────┴──────┐
   |             |
8a. Auto-Commit               Wenn confidence >= AUTO_COMMIT_CONFIDENCE
8b. Manuelles Review          GUI (/review)
         |
9. PATCH nach Paperless         Metadaten-Update (Titel, Datum, Korrespondent, ...)

Beim Inbox-Poll werden OCR und Embeddings weiterhin batchweise ausgefuehrt. Ab der
Klassifikation veroeffentlicht ArchiBot Ergebnisse aber so frueh wie moeglich pro
Dokument: Sobald ein Dokument klassifiziert ist und kein separates Judge-Modell
fuer dieses Dokument geladen werden muss, wird der Vorschlag direkt gespeichert
und entweder als Review sichtbar oder automatisch committet.
```

## Schritt fuer Schritt

### 1. Dokument wird erkannt

Die Pipeline kann die Paperless-Inbox periodisch reconciliieren, wenn `POLL_INTERVAL_SECONDS` groesser als `0` ist. Default ist `0`, also kein automatisches Polling; manuelle Verarbeitung startet in der Laravel Maintenance-Oberflaeche und ist in `/operations-log` nachvollziehbar.
Alternativ kann ein [Webhook](./webhooks.md) sofortige Verarbeitung ausloesen. Scheduler-, Webhook- und UI-Starts erscheinen gemeinsam in der Worker-Job-Historie mit Status, Fortschritt und Logs.

Nur Dokumente mit dem Inbox-Tag (`PAPERLESS_INBOX_TAG_ID`) werden verarbeitet.
Bereits verarbeitete Dokumente (gleicher `updated_at`-Timestamp) werden uebersprungen. Ein laufender `process_document` Job sperrt seine Paperless-Dokument-ID, damit kein anderer aktiver Job dieselbe ID parallel verarbeitet. Reindex-Jobs sind exklusiv; andere Jobs warten, bis der Reindex abgeschlossen oder abgebrochen ist.

### 2. Kontext-basierte Klassifikation

Der Classifier sucht per Embedding-Similarity die aehnlichsten bereits
klassifizierten Dokumente. Diese dienen als Few-Shot-Kontext:

- **Nur reviewte Dokumente** werden als Kontext genutzt — nie Inbox-Dokumente
- Kontext-Dokumente enthalten ihre **vollstaendige Klassifikation** (Korrespondent,
  Dokumenttyp, Speicherpfad, Tags, Datum)
- Das LLM nutzt diese als starke Hinweise fuer die eigene Entscheidung
- Anzahl der Kontext-Dokumente: `CONTEXT_MAX_DOCS` (Default: 5)

### 3. LLM-Vorschlag

Das LLM liefert strukturiertes JSON mit:
- **Titel** — bereinigter, aussagekraeftiger Titel
- **Datum** — erkanntes Dokumentdatum
- **Korrespondent** — Absender/Aussteller
- **Dokumenttyp** — Rechnung, Vertrag, Brief, etc.
- **Speicherpfad** — Ordner in Paperless
- **Tags** — passende Schlagworte
- **Confidence** — Vertrauenswert (0–100)
- **Reasoning** — Begruendung der Entscheidung

### 4. Review

#### In der GUI (`/review`)

- Alle offenen Vorschlaege in einer Queue
- Pro Vorschlag: Original vs. Vorschlag nebeneinander
- Felder einzeln editieren oder uebernehmen
- Annehmen oder Ablehnen mit einem Klick
- Nicht-Admins sehen Vorschlaege nur, wenn ihr gespeicherter Paperless-Token Zugriff auf das konkrete Paperless-Dokument nachweist
- Nicht-Admins duerfen Vorschlaege nur bearbeiten, annehmen oder ablehnen, wenn ihr gespeicherter Paperless-Token fuer das konkrete Dokument Aenderungsrechte nachweist

#### Auto-Commit

Wenn `AUTO_COMMIT_CONFIDENCE > 0` und der finale Confidence-Score darueber liegt,
wird der Vorschlag automatisch committet — ohne manuelles Review. Im Inbox-Poll
bleiben die Modellphasen strikt gebuendelt, damit OCR-, Embedding-,
Klassifikations- und Judge-Modelle nicht pro Dokument hin- und hergeladen
werden muessen:

1. OCR fuer alle Dokumente, Ergebnisse pro Dokument speichern
2. Embeddings/Kontextsuche fuer alle Dokumente, Ergebnisse pro Dokument merken
3. Klassifikation fuer alle Dokumente
4. Judge-Verifikation fuer alle erfolgreichen Klassifikationen
5. Vorschlaege speichern, Review/Auto-Commit ausfuehren
6. Embeddings final pro Dokument in den Kontextindex schreiben

Innerhalb jeder Phase wird nach jedem Dokument persistiert. Ein Absturz in
Dokument 12/19 verliert also nicht die Ergebnisse der ersten 11 Dokumente.
Die Worker-Job-Anzeige zeigt den aktuellen Phasenfortschritt, z. B.
`Embedding 4/19` oder `Judge 2/7`.

#### Judge-Verifikation (optional)

Mit `ENABLE_JUDGE_VERIFICATION=true` laeuft nach der Erst-Klassifikation ein
zweiter LLM-Pass, der den Vorschlag prueft. Nur aktiv, wenn die Erst-Confidence
unterhalb von `JUDGE_CONFIDENCE_THRESHOLD` (Default 85) liegt und Kontext-Docs
vorhanden sind. Verdikte: `agree`, `corrected`, `skipped`, `error`. Bei
`corrected` ersetzt der Judge die Erst-Klassifikation; der Erst-Vorschlag bleibt
als Snapshot im Review-Detail und in der DB als `original_proposed_json`.
Standardmaessig nutzt der Judge dasselbe Modell (`OLLAMA_MODEL`) — kein
zusaetzlicher GPU-Swap zwischen Klassifikation und Judge. Wenn ein eigenes
`OLLAMA_JUDGE_MODEL` gesetzt ist, laeuft es als eigene Batch-Phase nach der
Klassifikation. Dokumente, bei denen der Judge wegen hoher Confidence oder
deaktivierter Verifikation uebersprungen wird, werden in dieser Phase als
`skipped` gezaehlt und danach gespeichert/veroeffentlicht. Stats-Seite zeigt
eine eigene "Judge Verification"-Dauer-Kachel und ein Verdict-Breakdown-Panel.

### 5. Commit nach Paperless

Nach Freigabe werden die Metadaten via PATCH an Paperless geschrieben:
- Titel, Datum, Korrespondent, Dokumenttyp, Speicherpfad werden aktualisiert
- **Tags:** Nur Tags mit bekannter Paperless-ID werden geschrieben. Neue Tags
  landen in der Tag-Whitelist (`/tags`) und muessen erst freigegeben werden.
- **Inbox-Tag:** Bleibt standardmaessig erhalten (`KEEP_INBOX_TAG=true`).
  Mit `KEEP_INBOX_TAG=false` wird er beim Commit entfernt.
- **Processed-Tag:** Optional wird `PAPERLESS_PROCESSED_TAG_ID` hinzugefuegt.

## Tag-Management

### Whitelist

Neue Tags, die das LLM vorschlaegt und die noch nicht in Paperless existieren,
landen in der Tag-Whitelist mit Status `pending`. Auf der Seite `/tags` kannst du:

- **Freigeben** — Tag wird in Paperless angelegt, retroaktiv auf bereits committete
  Dokumente angewendet (PATCH), und in offenen Vorschlaegen voraufgeloest
- **Ablehnen** — Tag wandert in die Blacklist

### Blacklist

Abgelehnte Tags werden dauerhaft ignoriert. Das LLM kann sie weiterhin vorschlagen,
aber sie werden automatisch aus dem Vorschlag gefiltert. Tags koennen ueber `/tags`
wieder von der Blacklist entfernt werden.
