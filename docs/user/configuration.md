# Konfiguration

Einstellungen werden ueber Docker-Compose-Umgebungsvariablen und die Laravel Settings UI verwaltet. Beim ersten Setup importiert Laravel bestehende Werte aus `.env`/`/data/config.env` einmalig in `/data/laravel/database.sqlite`; danach sind Laravel-Settings fuehrend.

> Hinweis: Die mitgelieferte `.env.example` nutzt ein 6GB-VRAM-Preset
> (staerkere Embedding/OCR-Modelle). Die Tabellen unten dokumentieren die
> internen App-Defaults.

**Prioritaet (hoechste zuerst):**
1. Laravel Settings in `/data/laravel/database.sqlite`
2. OS-Umgebungsvariablen — gesetzt von Docker Compose aus `.env`
3. Legacy `{DATA_DIR}/config.env` — nur fuer den einmaligen Import beim Setup
4. Defaults — in Laravel/Python hinterlegt

## Paperless-NGX

| Variable | Default | Beschreibung |
|---|---|---|
| `PAPERLESS_URL` | — | Basis-URL, z.B. `http://paperless:8000` |
| `PAPERLESS_TOKEN` | — | API-Token (Paperless → Admin → Tokens) |
| `PAPERLESS_INBOX_TAG_ID` | — | ID des Tags `Posteingang`; in der Settings-UI per Live-Dropdown aus Paperless auswaehlbar |
| `PAPERLESS_PROCESSED_TAG_ID` | — | Optional: Tag-ID, die nach Commit gesetzt wird; in der Settings-UI per Live-Dropdown aus Paperless auswaehlbar |
| `KEEP_INBOX_TAG` | `true` | Posteingang-Tag nach Commit beibehalten |

## Ollama (allgemein)

| Variable | Default | Beschreibung |
|---|---|---|
| `OLLAMA_URL` | `http://ollama:11434` | Ollama-Endpoint |
| `OLLAMA_TIMEOUT_SECONDS` | `600` | HTTP-Timeout fuer Ollama-Requests (Sekunden) |
| `OLLAMA_CHAT_RETRIES` | `2` | Max. Retries fuer Chat/OCR/Klassifikation bei transienten Fehlern (429/5xx/Timeouts) |
| `OLLAMA_CHAT_RETRY_BASE_DELAY` | `1.0` | Basis-Delay in Sekunden fuer exponentiellen Chat-Backoff |
| `OLLAMA_MODEL_SWAP_DELAY` | `8.0` | Wartezeit nach Model-Unload, damit Ollama freie VRAM korrekt erkennt |

## Phase 1: OCR-Korrektur

| Variable | Default | Beschreibung |
|---|---|---|
| `OCR_MODE` | `off` | OCR-Stufe: `off`, `text`, `vision_light`, `vision_full` |
| `OCR_REQUESTED_TAG_ID` | `0` | Optionaler Paperless-Tag-Filter fuer OCR. `0`, leer oder nicht gesetzt = OCR fuer alle Dokumente. In der Settings-UI kann eine leere Auswahl den Env-Wert deaktivieren. Wenn der Tag spaeter in Paperless geloescht wird, wird OCR uebersprungen und ein Fehler im Dashboard angezeigt. Webhooks ohne Tag-IDs loesen keinen Zusatz-Lookup fuer OCR aus und ueberspringen OCR. |
| `OLLAMA_OCR_MODEL` | `qwen3:4b` | Modell fuer Text-Only OCR-Korrektur |
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

Wenn `OCR_REQUESTED_TAG_ID` gesetzt ist, laeuft bzw. wiederholt sich OCR nur fuer Dokumente, die diesen Tag aktuell tragen. Die restliche Pipeline (Embedding/Klassifikation) laeuft bei nicht passenden Dokumenten weiter.

**Graceful Degradation:** `vision_full` → `vision_light` → `text` → `off`.
Jede Stufe faengt Fehler ab und faellt auf die naechst niedrigere zurueck.

## Phase 2: Embedding

| Variable | Default | Beschreibung |
|---|---|---|
| `OLLAMA_EMBED_MODEL` | `qwen3-embedding:4b` | Embedding-Modell (hoehere Retrieval-Qualitaet) |
| `OLLAMA_EMBED_DIM` | `0` | Embedding-Dimension fuer sqlite-vec. `0` = Auto (`qwen3-embedding:0.6b`→1024, `qwen3-embedding:4b`→2560). |
| `OLLAMA_EMBED_NUM_CTX` | `8192` | Kontextfenster fuer das Embedding-Modell (Tokens) |
| `EMBED_MAX_CHARS` | `6000` | Max. Zeichen des Dokumenttexts fuer Embedding |
| `OLLAMA_EMBED_RETRIES` | `3` | Max. Retries bei Embedding-Fehlern (Truncation + transiente 500er) |
| `OLLAMA_EMBED_RETRY_BASE_DELAY` | `1.0` | Basis-Delay in Sekunden fuer exponentiellen Backoff |

## Phase 3: Klassifikation

