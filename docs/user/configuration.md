# Konfiguration

Einstellungen werden ueber Docker-Compose-Umgebungsvariablen und die Laravel Settings UI verwaltet. Beim ersten Setup importiert Laravel bestehende Werte aus `.env`/`/data/config.env` einmalig in PostgreSQL; danach sind Laravel-Settings fuehrend.

> **Chat/RAG deaktiviert:** Es gibt keine Chat-Seite, Route, Provider- oder Prompt-Einstellung und keinen globalen MCP-Retrieval-Pfad. Bestehende gespeicherte Chat-Daten und alte Konfigurationswerte bleiben erhalten, werden aber nicht exponiert oder ausgefuehrt. [Issue #221](https://github.com/pfriedrich84/archibot/issues/221) ist der einzige Redesign-/Re-enable-Track; der [RAG-Entwurf](../architecture/authorization-safe-rag-design.md) ist keine Freigabe.

> Hinweis: Die mitgelieferte `.env.example` nutzt ein 6GB-VRAM-Preset
> (staerkere Embedding/OCR-Modelle). Die Tabellen unten dokumentieren die
> internen App-Defaults.

**Prioritaet (hoechste zuerst):**
1. Laravel Settings in PostgreSQL
2. OS-Umgebungsvariablen — gesetzt von Docker Compose aus `.env`
3. Legacy `{DATA_DIR}/config.env` — nur fuer den einmaligen Import beim Setup
4. Defaults — in Laravel/Python hinterlegt

Ausnahme: `PAPERLESS_URL` ist ein Deployment-Trust-Anchor und hat immer Vorrang vor PostgreSQL und Legacy-Dateien. Setup, Tag-Laden, Login, Admin-Ansicht, alle Laravel-Paperless-Clients und der Python-Runtime-Export verwenden exakt diesen normalisierten Origin. Die Setup-/Admin-UI zeigt ihn read-only; gesendete Overrides werden abgelehnt.

## Paperless-NGX

| Variable | Default | Beschreibung |
|---|---|---|
| `PAPERLESS_URL` | — | Zwingender, deployment-eigener Origin, z.B. `http://paperless:8000`. Nur `http(s)://host[:port]`, ohne Credentials, Pfad, Query oder Fragment. Aenderungen erfordern ein bewusstes Deployment-Update und Neustart. |
| `PAPERLESS_HTTP_TIMEOUT_SECONDS` | `10` | Laravel Connect-/Request-Timeout fuer Paperless-Aufrufe; Connect-Timeout ist maximal 5 Sekunden. |
| `PAPERLESS_HTTP_MAX_RESPONSE_BYTES` | `2097152` | Maximale tatsaechlich gepufferte/dekodierte Paperless-JSON-Responsegroesse fuer Laravel-Aufrufe; gilt auch ohne `Content-Length` sowie fuer komprimierte/chunked Antworten. |
| `PAPERLESS_HTTP_MAX_PREVIEW_BYTES` | `52428800` | Separates Limit fuer tatsaechlich gepufferte/dekodierte Dokumentvorschauen. Das groessere, weiterhin endliche Limit verhindert, dass regulaere PDF-Previews am kleineren JSON-Limit scheitern. |
| `SETUP_RATE_LIMIT_PER_MINUTE` | `5` | Gemeinsames Limit pro Benutzername/IP fuer Setup-Abschluss und Tag-Verifikation; auch ungueltige Versuche zaehlen. |
| `MODEL_DISCOVERY_RATE_LIMIT_PER_MINUTE` | `10` | Limit pro angemeldetem Admin fuer AI-Modell-Discovery. |
| `PAPERLESS_INBOX_TAG_ID` | — | ID des Tags `Posteingang`; in der Settings-UI per Live-Dropdown aus Paperless auswaehlbar |
| `PAPERLESS_PROCESSED_TAG_ID` | — | Optional: Tag-ID, die nach Commit gesetzt wird; in der Settings-UI per Live-Dropdown aus Paperless auswaehlbar |
| `KEEP_INBOX_TAG` | `true` | Posteingang-Tag nach Commit beibehalten |

## AI Provider / Ollama (allgemein)

ArchiBot nutzt intern eine neutrale AI-Provider-Schnittstelle. Unterstuetzt werden Ollama-kompatible Provider und OpenAI-kompatible `/v1`-APIs. Die oeffentlichen Setup- und Tag-Verifikationsfelder sind serverseitig begrenzt (Paperless-URL 2048, Benutzername 150, Passwort/Webhook-Secret 1024 und Reset-Token 255 Zeichen; Tag-IDs muessen positive 32-Bit-Integer sein).

Editierbare AI-Provider-Endpunkte und Modell-Discovery werden erst nach abgeschlossenem Setup in der authentifizierten Admin-Settings-UI angeboten; der oeffentliche Bootstrap nimmt keine frei waehlbaren Provider-Ziele an. Manuelle Modelleingabe bleibt moeglich, falls ein Provider keine vollstaendige Modellliste liefert. Legacy-Variablennamen mit `OLLAMA_*` bleiben aus Kompatibilitaetsgruenden erhalten und bedeuten nicht, dass die Verarbeitung nur eine bestimmte Ollama-Instanz unterstuetzt.

Jede ArchiBot-Installation nutzt genau einen Provider-Endpunkt fuer Klassifikation, Embeddings, OCR und Judge. Fuer diese Rollen werden unterschiedliche Modelle konfiguriert. Ein Gateway wie LiteLLM kann intern mehrere Backends bedienen, bleibt aus ArchiBot-Sicht aber ein einzelner Provider. Bei einem entfernten Provider koennen Dokumenttext und OCR-Inhalte die eigene Infrastruktur verlassen; die Wahl des Endpunkts muss daher bewusst erfolgen.

| Variable | Default | Beschreibung |
|---|---|---|
| `LLM_PROVIDER` | `ollama` | `ollama` fuer Ollama-kompatible API oder `openai_compatible` fuer OpenAI-kompatible `/v1`-API |
| `OLLAMA_URL` / `OPENAI_BASE_URL` | `http://ollama:11434` | Provider-Basis-URL. Fuer `openai_compatible` inkl. `/v1`, z.B. `http://localhost:11434/v1`. `OPENAI_BASE_URL` ist ein Alias fuer OpenAI-kompatible Setups; `OLLAMA_URL` bleibt der Legacy-Name. |
| `OPENAI_API_KEY` | — | Optionaler Bearer Token fuer OpenAI-kompatible Provider; leer lassen bei lokalen Endpunkten ohne Auth |
| `OLLAMA_TIMEOUT_SECONDS` | `600` | HTTP-Timeout fuer AI-Provider-Requests (Sekunden) |
| `OLLAMA_CHAT_RETRIES` | `2` | Historisch benannter Wert fuer maximale Retries strukturierter OCR-/Klassifikationsaufrufe bei transienten Fehlern (429/5xx/Timeouts); aktiviert keinen Chat. |
| `OLLAMA_CHAT_RETRY_BASE_DELAY` | `1.0` | Historisch benannter Basis-Delay fuer den exponentiellen Backoff strukturierter OCR-/Klassifikationsaufrufe; aktiviert keinen Chat. |
| `OLLAMA_MODEL_SWAP_DELAY` / `OLLAMA_MODEL_SWAP_DELAY_SECONDS` | `8.0` | Wartezeit nach Model-Unload, damit Ollama-kompatible Runtimes freie VRAM korrekt erkennen; nur bei Providern genutzt, die Model-Unload unterstuetzen. `_SECONDS` ist ein Legacy-Alias. |

Die Admin-Settings laden die Modellliste einmal von diesem Endpunkt. Klassifikation, Embedding, OCR Text, OCR Vision und Judge besitzen danach jeweils ein eigenes Modellfeld. Falls Discovery keine vollstaendige Liste liefert, kann weiterhin eine Modell-ID manuell eingegeben und rollenspezifisch validiert werden.

## Phase 1: OCR-Korrektur

| Variable | Default | Beschreibung |
|---|---|---|
| `OCR_MODE` | `off` | OCR-Stufe: `off`, `text`, `vision_light`, `vision_full` |
| `OCR_REQUESTED_TAG_ID` | `0` | Optionaler Paperless-Tag-Filter fuer OCR. `0`, leer oder nicht gesetzt = OCR fuer alle Dokumente. In der Settings-UI kann eine leere Auswahl den Env-Wert deaktivieren. Wenn der Tag spaeter in Paperless geloescht wird, wird OCR uebersprungen und ein Fehler im Dashboard angezeigt. Webhooks ohne Tag-IDs loesen keinen Zusatz-Lookup fuer OCR aus und ueberspringen OCR. |
| `OCR_TEXT_MODEL` / `OCR_MODEL` / `OLLAMA_OCR_MODEL` | `qwen3:4b` | Modell fuer Text-Only OCR-Korrektur. `OCR_TEXT_MODEL` ist der bevorzugte Name; `OCR_MODEL` und `OLLAMA_OCR_MODEL` bleiben Aliase. |
| `OCR_VISION_MODEL` | `qwen3-vl:4b` | Vision-Modell fuer OCR (muss vision-faehig sein) |
| `OCR_VISION_MAX_PAGES` | `3` | Max. Seiten fuer Vision-OCR |
| `OCR_VISION_DPI` | `150` | Render-Aufloesung fuer PDF-Seiten (Pixel pro Zoll) |
| `OLLAMA_OCR_NUM_CTX` | `12288` | Kontextfenster fuer OCR-Modelle (Tokens). Vision braucht ~1536 Tokens/Seite. |

### OCR-Modi im Vergleich

| Modus | Beschreibung | Heuristik? | Kosten |
|-------|-------------|------------|--------|
| `off` | Keine OCR-Korrektur (Default) | — | Keine |
| `text` | Text-only LLM-Korrektur | Ja | 1 LLM-Call |
| `vision_light` | Bild + OCR-Text vergleichen | Ja | 1 Download + N Vision-Calls |
| `vision_full` | Seite-fuer-Seite Korrektur | Nein (laeuft immer) | 1 Download + N Vision-Calls |

`reindex-ocr --force` bzw. der Force-Button ignoriert den OCR-Cache und umgeht die Clean-Text-Heuristik fuer `text` und `vision_light`, damit OCR nach Modell- oder Promptwechseln wirklich neu erzeugt wird.

OCR-Korrekturen und lokale Freigaben bleiben ausschließlich in ArchiBot. Es gibt keine Auto-write-, Write-back-, Restore- oder Retry-Einstellung für Paperless-Dokumentinhalt. Ein eventuell noch gespeicherter PostgreSQL-Wert `ocr.auto_write_back` wird nicht im Settings-Katalog angeboten, nicht exportiert und nicht ausgeführt; ein veralteter `OCR_AUTO_WRITE_BACK`-Eintrag wird beim nächsten verwalteten Runtime-Export entfernt.

Wenn `OCR_REQUESTED_TAG_ID` gesetzt ist, laeuft bzw. wiederholt sich OCR nur fuer Dokumente, die diesen Tag aktuell tragen. Die restliche Pipeline (Embedding/Klassifikation) laeuft bei nicht passenden Dokumenten weiter.

**Graceful Degradation:** `vision_full` → `vision_light` → `text` → `off`.
Jede Stufe faengt Fehler ab und faellt auf die naechst niedrigere zurueck.

## Phase 2: Embedding

| Variable | Default | Beschreibung |
|---|---|---|
| `ARCHIBOT_EMBEDDING_MODEL` / `EMBEDDING_MODEL` / `OLLAMA_EMBED_MODEL` | `qwen3-embedding:4b` | Embedding-Modell oder Provider-Alias (hoehere Retrieval-Qualitaet). `EMBEDDING_MODEL` ist der bevorzugte Compose-/Env-Name; `OLLAMA_EMBED_MODEL` bleibt als Legacy-Name unterstuetzt. |
| `EMBEDDING_DIMENSION` / `OLLAMA_EMBED_DIM` | `0` | Embedding-Dimension fuer pgvector. `0` = Auto (`qwen3-embedding:0.6b`→1024, `qwen3-embedding:4b`→2560). |
| `EMBEDDING_CONTEXT_WINDOW` / `OLLAMA_EMBED_NUM_CTX` | `8192` | Kontextfenster fuer das Embedding-Modell (Tokens) |
| `EMBED_MAX_CHARS` / `EMBEDDING_MAX_CHARS` | `6000` | Max. Zeichen des Dokumenttexts fuer Embedding |
| `EMBEDDING_DOCUMENT_TIMEOUT_SECONDS` | `180` | Timeout pro Dokument-Embedding-Anfrage. |
| `OLLAMA_EMBED_RETRIES` | `3` | Max. Retries bei Embedding-Fehlern (Truncation + transiente 500er) |
| `OLLAMA_EMBED_RETRY_BASE_DELAY` | `1.0` | Basis-Delay in Sekunden fuer exponentiellen Backoff |

Bei `LLM_PROVIDER=openai_compatible` sendet ArchiBot Embeddings an `/v1/embeddings` mit OpenAI-kompatiblem Payload und setzt explizit `encoding_format: "float"`. `encoding_format: null` darf nicht gesendet werden. Der Modellname bleibt ein konfigurierbarer Provider-Alias, z.B. `qwen3-embedding-4b-local`; fuer Qwen3-Embedding 4B erkennt ArchiBot automatisch die Dimension `2560`, wenn `OLLAMA_EMBED_DIM=0` gesetzt ist.

Beispiel fuer Embeddings hinter einem lokalen OpenAI-kompatiblen Endpoint:

```env
LLM_PROVIDER=openai_compatible
OPENAI_BASE_URL=http://localhost:11434/v1
OPENAI_API_KEY=<optional-local-token>
ARCHIBOT_EMBEDDING_MODEL=qwen3-embedding-4b-local
OLLAMA_EMBED_MODEL=qwen3-embedding-4b-local
```

## Phase 3: Klassifikation

> **Security-Hinweis:** Confidence-basiertes Auto-Commit ist gemaess ADR-0018 deaktiviert. Laravel exportiert den effektiven Wert `0`, Python erzwingt `0`, und alte Environment-, Import- oder PostgreSQL-Werte koennen weder Annahme noch Paperless-Write ausloesen. Das Admin-Feld ist deshalb read-only. Der [Safe-Automation-Entwurf](../architecture/safe-automation-design.md) beschreibt nur Forschungs- und Freigabe-Gates und aktiviert diese Einstellung nicht.

| Variable | Default | Beschreibung |
|---|---|---|
| `CLASSIFICATION_MODEL` / `OLLAMA_MODEL` | `gemma4:e4b` | Klassifikations-Modell (6GB-Empfehlung; Alternativen: `qwen3:4b`). `CLASSIFICATION_MODEL` ist der bevorzugte Name. |
| `CLASSIFICATION_CONTEXT_WINDOW` / `OLLAMA_NUM_CTX` | `16384` | Kontextfenster fuer Klassifikation und Judge (Tokens). `CHAT_CONTEXT_WINDOW` ist keine Runtime-Setting; Chat/RAG ist deaktiviert. `JUDGE_CONTEXT_WINDOW` ist derzeit ebenfalls keine Runtime-Setting. |
| `MAX_DOC_CHARS` | `24000` | Max. Zeichen des Dokumenttexts im LLM-Prompt |
| `CONTEXT_MAX_DOCS` | `5` | Wieviele aehnliche Dokumente als Few-Shot-Kontext |
| `AUTO_COMMIT_CONFIDENCE` | `0` (fest) | Legacy-Kompatibilitaetsname ohne schreibwirksame Konfiguration. Jeder gelieferte Wert wird auf `0` normalisiert; Modell-/Judge-Confidence bleibt nur Review-Evidenz. |
| `ENABLE_JUDGE_VERIFICATION` | `false` | Zweiter LLM-Pass, der Klassifikationen prueft und ggf. korrigiert. |
| `JUDGE_CONFIDENCE_THRESHOLD` | `101` | Judge-Pass wird uebersprungen, wenn die Initial-Confidence bereits >= diesem Wert (0–100) ist. `101` bedeutet: jede Klassifikation pruefen, auch ohne Kontext-Dokumente. |
| `JUDGE_MODEL` / `OLLAMA_JUDGE_MODEL` | — | Optionales Modell fuer den Judge-Pass. Leer = `CLASSIFICATION_MODEL`/`OLLAMA_MODEL` wiederverwenden (kein zusaetzlicher GPU-Swap). Wenn ein anderes Modell gesetzt ist, werden nur Dokumente, die wirklich Judge brauchen, bis zur Batch-Judge-Phase zurueckgestellt. |

## Worker

| Variable | Default | Beschreibung |
|---|---|---|
| `POLL_INTERVAL_SECONDS` | `600` | Sekunden zwischen automatischen Inbox-Reconciliation-Laeufen; Webhooks bleiben der primaere Trigger (`0` deaktiviert die Reconciliation). Der Laravel Scheduler prueft jede Minute, ob der konfigurierte Abstand erreicht ist. Bereits klassifizierte Inbox-Dokumente werden anhand ihres dauerhaften Review-Vorschlags uebersprungen. |
| `ARCHIBOT_RECOVERY_INTERVAL_SECONDS` | `30` | Sekunden zwischen Laravel-native Recovery-Scans fuer durable Commands, Runs, Webhooks und Actor Executions. |
| `ARCHIBOT_STALE_QUEUED_MINUTES` | `5` | Ab wann queued Arbeit ohne aktiven Actor sicher erneut dispatcht werden darf. |
| `ARCHIBOT_STALE_RUNNING_MINUTES` | `10` | Ab wann ein Actor ohne aktuellen Fortschritt als stale gilt und ueber seine durable Quelle recovered wird. |

## Laravel/Svelte GUI

| Variable | Default | Beschreibung |
|---|---|---|
| `GUI_PORT` | `8088` | Port der Laravel/Svelte-Web-GUI; Runtime-/Container-Setting, muss in `.env` gepflegt werden |
| `APP_TIMEZONE` / GUI `Timezone` | `Europe/Vienna` | Zeitzone fuer angezeigte Zeitstempel. In der Laravel-GUI als `gui.timezone` pflegbar. |
| `GUI_DATE_FORMAT` / GUI `Date/time format` | `dd.mm.yyyy hh:mm:ss` | Anzeigeformat fuer Zeitstempel. Unterstuetzte Tokens: `dd`, `mm`, `yyyy`, `hh`, `MM`, `ss`; im Default-Kontext wird `mm` vor/nach `:` als Minuten interpretiert. |
| `GUI_BASE_URL` | — | Externe ArchiBot-URL fuer Links ausserhalb der Weboberflaeche; kann in `/admin/settings` gepflegt werden |
| `GUI_DATE_FORMAT` | `%d.%m.%Y` | Datumsformat fuer benutzerseitige Anzeigen und Python-CLI-Ausgaben; muss in `.env` gepflegt werden |
| `APP_TIMEZONE` | `Europe/Vienna` | Zeitzone fuer Laravel/PHP-Datumswerte sowie Python-Worker/CLI-Anzeigen; muss in `.env` gepflegt werden |
| `APP_URL` | — | Externe URL der ArchiBot-Instanz (z.B. `https://archibot.example`) |
| `APP_KEY` | auto-generiert in `/data/laravel/app_key` | Laravel-App-Key fuer Sessions und verschluesselte Secrets |
| `DB_DATABASE` | `archibot` | Laravel-App-Datenbankname |
| `QUEUE_CONNECTION` | `database` | Erforderliches Laravel Queue Backend; andere Backends werden beim Start abgelehnt. |
| `QUEUE_WORKER_TIMEOUT` | `21600` | Maximale Laufzeit eines Laravel Actor-Jobs in Sekunden. |
| `DB_QUEUE_RETRY_AFTER` | `21720` | Queue-Lease in Sekunden; muss groesser als das sechsstuendige Actor-Timeout bleiben. |
| `APP_PATH_PREFIX` | — | Optionaler Pfadpraefix; leer bedeutet GUI direkt unter `/`, z.B. `archibot` stellt die Oberflaeche unter `/archibot` bereit. Interne Navigation, Setup-/Settings-Aktionen, Vorschauen und API-Aufrufe verwenden den Praefix automatisch. Nach einer Aenderung ist ein Container-Neustart erforderlich. |

Die GUI zeigt Paperless-Labels/Namen statt roher numerischer IDs an (z.B. `Posteingang` statt `124`). IDs bleiben nur interne technische Referenzen.

Paginierten Listen fuer Reviews, OCR, Pipeline Runs, Webhooks und Fehler bieten eine gemeinsame Seitennavigation und Seitengroesse. Filter und Sortierung bleiben beim Seitenwechsel erhalten. Globale, barrierearm ausgezeichnete Statusmeldungen bestaetigen erfolgreiche oder fehlgeschlagene Aktionen. Bereits laufende Formulare deaktivieren ihre Schaltflaeche gegen Doppelklicks; Sammel- und destruktive Aktionen nennen Anzahl und Auswirkung in einer Bestaetigung.

Nach dem ersten Setup fuehrt ArchiBot den neuen Administrator zur Sektion **AI Provider**. Dort werden der eine installationsweite Endpunkt und alle Modellrollen gemeinsam konfiguriert. **Test connection and load models** laedt Modellvorschlaege; freie Modell-IDs bleiben moeglich. **Validate configured models** fuehrt fuer jedes ausgefuellte Modellfeld einen kleinen rollenspezifischen Provider-Aufruf aus. Discovery- und Validierungsfehler werden getrennt angezeigt. Anschliessend muessen die Settings gespeichert werden.

Beim Upgrade werden die frueheren Multi-Provider-Variablen `AI_PROVIDER_PROFILES`, `CLASSIFICATION_PROVIDER`, `EMBEDDING_PROVIDER`, `OCR_PROVIDER` und `JUDGE_PROVIDER` nicht mehr verwendet und beim naechsten verwalteten Runtime-Export entfernt. Vor dem Upgrade muss deshalb der gewuenschte gemeinsame Provider als `LLM_PROVIDER`, `OLLAMA_URL`/`OPENAI_BASE_URL` und optional `OPENAI_API_KEY` gesetzt werden.

Die fruehere globale GUI-Basic-Auth gibt es nicht mehr. Benutzer melden sich mit Paperless-NGX-Benutzername/Passwort an.

## Webhook (erforderlich fuer Webhook-Ingress)

| Variable | Default | Beschreibung |
|---|---|---|
| `PAPERLESS_WEBHOOK_SECRET` | — | Erforderliches instanzspezifisches Shared Secret fuer `POST /api/webhooks/paperless` und `POST /webhook`, sofern Setup nicht die verschluesselte globale Einstellung `webhook.secret` speichert. Die globale Einstellung hat Vorrang; `WEBHOOK_SECRET` ist ein Deployment-Fallback. Leere effektive Konfiguration schliesst beide Endpoints. |
| `PAPERLESS_WEBHOOK_MAX_BYTES` | `262144` | Maximale deklarierte und tatsaechliche Roh-Body-Groesse vor Parsing/Persistenz. |
| `PAPERLESS_WEBHOOK_RATE_LIMIT_PER_MINUTE` | `60` | Gemeinsames per-Client Minutenlimit fuer beide Aliase vor Persistenz. |
| `PAPERLESS_WEBHOOK_DEVELOPMENT_BYPASS` | `false` | Gefaehrlicher expliziter Bypass nur fuer isoliertes `local`/`development`; `production` und `testing` bleiben trotz Flag geschlossen und aktive lokale Nutzung wird in Startup-Log/Admin-UI gewarnt. |

Siehe [Webhook-Doku und Rotations-Runbook](./webhooks.md).

## MCP Server (optional)

MCP-Transport und Host/Port koennen in `/admin/settings` gepflegt werden. Per-user MCP Tokens werden separat unter `/settings/mcp-tokens` erstellt und widerrufen. Alle Tools und Resources sind derzeit retired, bis vollstaendige berechtigungsgebundene Laravel/PostgreSQL-Seams vorliegen; die folgenden Write-, Key- und Classification-Schalter sind daher inert und werden in einem spaeteren Konfigurations-Cleanup entfernt.

| Variable | Default | Beschreibung |
|---|---|---|
| `ENABLE_MCP` | `false` | MCP-Server im selben Container mitlaufen lassen |
| `MCP_TRANSPORT` | `stdio` | Transport: `stdio`, `sse`, `streamable-http` |
| `MCP_PORT` | `3001` | Port fuer SSE/HTTP-Transport |
| `MCP_HOST` | `0.0.0.0` | Bind-Adresse |
| `MCP_ENABLE_WRITE` | `false` | Inert; es sind keine MCP-Write-Tools registriert |
| `MCP_API_KEY` | — | Inert fuer registrierte Tools; statische Keys tragen keine verifizierte Benutzeridentitaet |
| `MCP_LARAVEL_AUTH_ENABLED` | `true` | Muss vor einer zukuenftigen Tool-Rueckkehr aktiv sein; MCP-Tokens ueber Laravel pruefen |
| `MCP_LARAVEL_PATH` | `/app/laravel` | Pfad zur Laravel-App fuer den lokalen Verifier |
| `MCP_LARAVEL_PHP_BINARY` | `php` | PHP-Binary fuer den lokalen Verifier |
| `MCP_CLASSIFY_RATE_LIMIT` | `10` | Inert, solange `classify_document` retired ist |

Details: [MCP-Server-Dokumentation](../developer/mcp.md)

## System

Diese Werte sind bewusst runtime-/deployment-only und werden nicht ueber `/admin/settings` geaendert, weil sie Container, Dateisystem oder Logging betreffen und einen Prozess-/Deployment-Neustart brauchen.

| Variable | Default | Beschreibung |
|---|---|---|
| `DATA_DIR` | `/data` | Persistentes Datenverzeichnis (DB, Config) |
| `LOG_LEVEL` | `INFO` | Log-Level: `DEBUG`, `INFO`, `WARNING`, `ERROR` |

## Upgrade bestehender Installationen: gepinnter Paperless-Origin

Vor dem Upgrade muss `PAPERLESS_URL` im Deployment explizit auf den bereits vertrauten Paperless-Origin gesetzt werden. Fehlt der Wert oder enthaelt er Credentials, Pfad, Query oder Fragment, schlagen Setup und Paperless-Verbindungen geschlossen fehl; ArchiBot faellt nicht auf einen gespeicherten Wert zurueck.

Ein bestehender PostgreSQL-Wert `paperless.url` oder `PAPERLESS_URL` in `/data/config.env` wird als Legacy-Migrationsdatum beibehalten, aber nicht mehr als Ziel verwendet. Die Admin-UI zeigt den Deployment-Origin read-only. Jeder verwaltete Python-Runtime-Export ersetzt einen alten Datei-/DB-/Call-Site-Wert durch den Deployment-Origin. Bei einem bewusst geaenderten Paperless-Ziel: Paperless-Workflows pausieren, `PAPERLESS_URL` im Deployment aendern, Container neu starten, Erreichbarkeit und Superuser-Login pruefen und erst dann Workflows fortsetzen. Ein unbeabsichtigter Zielwechsel sollte durch Zuruecksetzen der Deployment-Variable und Neustart zurueckgerollt werden, nicht durch Datenbank-Edits.

## Settings-UI

Admin-Settings liegen in der Laravel-Oberflaeche unter `/admin/settings`; per-user MCP Tokens unter `/settings/mcp-tokens`. Secrets werden maskiert und write-only gespeichert. Aenderungen werden in der Laravel-Datenbank auditiert. `paperless.url` ist die read-only Ausnahme und wird vom Deployment bestimmt.
