# Konfiguration

Einstellungen werden ueber Docker-Compose-Umgebungsvariablen und die Laravel Settings UI verwaltet. Beim ersten Setup importiert Laravel bestehende Werte aus `.env`/`/data/config.env` einmalig in PostgreSQL; danach sind Laravel-Settings fuehrend.

> Hinweis: Die mitgelieferte `.env.example` nutzt ein 6GB-VRAM-Preset
> (staerkere Embedding/OCR-Modelle). Die Tabellen unten dokumentieren die
> internen App-Defaults.

**Prioritaet (hoechste zuerst):**
1. Laravel Settings in PostgreSQL
2. OS-Umgebungsvariablen — gesetzt von Docker Compose aus `.env`
3. Legacy `{DATA_DIR}/config.env` — nur fuer den einmaligen Import beim Setup
4. Defaults — in Laravel/Python hinterlegt

## Paperless-NGX

| Variable | Default | Beschreibung |
|---|---|---|
| `PAPERLESS_URL` | — | Basis-URL, z.B. `http://paperless:8000` |
| `PAPERLESS_INBOX_TAG_ID` | — | ID des Tags `Posteingang`; in der Settings-UI per Live-Dropdown aus Paperless auswaehlbar |
| `PAPERLESS_PROCESSED_TAG_ID` | — | Optional: Tag-ID, die nach Commit gesetzt wird; in der Settings-UI per Live-Dropdown aus Paperless auswaehlbar |
| `KEEP_INBOX_TAG` | `true` | Posteingang-Tag nach Commit beibehalten |

## AI Provider / Ollama (allgemein)

ArchiBot nutzt intern eine neutrale AI-Provider-Schnittstelle. Unterstuetzt werden Ollama-kompatible Provider und OpenAI-kompatible `/v1`-APIs. Die Setup-UI kann Modelle vom gewaehlten Provider laden; manuelle Eingabe bleibt moeglich, falls ein Provider keine vollstaendige Modellliste liefert. Legacy-Variablennamen mit `OLLAMA_*` bleiben aus Kompatibilitaetsgruenden erhalten und bedeuten nicht, dass die Verarbeitung nur eine bestimmte Ollama-Instanz unterstuetzt.

Der einfache Modus nutzt einen globalen Provider. Optional koennen zusaetzliche benannte Provider-Profile angelegt und pro Rolle ausgewaehlt werden, z.B. ein lokaler OpenAI-kompatibler Embedding-Endpoint plus ein separater OpenAI-kompatibler Judge-Endpoint. Cloud-Provider koennen Dokumenttext/OCR-Inhalte erhalten und sollten bewusst markiert/verwendet werden.

| Variable | Default | Beschreibung |
|---|---|---|
| `LLM_PROVIDER` | `ollama` | `ollama` fuer Ollama-kompatible API oder `openai_compatible` fuer OpenAI-kompatible `/v1`-API |
| `OLLAMA_URL` / `OPENAI_BASE_URL` | `http://ollama:11434` | Provider-Basis-URL. Fuer `openai_compatible` inkl. `/v1`, z.B. `http://localhost:11434/v1`. `OPENAI_BASE_URL` ist ein Alias fuer OpenAI-kompatible Setups; `OLLAMA_URL` bleibt der Legacy-Name. |
| `OPENAI_API_KEY` | — | Optionaler Bearer Token fuer OpenAI-kompatible Provider; leer lassen bei lokalen Endpunkten ohne Auth |
| `AI_PROVIDER_PROFILES` | — | Optionales JSON-Array weiterer Provider-Profile (`id`, `type`, `base_url`, optional `api_key_env`, `is_cloud`). Secrets bevorzugt per Env-Variable referenzieren, nicht inline speichern. |
| `CLASSIFICATION_PROVIDER` | — | Provider-Profil-ID fuer Klassifikation; leer = Default |
| `EMBEDDING_PROVIDER` | — | Provider-Profil-ID fuer Embeddings; leer = Default |
| `OCR_PROVIDER` | — | Provider-Profil-ID fuer OCR Text/Vision; leer = Default |
| `JUDGE_PROVIDER` | — | Provider-Profil-ID fuer Judge-Verifikation; leer = Default |
| `CHAT_PROVIDER` | — | Provider-Profil-ID fuer Chat/RAG; leer = Default |
| `OLLAMA_TIMEOUT_SECONDS` | `600` | HTTP-Timeout fuer AI-Provider-Requests (Sekunden) |
| `OLLAMA_CHAT_RETRIES` | `2` | Max. Retries fuer Chat/OCR/Klassifikation bei transienten Fehlern (429/5xx/Timeouts) |
| `OLLAMA_CHAT_RETRY_BASE_DELAY` | `1.0` | Basis-Delay in Sekunden fuer exponentiellen Chat-Backoff |
| `OLLAMA_MODEL_SWAP_DELAY` / `OLLAMA_MODEL_SWAP_DELAY_SECONDS` | `8.0` | Wartezeit nach Model-Unload, damit Ollama-kompatible Runtimes freie VRAM korrekt erkennen; nur bei Providern genutzt, die Model-Unload unterstuetzen. `_SECONDS` ist ein Legacy-Alias. |

