# Installation

Anleitung zur Einrichtung von ArchiBot — als fertiges Docker-Image
oder selbst gebaut.

## Voraussetzungen

- Docker + Docker Compose
- Eine laufende [Paperless-NGX](https://docs.paperless-ngx.com/) Instanz
- Eine laufende lokale AI-Provider-Instanz: native [Ollama](https://ollama.com/) (GPU empfohlen) oder ein OpenAI-kompatibler `/v1`-Endpoint wie OpenAI-compatible.
- Bei nativer Ollama-Nutzung muessen Modelle vorab gezogen werden:
  ```bash
  ollama pull gemma4:e4b                # Klassifikation
  ollama pull qwen3-embedding:4b        # Embedding (mehr Qualitaet, mehr VRAM)
  ollama pull qwen3:4b                  # OCR-Korrektur (optional)
  ollama pull qwen3-vl:4b               # Vision-OCR (optional)
  ```
- Bei OpenAI-compatible/OpenAI-kompatiblen Setups muessen die konfigurierten Modell-Aliasse erreichbar sein. Embedding-Aliasse wie `qwen3-embedding-4b-local` sind erlaubt; ArchiBot sendet OpenAI-kompatible Embedding-Requests mit `encoding_format="float"`.

## Option A: Fertiges Image von GHCR (empfohlen)

```bash
# 1. docker-compose.yml und .env herunterladen
curl -LO https://raw.githubusercontent.com/pfriedrich84/archibot/main/docker-compose.yml
curl -LO https://raw.githubusercontent.com/pfriedrich84/archibot/main/.env.example
cp .env.example .env
# → PAPERLESS_URL auf den vertrauten Paperless-Origin setzen; weitere Provider-Werte optional

# 2. Starten (zieht automatisch ghcr.io/pfriedrich84/archibot:latest)
docker compose up -d

# 3. GUI oeffnen
open http://localhost:8088
```

> **Verfuegbare Image-Tags:**
> - `latest` — aktueller Stand von `main`
> - `v0.1.0`, `v0.1` — versionierte Releases (bei getaggten Releases)
> - `sha-<hash>` — spezifischer Commit

## Option B: Selbst bauen

```bash
# 1. Repo klonen
git clone git@github.com:pfriedrich84/archibot.git
cd archibot

# 2. .env anlegen
cp .env.example .env
# → PAPERLESS_URL auf den vertrauten Paperless-Origin setzen; weitere Provider-Werte optional

# 3. Bauen und starten
docker compose up -d --build

# 4. GUI oeffnen
open http://localhost:8088
```

## Erster Start

Beim ersten Start wird automatisch der Laravel Setup-Wizard angezeigt (`/setup`).
Er fuehrt durch:

1. **Paperless-Verbindung** — den aus Deployment-`PAPERLESS_URL` gepinnten Origin read-only pruefen und mit Benutzername/Passwort eines echten Paperless-Superusers verifizieren; Setup kann kein anderes Ziel waehlen
2. **Tags und direkte Anmeldung** — Inbox-, optionales Processed- und OCR-Requested-Tag anhand lesbarer Namen waehlen; ArchiBot speichert den Paperless-API-Token pro Benutzer verschluesselt
3. **Einstellungen importieren** — vorhandene Werte aus `.env`/`/data/config.env` werden einmalig in die Laravel-Datenbank importiert; ein alter `paperless.url`-Wert bleibt nur Migrationsdatum und kann den Deployment-Origin nicht ueberschreiben
4. **AI-Anbindung** — nach erfolgreichem Claim wird direkt `/admin/settings/ai-provider` geoeffnet. Dort wird genau ein installationsweiter Provider-Endpunkt konfiguriert. Fuer Klassifikation, Embedding, OCR Text, OCR Vision und Judge koennen unterschiedliche Modelle geladen, frei eingetragen und validiert werden; der Paperless-Origin bleibt read-only
5. **Admin-Diagnostik und Maintenance** — Nur ArchiBot-Admins koennen Operations Log, Pipeline Runs, Actor Executions, Webhook Deliveries, Statistiken, Fehler, Embedding-Diagnostik, Maintenance und Audit-Logs direkt aufrufen. Die Seiten zeigen Status, IDs, Zaehler, strukturierte Metadaten, Badges und Ereignis-Timelines; rohe JSON-Payloads/Headers sowie freie Fehler-, Dokument-, OCR- und Prompt-Inhalte werden nicht ausgegeben. Konfigurierbare Provider- und Modell-IDs erscheinen nur als stabile, nicht rueckrechenbare Referenzen. Poll/Reindex/Einzeldokument-Verarbeitung bleibt ueber die admin-geschuetzte Laravel-Maintenance verfuegbar.

Danach ist die Laravel/Svelte-Oberflaeche die primaere App. Python bleibt fuer Klassifikation, Embeddings, Paperless-Ausfuehrung und MCP aktiv.

## Lokale Entwicklung (ohne Docker)

Python Worker/MCP Runtime:

```bash
python3.12 -m venv .venv
source .venv/bin/activate
pip install -c constraints.txt -e ".[dev]"
cp .env.example .env
pytest tests/ -q
```

Laravel/Svelte App:

```bash
cd laravel
composer install
npm ci
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve --host=0.0.0.0 --port=8088
# Separat bei Bedarf: php artisan queue:work --queue=default
```

Vor Commits sollten die relevanten Checks laufen: Python `ruff`/`pytest`, Laravel `composer test`, `npm run lint:check`, `npm run format:check`, `npm run types:check` und `npm run build`.

## Upgrade eines persistenten PostgreSQL-Volumes

Vor einem Upgrade muss ein konsistentes PostgreSQL-Backup erstellt und die
Laravel-Queue angehalten werden. Die Actor-Fencing-Migration laeuft unter
PostgreSQL in einer Transaktion. Bei mehreren aktiven Ausfuehrungen derselben
dauerhaften Quelle waehlt sie unabhaengig vom Actor-Namen deterministisch einen
Gewinner (laufend vor wartend, danach hoechster Versuch und hoechste ID),
markiert alle Verlierer als
`failed_permanent` und schreibt pro Verlierer ein Audit-Ereignis
`actor.execution.reconciled_duplicate`, bevor die partiellen Unique-Indizes
angelegt werden. Ein alleiniger `pending`-Gewinner besitzt noch keinen nutzbaren
Queue-Claim. Die Migration ueberfuehrt einen direkt wiederholbaren Gewinner
deshalb mit sofort faelligem Zeitpunkt in `retrying`, setzt Command, Pipeline Run
oder Webhook Delivery in den jeweils sicheren Recovery-Zustand und behaelt bis
zur Redispatch-Transaktion einen uebereinstimmenden Fence-Token. Bei terminalen
oder blockierten Quellen und bei Process-Document-Webhooks wird nur der obsolete
Actor-Versuch beendet; die Quelle bleibt ihrem normalen Gate beziehungsweise der
Pipeline-Start-Reconciliation ueberlassen. Recovery erzeugt fuer faellige
Versuche anschliessend genau einen neuen `queued` Claim. Migrationstoken sind feste, deterministische 45-Zeichen-Hashes;
auch maximale PostgreSQL-`bigint`-IDs koennen die 64-Zeichen-Spalten nicht
ueberschreiten. Ein Fehler rollt Schema, Statusaenderungen und Audit-Ereignisse
gemeinsam zurueck; das Volume bleibt auf dem alten Stand.

Nach dem Upgrade sind Migrationstatus, Operations Log und Queue vor dem Neustart
der Worker zu pruefen. Ein Schema-Rollback entfernt die Fencing-Spalten, aktiviert
aber bereinigte Altversuche nicht wieder. Fuer einen vollstaendigen Rollback daher
Container und PostgreSQL-Volume stoppen und das Backup in ein neues/leeres Volume
wiederherstellen. Niemals ein teilweise aktualisiertes Volume mit einer aelteren
ArchiBot-Version weiterbetreiben.

## Naechste Schritte

- [Konfiguration](./configuration.md) — Alle Umgebungsvariablen im Detail
- [CLI Commands](../developer/cli.md) — Manuelle Pipeline-Steuerung
- [Review-Workflow](./workflow.md) — So funktioniert die Klassifikation
