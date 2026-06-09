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
# → Docker-/AI-Provider-Werte eintragen; Paperless-Verbindung wird im Setup-Wizard konfiguriert

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
# → Docker-/AI-Provider-Werte eintragen; Paperless-Verbindung wird im Setup-Wizard konfiguriert

# 3. Bauen und starten
docker compose up -d --build

# 4. GUI oeffnen
open http://localhost:8088
```

## Erster Start

Beim ersten Start wird automatisch der Laravel Setup-Wizard angezeigt (`/setup`).
Er fuehrt durch:

1. **Paperless-Verbindung** — URL eintragen und mit Paperless-Benutzername/-Passwort eines Superusers verifizieren
2. **Direkte Anmeldung** — ArchiBot speichert den Paperless-API-Token pro Benutzer verschluesselt; danach erfolgt die GUI-Anmeldung mit Paperless-Zugangsdaten
3. **Einstellungen importieren** — vorhandene Werte aus `.env`/`/data/config.env` werden einmalig in die Laravel-Datenbank importiert; Wizard-Werte gewinnen bei Konflikten
4. **Admin-Settings** — AI-Provider/Ollama, Inbox-Tag, Klassifikation, Review, Worker, MCP und Audit-Retention werden in `/admin/settings` gepflegt
5. **Maintenance und Operations Log** — Poll/Reindex/Einzeldokument-Verarbeitung starten ueber Laravel Maintenance; `/operations-log` zeigt durable Commands, Pipeline Runs, Actor Executions, Webhooks und Audit-Logs.

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

## Naechste Schritte

- [Konfiguration](./configuration.md) — Alle Umgebungsvariablen im Detail
- [CLI Commands](../developer/cli.md) — Manuelle Pipeline-Steuerung
- [Review-Workflow](./workflow.md) — So funktioniert die Klassifikation