Beispiel fuer mehrere Provider:

```json
[
  {
    "id": "local-embeddings",
    "label": "Local embeddings",
    "type": "openai_compatible",
    "base_url": "http://localhost:11434/v1"
  },
  {
    "id": "local-judge",
    "label": "Local judge",
    "type": "ollama",
    "base_url": "http://ollama:11434"
  }
]
```

Dann z.B. `EMBEDDING_PROVIDER=local-embeddings` und `JUDGE_PROVIDER=local-judge` setzen.

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

> **Security-Hinweis:** ADR-0018 verlangt, Confidence-basiertes Auto-Commit abzuschalten. Bis Hardening-Meilenstein 0.2 ausgeliefert ist, kann ein veralteter effektiver Python-Export Werte groesser als `0` weiterhin ausfuehren, selbst wenn Env oder UI `0` anzeigen. Es gibt keine verlaessliche reine Einstellungsminderung; bis Meilenstein 0.2 darf keine Dokumentklassifikation/-verarbeitung gestartet werden.

| Variable | Default | Beschreibung |
|---|---|---|
| `CLASSIFICATION_MODEL` / `OLLAMA_MODEL` | `gemma4:e4b` | Klassifikations-Modell (6GB-Empfehlung; Alternativen: `qwen3:4b`). `CLASSIFICATION_MODEL` ist der bevorzugte Name. |
| `CLASSIFICATION_CONTEXT_WINDOW` / `OLLAMA_NUM_CTX` | `16384` | Kontextfenster fuer Klassifikation, Judge und Chat/RAG (Tokens). Separate `CHAT_CONTEXT_WINDOW`/`JUDGE_CONTEXT_WINDOW` sind derzeit keine Runtime-Settings. |
| `MAX_DOC_CHARS` | `24000` | Max. Zeichen des Dokumenttexts im LLM-Prompt |
| `CONTEXT_MAX_DOCS` | `5` | Wieviele aehnliche Dokumente als Few-Shot-Kontext |
| `AUTO_COMMIT_CONFIDENCE` | `0` | Nur `0` ist waehrend des ausstehenden Security-Hardenings zulaessig. Die aktuelle Runtime kann Werte 1–100 noch als automatische Commit-Schwelle interpretieren; ADR-0018 verbietet diese Nutzung bis zur sicheren Neugestaltung. |
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
| `ABSURD_DATABASE_URL` | — | Nur noch migration-only Konfiguration bis zum separaten Absurd-Cleanup. Supervisor startet keinen Absurd Worker oder Recovery-Bridge mehr. |

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
| `APP_PATH_PREFIX` | — | Optionaler Pfadpraefix; leer bedeutet GUI direkt unter `/` |

Die GUI zeigt Paperless-Labels/Namen statt roher numerischer IDs an (z.B. `Posteingang` statt `124`). IDs bleiben nur interne technische Referenzen.

Die fruehere globale GUI-Basic-Auth gibt es nicht mehr. Benutzer melden sich mit Paperless-NGX-Benutzername/Passwort an.

## Webhook (optional)

| Variable | Default | Beschreibung |
|---|---|---|
| `WEBHOOK_SECRET` | — | Erforderliches Shared Secret fuer `POST /api/webhooks/paperless` oder den Alias `POST /webhook`. Bis Hardening-Meilenstein 0.4 muss der aktuelle Fail-open-Zwischenstand durch vertrauenswuerdige Netzwerkisolation abgesichert werden. Siehe [Webhook-Doku](./webhooks.md). |

## MCP Server (optional)

MCP-Transport, Host/Port, Write-Tool-Schalter, Auth-Modus und Rate-Limit koennen in `/admin/settings` gepflegt werden. Per-user MCP Tokens werden separat unter `/settings/mcp-tokens` erstellt und widerrufen.

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

Diese Werte sind bewusst runtime-/deployment-only und werden nicht ueber `/admin/settings` geaendert, weil sie Container, Dateisystem oder Logging betreffen und einen Prozess-/Deployment-Neustart brauchen.

| Variable | Default | Beschreibung |
|---|---|---|
| `DATA_DIR` | `/data` | Persistentes Datenverzeichnis (DB, Config) |
| `LOG_LEVEL` | `INFO` | Log-Level: `DEBUG`, `INFO`, `WARNING`, `ERROR` |

## Settings-UI

Admin-Settings liegen in der Laravel-Oberflaeche unter `/admin/settings`; per-user MCP Tokens unter `/settings/mcp-tokens`. Secrets werden maskiert und write-only gespeichert. Aenderungen werden in der Laravel-Datenbank auditiert.
