# Review-Workflow

So funktioniert die Klassifikation von der Dokumenterfassung bis zum Commit
in Paperless-NGX.

> **Chat/RAG ist deaktiviert:** Weder UI noch Web/API, Python-CLI oder MCP bieten einen Chat- oder globalen Retrieval-Pfad. Vorhandene Chat-Daten bleiben gespeichert, werden aber nicht angezeigt. [Issue #221](https://github.com/pfriedrich84/archibot/issues/221) ist der einzige Redesign-/Re-enable-Track.

## Ablauf

```
1. Dokument hochladen          Paperless-NGX vergibt Tag "Posteingang"
         |
2. Worker erkennt Dokument      Naechster Poll oder Webhook-Trigger
         |
3. Embedding berechnen          Fuer die gesamte Zielmenge, gespeichert in pgvector
         |
4. OCR-Korrektur (optional)     Danach fuer alle berechtigten Zieldokumente
         |
5. Kontext-Suche                KNN: aehnlichste bereits klassifizierte Dokumente finden
         |
6. Klassifikation               LLM bekommt Zieldokument + Kontext, liefert JSON
         |
6b. Judge-Pass (optional)       Zweiter LLM-Pass prueft/korrigiert bei niedriger
                                Confidence + vorhandenem Kontext
         |
7. Vorschlag speichern          Status: "pending" in PostgreSQL review_suggestions
         |
8. Manuelles Review             Autorisierte Annahme in der GUI (/review)
         |
9. PATCH nach Paperless         Erst ueber den geprueften Review-Commit-Pfad

Beim Inbox-Poll verarbeitet ArchiBot die gesamte neu erstellte Zielmenge als einen
durable Batch. Alle Embeddings sind abgeschlossen, bevor OCR beginnt; alle
OCR-Schritte sind abgeschlossen, bevor die Klassifikation beginnt; und kein
Dokument startet den Judge, bevor die Klassifikation der gesamten Zielmenge
abgeschlossen ist. Review-Vorschlaege werden erst nach der globalen Judge-Phase
sichtbar. Ein Paperless-Write erfolgt weiterhin erst nach manueller Annahme.
```

## Schritt fuer Schritt

### 1. Dokument wird erkannt

Paperless [Webhooks](./webhooks.md) sind der primaere Trigger. Wenn `POLL_INTERVAL_SECONDS` groesser als `0` ist, reconciliert die Pipeline die Inbox automatisch; Default sind `600` Sekunden. Polling repariert verpasste Events, und manuelle Verarbeitung startet in der Laravel Maintenance-Oberflaeche.
Webhook-, Reconciliation- und UI-Starts erscheinen gemeinsam in `/operations-log` als durable Commands, Pipeline Runs, Events und Actor Executions mit Status, Fortschritt und Logs.

Nur Dokumente mit dem Inbox-Tag (`PAPERLESS_INBOX_TAG_ID`) sind Poll-Kandidaten. Sobald ArchiBot nach erfolgreicher Klassifikation einen Review-Vorschlag gespeichert hat, dient dieser als dauerhafter Klassifikationsmarker. Weitere automatische Polls ueberspringen das Inbox-Dokument auch dann, wenn ein Review oder Commit den Paperless-`modified`-Zeitstempel geaendert hat und `KEEP_INBOX_TAG=true` ist. Ein abgelehnter Vorschlag bleibt ebenfalls markiert; fuer eine gewollte neue Klassifikation stehen der explizite Force-Poll und das manuelle Force-Reprocess zur Verfuegung. Parallel eintreffende Webhooks und Polls werden zusaetzlich ueber den gemeinsamen Pipeline-Dedupe-Key zusammengefuehrt.

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

Die Navigation priorisiert den taeglichen Dokumentfluss: **Today** zeigt die
naechste Review-Aufgabe, **Review queue** fuehrt das Register der Vorschlaege und
**Inbox** zeigt die eingegangenen Paperless-Dokumente. Monitoring-, Recovery- und
Konfigurationsseiten bleiben fuer Admins unter **Admin tools** erreichbar, ohne
den normalen Reviewpfad zu ueberladen.

- Alle offenen Vorschlaege stehen in einer durchsuchbaren Queue; selten benoetigte Filter bleiben unter „More filters and sorting“ eingeklappt.
- Die Detailansicht haelt die Dokumentvorschau neben den vorgeschlagenen Aenderungen sichtbar.
- Geaenderte Werte werden hervorgehoben; unveraenderter Kontext tritt visuell zurueck.
- Felder lassen sich unter „Edit proposed metadata“ einzeln bearbeiten und speichern.
- Annehmen reiht den geprueften Paperless-Metadaten-Write ein; Ablehnen veraendert Paperless nicht. Danach oeffnet ArchiBot direkt das naechste sichtbare Review oder kehrt bei leerer Queue zum Register zurueck.
- Modell- und Judge-Begruendungen bleiben als einklappbare Entscheidungs-Evidenz verfuegbar und autorisieren nie selbst einen Write.
- Nicht-Admins sehen Vorschlaege nur, wenn ihr gespeicherter Paperless-Token Zugriff auf das konkrete Paperless-Dokument nachweist.
- Nicht-Admins duerfen Vorschlaege nur bearbeiten, annehmen oder ablehnen, wenn ihr gespeicherter Paperless-Token fuer das konkrete Dokument Aenderungsrechte nachweist.

#### OCR-Review (`/ocr-reviews`)

OCR-Reviews speichern Original-, Korrektur- und gegebenenfalls freigegebene Text-Snapshots ausschließlich lokal in ArchiBot. Eine Freigabe oder Ablehnung ist eine lokale Entscheidung; sie schreibt keinen Dokumentinhalt nach Paperless und bietet weder Restore noch Retry eines früheren Write-backs. Vor Listen- und Detailanzeige prüft ArchiBot die aktuelle Paperless-Sichtberechtigung, vor Erstellen, Freigeben oder Ablehnen die aktuelle Änderungsberechtigung. Das gilt auch für ArchiBot-Admins; bei Paperless- oder Authentifizierungsfehlern wird der Zugriff verweigert. Bestehende historische OCR-Zeilen und Snapshots bleiben erhalten.

[Issue #222](https://github.com/pfriedrich84/archibot/issues/222) und die [Paperless-v3-Kompatibilitaetsforschung](../architecture/paperless-v3-ocr-compatibility-design.md) untersuchen API-/OCR-Optionen. Der Entwurf ist keine Integration und darf diese lokale-only Grenze nicht umgehen.

#### Confidence Auto-Commit (deaktiviert)

ADR-0018 setzt den effektiven Schwellenwert fest auf `0`. Alte Werte aus Environment,
importierter Runtime-Konfiguration oder PostgreSQL werden ignoriert. Auch eine Modell-
oder Judge-Confidence von `100` bleibt lediglich Review-Evidenz und kann weder eine
Annahme noch einen Paperless-Write ausloesen. Eine spaetere sichere Automation braucht
deterministische Eligibility-Gates sowie ausdrueckliche Produkt-/Security-Freigabe.

Im Inbox-Poll bleiben die Modellphasen strikt gebuendelt, damit OCR-, Embedding-,
Klassifikations- und Judge-Modelle nicht pro Dokument hin- und hergeladen werden muessen:

1. Embeddings fuer alle Dokumente erzeugen und mit korrekter Kontext-Trust-Markierung speichern
2. OCR fuer alle berechtigten Dokumente ausfuehren und Korrekturen lokal speichern
3. Kontextsuche und Klassifikation fuer alle Dokumente ausfuehren
4. Judge-Verifikation fuer alle erfolgreichen Klassifikationen ausfuehren oder gemaess Einstellung ueberspringen
5. Vorschlaege erst danach als pending Review speichern

Pipeline Items und `pipeline.batch.phase.completed`-Events dokumentieren jede
Phasengrenze. Ein erforderlicher Klassifikationsfehler stoppt den Batch vor dem
Judge und fuehrt gemaess Retry-Policy zu einem erneuten Batch-Versuch. Die
durable Pipeline-/Actor-Statusanzeige zeigt den aktuellen Phasenfortschritt, z. B.
`embedding`, `ocr`, `classification` oder `judge`.

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
- Titel, Datum, Korrespondent und Dokumenttyp werden aus der manuellen Freigabe aktualisiert.
- Ein Speicherpfad wird nur ueber diese manuelle Review-Naht gesetzt, wenn Paperless unmittelbar vor dem PATCH live `null` meldet; ein vorhandener Speicherpfad bleibt unveraenderlich.
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
