# ArchiBot

[![AI Assisted](https://img.shields.io/badge/AI-Assisted-blueviolet)](https://github.com/pfriedrich84/archibot)
![Project Status](https://img.shields.io/badge/status-in%20development-orange)
![Release](https://img.shields.io/badge/release-none%20yet-lightgrey)

> **Hinweis:** ArchiBot ist aktuell in aktiver Entwicklung. Es gibt noch keinen stabilen Release.

<p align="center">
  <img src="app/static/logo-full.png" alt="ArchiBot Logo" width="256">
</p>

KI-basierter Klassifikator für [Paperless-NGX](https://docs.paperless-ngx.com/), der neu eingescannte Dokumente (Tag `Posteingang`) automatisch verprobt und Vorschläge für **Titel, Datum, Korrespondent, Dokumenttyp und Speicherpfad** erzeugt. Läuft als **ein einzelner Docker-Container** gegen lokale Ollama- oder OpenAI-kompatible Provider wie LiteLLM.

Alle Vorschläge landen in einer Review-Queue und werden erst nach manueller Freigabe in Paperless geschrieben. Neue Tags, die das LLM vorschlägt, werden nur angelegt, wenn du sie in der Tag-Whitelist freigibst. Ein bereits gesetzter Paperless-Speicherpfad wird dabei nie überschrieben; ArchiBot setzt den Speicherpfad nur, wenn er am Dokument noch leer ist.

## Features

- 🔍 Polling von Paperless-NGX nach Dokumenten mit Tag `Posteingang`
- 🧠 Klassifikation via lokalem AI-Provider/Ollama (Default: `gemma4:e4b`, konfigurierbar)
- 📚 Kontextaware durch Embedding-Similarity-Search über bereits klassifizierte Dokumente (`pgvector`) — Kontext-Dokumente liefern ihre vollständige Klassifikation (Korrespondent, Typ, Tags, Speicherpfad) als Referenz
- 🛡️ LLM-as-Judge (optional): zweiter LLM-Pass prüft und korrigiert unsichere Klassifikationen, nur bei niedriger Erst-Confidence + vorhandenem Kontext — kein zusätzlicher GPU-Swap wenn dasselbe Modell wiederverwendet wird
- ⚡ Frühe Poll-Ergebnisse: Inbox-Polls veröffentlichen/auto-committen jedes Dokument direkt nach Klassifikation/Judge, sofern dafür kein separates Judge-Modell geladen werden muss
- ✅ Review-GUI in der Svelte-Admin-App: Annehmen / Ablehnen / Editieren in einem Klick
- 🏷️ Tag-Whitelist: Neue Tags werden vorgeschlagen, aber erst nach Freigabe in Paperless angelegt
- 📝 Multi-Level OCR-Korrektur: text-only, vision-light oder vision-full (konfigurierbar via `OCR_MODE`), optional eingeschraenkt auf Dokumente mit `OCR_REQUESTED_TAG_ID`
- ⏱️ Robuste AI-Provider-Requests: Default-Timeout ist auf 600s ausgelegt (insb. fuer langsamere OCR/vision-Laeufe)
- 🗄️ PostgreSQL-State mit vollständigem Audit-Trail
- 🔁 Idempotent: verarbeitet jedes Dokument nur einmal
- 💬 RAG Chat: Fragen zu deinen Dokumenten stellen — über Python/MCP/Telegram-Runtime; die Laravel-Oberfläche wird schrittweise erweitert
- 🤖 Telegram-Bot: Vorschläge annehmen/ablehnen + RAG-Chat für Dokument-Fragen (optional)
- 🔌 MCP Server: Paperless-NGX + KI-Klassifikation als Tools für Claude Code und andere KI-Assistenten (optional)
- 🚀 Laravel/Svelte Setup-Wizard: Geführtes Onboarding beim ersten Start (`/setup`) mit direkter Paperless-NGX-Anmeldung
- 📥 Inbox-View: Posteingang mit Dokumenten-Karten (`/inbox`)
- 🏷️ Entity-Freigaben: Tags, Korrespondenten und Dokumenttypen in Laravel verwalten (`/tags`, `/correspondents`, `/doctypes`)
- 🔔 Webhook-Support: Sofortige Verarbeitung + Embedding-Update via Paperless-Workflow-Webhooks
- ⚙️ Settings UI: Konfiguration im Browser ändern, ohne Container-Neustart (`/admin/settings`, `/settings/appearance`, `/settings/mcp-tokens`)
- 🐳 Single-Container, Dockhand-ready, fertiges Image via [GitHub Container Registry](https://ghcr.io/pfriedrich84/archibot)

## Architektur

```
┌────────────────┐   poll     ┌──────────────────────┐
│ Paperless-NGX  │◀───────────│  Worker (APScheduler)│
│  (Tag: Post-   │            │   - fetch inbox docs │
│   eingang)     │──docs─────▶│   - build context    │
└────────────────┘            │   - call AI provider │
        ▲                     │   - store suggestion │
        │                     └──────────┬───────────┘
        │ PATCH                          │
        │ (nach Freigabe)                ▼
        │                     ┌──────────────────────┐
        │                     │ PostgreSQL + pgvector │
        │                     │   - suggestions      │
        │                     │   - tag whitelist    │
        │                     │   - embeddings       │
        │                     │   - audit log        │
        │                     └──────────┬───────────┘
        │                                │
        │                                ▼
        │                     ┌──────────────────────┐
        └─────────────────────│ Laravel + Svelte GUI │
                              │   - /dashboard       │
                              │   - /review          │
                              │   - /inbox           │
                              │   - /tags            │
                              │   - /correspondents  │
                              │   - /doctypes        │
                              │   - /worker-jobs     │
                              │   - /admin/settings  │
                              │   - /setup           │
                              └──────────────────────┘
                                         ▲
                                         │
                                       Browser
```

## Quickstart

```bash
# 1. docker-compose.yml und .env herunterladen
curl -LO https://raw.githubusercontent.com/pfriedrich84/archibot/main/docker-compose.yml
curl -LO https://raw.githubusercontent.com/pfriedrich84/archibot/main/.env.example
cp .env.example .env
# → optional Werte eintragen; alternativ im Setup-Wizard konfigurieren
# → für LiteLLM/OpenAI-kompatible Provider: LLM_PROVIDER=openai_compatible,
#   OPENAI_BASE_URL/OLLAMA_URL inklusive /v1 setzen und Modell-Aliasse eintragen

# 2. Modelle bereitstellen
# Native Ollama:
ollama pull gemma4:e4b
ollama pull qwen3-embedding:4b
ollama pull qwen3:4b              # OCR-Korrektur (optional)
ollama pull qwen3-vl:4b           # Vision-OCR (optional)
# OpenAI-kompatible Provider wie LiteLLM: Modell-Aliasse im Provider konfigurieren.
# Embedding-Requests senden encoding_format="float" und funktionieren z.B. mit llama.cpp.

# 3. Starten
docker compose up -d

# 4. GUI öffnen → Setup-Wizard führt durch die Ersteinrichtung
open http://localhost:8088
```

Weitere Optionen (selbst bauen, lokale Entwicklung): **[docs/user/installation.md](./docs/user/installation.md)**

## Modell-Empfehlungen (6GB VRAM)

**Empfohlen (Balanced/Qualitaet):**
- Klassifikation: `gemma4:e4b` (Default)
- Embeddings: `qwen3-embedding:4b`
- OCR text-only: `qwen3:4b`
- OCR vision: `qwen3-vl:4b` mit `OCR_MODE=vision_light`

**Alternative Klassifikationsmodelle (testen):**
- `qwen3:4b` (oft gut bei strukturierter Extraktion)
- `llama3.1:8b` (nur sinnvoll, wenn dein Host/Ollama-Offloading stabil laeuft)

> Wichtig: Wenn du `ARCHIBOT_EMBEDDING_MODEL`/`OLLAMA_EMBED_MODEL` oder
> `OLLAMA_EMBED_DIM` aenderst, fuehre danach einen **Full Reindex** aus,
> damit pgvector mit der neuen Embedding-Dimension neu aufgebaut wird.
> OpenAI-kompatible Embeddings setzen explizit `encoding_format: "float"`.

## Dokumentation

| Dokument | Beschreibung |
|---|---|
| **[Doku-Index](./docs/README.md)** | Uebersicht ueber User-, Developer- und Agent-Dokumentation |
| **[Installation](./docs/user/installation.md)** | Quickstart, Docker-Setup, lokale Entwicklung |
| **[Konfiguration](./docs/user/configuration.md)** | Alle Umgebungsvariablen im Detail |
| **[Review-Workflow](./docs/user/workflow.md)** | Klassifikation, Review, Tag-Management |
| **[CLI Commands](./docs/developer/cli.md)** | Manuelle Pipeline-Steuerung und Container-Reset |
| **[MCP Server](./docs/developer/mcp.md)** | KI-Tools für Claude Code und andere Assistenten |
| **[Deployment](./docs/user/deployment.md)** | Dockhand, Reverse Proxy, Backup |
| **[Architektur](./docs/developer/architecture.md)** | Datenfluss-Diagramme und System-Kontext |
| **[Webhooks](./docs/user/webhooks.md)** | Sofortige Verarbeitung statt Polling |
| **[Paperless-Auth](./docs/user/paperless-auth.md)** | Anmeldung, Token-Kontext und Sicherheitsmodell |
| **[Agent Instructions](./AGENTS.md)** | Tool-neutrale Hinweise fuer Coding-Agents |

## Entwicklung & Repository-Hinweise

| Datei / Pfad | Zweck |
|---|---|
| [`pyproject.toml`](./pyproject.toml) | Python-Paket, Dependencies, Ruff- und pytest-Konfiguration |
| [`constraints.txt`](./constraints.txt) | Gepinnte transitive Python-Abhaengigkeiten fuer reproduzierbare Builds |
| [`scripts/check_dependency_age.py`](./scripts/check_dependency_age.py) | 3-Tage Supply-Chain-Check fuer Python-Dependencies |
| [`scripts/check_markdown_links.py`](./scripts/check_markdown_links.py) | Lokaler Markdown-Link-Check fuer Dokumentation |
| [`.github/workflows/ci.yml`](./.github/workflows/ci.yml) | CI: Lint, Tests, Audit, Dependency-Age, Docker Build, Grype/Trivy |
| [`laravel/composer.json`](./laravel/composer.json) | Laravel/PHP Dependencies und Backend-Test-Scripts |
| [`laravel/package.json`](./laravel/package.json) | Frontend-Scripts fuer Lint, Format, Typecheck und Build |

## Lizenz

MIT — siehe `LICENSE`.

---

Developed & maintained by [@pfriedrich84](https://github.com/pfriedrich84), AI‑assisted.