| Variable | Default | Beschreibung |
|---|---|---|
| `OLLAMA_MODEL` | `gemma4:e4b` | Klassifikations-Modell (6GB-Empfehlung; Alternativen: `qwen3:4b`) |
| `OLLAMA_NUM_CTX` | `16384` | Kontextfenster fuer das Chat-Modell (Tokens) |
| `MAX_DOC_CHARS` | `24000` | Max. Zeichen des Dokumenttexts im LLM-Prompt |
| `CONTEXT_MAX_DOCS` | `5` | Wieviele aehnliche Dokumente als Few-Shot-Kontext |
| `AUTO_COMMIT_CONFIDENCE` | `0` | 0 = immer manuell reviewen. Ab diesem Score (1–100) automatisch committen. |
| `ENABLE_JUDGE_VERIFICATION` | `false` | Zweiter LLM-Pass, der jede Klassifikation prueft und ggf. korrigiert. Laeuft nur bei niedriger Confidence und vorhandenem Kontext. |
| `JUDGE_CONFIDENCE_THRESHOLD` | `85` | Judge-Pass wird uebersprungen, wenn die Initial-Confidence bereits >= diesem Wert (0–100) ist. |
| `OLLAMA_JUDGE_MODEL` | — | Optionales Modell fuer den Judge-Pass. Leer = `OLLAMA_MODEL` wiederverwenden (kein zusaetzlicher GPU-Swap). |

## Worker

| Variable | Default | Beschreibung |
|---|---|---|
| `POLL_INTERVAL_SECONDS` | `0` | Sekunden zwischen Inbox-Polls (`0` = automatisches Polling deaktiviert) |

## Laravel/Svelte GUI

| Variable | Default | Beschreibung |
|---|---|---|
| `GUI_PORT` | `8088` | Port der Laravel/Svelte-Web-GUI |
| `APP_URL` | — | Externe URL der ArchiBot-Instanz (z.B. `https://archibot.example`) |
| `APP_KEY` | auto-generiert in `/data/laravel/app_key` | Laravel-App-Key fuer Sessions und verschluesselte Secrets |
| `DB_DATABASE` | `/data/laravel/database.sqlite` | Laravel-App-Datenbank |
| `QUEUE_CONNECTION` | `database` | Laravel Queue Backend |
| `APP_PATH_PREFIX` | — | Optionaler Pfadpraefix; leer bedeutet GUI direkt unter `/` |

Die fruehere globale GUI-Basic-Auth gibt es nicht mehr. Benutzer melden sich mit Paperless-NGX-Benutzername/Passwort an.

## Telegram (optional)

| Variable | Default | Beschreibung |
|---|---|---|
| `ENABLE_TELEGRAM` | `false` | Telegram-Bot aktivieren |
| `TELEGRAM_BOT_TOKEN` | — | Bot-Token von @BotFather |
| `TELEGRAM_CHAT_ID` | — | Chat/Gruppen-ID fuer Benachrichtigungen |
| `TELEGRAM_POLL_INTERVAL` | `5` | Sekunden zwischen Telegram-getUpdates-Calls |

## Webhook (optional)

| Variable | Default | Beschreibung |
|---|---|---|
| `WEBHOOK_SECRET` | — | Shared Secret fuer `POST /webhook/paperless`. Siehe [Webhook-Doku](./webhooks.md). |

## MCP Server (optional)

| Variable | Default | Beschreibung |
|---|---|---|
| `ENABLE_MCP` | `false` | MCP-Server im selben Container mitlaufen lassen |
| `MCP_TRANSPORT` | `stdio` | Transport: `stdio`, `sse`, `streamable-http` |
| `MCP_PORT` | `3001` | Port fuer SSE/HTTP-Transport |
| `MCP_HOST` | `0.0.0.0` | Bind-Adresse |
| `MCP_ENABLE_WRITE` | `false` | Write-Tools aktivieren |
| `MCP_API_KEY` | — | Legacy-API-Key, nur wenn Laravel MCP Auth deaktiviert ist |
| `MCP_LARAVEL_AUTH_ENABLED` | `true` | MCP-Tokens ueber Laravel pruefen |
| `MCP_LARAVEL_PATH` | `/app/laravel` | Pfad zur Laravel-App fuer den lokalen Verifier |
| `MCP_LARAVEL_PHP_BINARY` | `php` | PHP-Binary fuer den lokalen Verifier |
| `MCP_CLASSIFY_RATE_LIMIT` | `10` | Max. KI-Klassifikationen pro Stunde (0 = unbegrenzt) |

Details: [MCP-Server-Dokumentation](../developer/mcp.md)

## System

| Variable | Default | Beschreibung |
|---|---|---|
| `DATA_DIR` | `/data` | Persistentes Datenverzeichnis (DB, Config) |
| `LOG_LEVEL` | `INFO` | Log-Level: `DEBUG`, `INFO`, `WARNING`, `ERROR` |

## Settings-UI

Admin-Settings liegen in der Laravel-Oberflaeche unter `/admin/settings`; per-user MCP Tokens unter `/settings/mcp-tokens`. Secrets werden maskiert und write-only gespeichert. Aenderungen werden in der Laravel-Datenbank auditiert.
