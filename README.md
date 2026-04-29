# ArchiBot

[![AI Assisted](https://img.shields.io/badge/AI-Assisted-blueviolet)](https://github.com/pfriedrich84/archibot)

<p align="center">
  <img src="app/static/logo-full.png" alt="ArchiBot Logo" width="256">
</p>

KI-basierter Klassifikator für [Paperless-NGX](https://docs.paperless-ngx.com/), der neu eingescannte Dokumente (Tag `Posteingang`) automatisch verprobt und Vorschläge für **Titel, Datum, Korrespondent, Dokumenttyp und Speicherpfad** erzeugt. Läuft als **ein einzelner Docker-Container** gegen eine lokale **Ollama**-Instanz.

Alle Vorschläge landen in einer Review-Queue und werden erst nach manueller Freigabe in Paperless geschrieben. Neue Tags, die das LLM vorschlägt, werden nur angelegt, wenn du sie in der Tag-Whitelist freigibst. Ein bereits gesetzter Paperless-Speicherpfad wird dabei nie überschrieben; ArchiBot setzt den Speicherpfad nur, wenn er am Dokument noch leer ist.

## Features

- 🔍 Polling von Paperless-NGX nach Dokumenten mit Tag `Posteingang`
- 🧠 Klassifikation via Ollama (Default: `gemma4:e4b`, konfigurierbar)
- 📚 Kontextaware durch Embedding-Similarity-Search über bereits klassifizierte Dokumente (`sqlite-vec`) — Kontext-Dokumente liefern ihre vollständige Klassifikation (Korrespondent, Typ, Tags, Speicherpfad) als Referenz
- 🛡️ LLM-as-Judge (optional): zweiter LLM-Pass prüft und korrigiert unsichere Klassifikationen, nur bei niedriger Erst-Confidence + vorhandenem Kontext — kein zusätzlicher GPU-Swap wenn dasselbe Modell wiederverwendet wird
- ✅ Review-GUI in der Svelte-Admin-App: Annehmen / Ablehnen / Editieren in einem Klick
- 🏷️ Tag-Whitelist: Neue Tags werden vorgeschlagen, aber erst nach Freigabe in Paperless angelegt
- 📝 Multi-Level OCR-Korrektur: text-only, vision-light oder vision-full (konfigurierbar via `OCR_MODE`), optional eingeschraenkt auf Dokumente mit `OCR_REQUESTED_TAG_ID`
- ⏱️ Robuste Ollama-Requests: Default-Timeout ist auf 600s ausgelegt (insb. fuer langsamere OCR/vision-Laeufe)
- 🗄️ SQLite-State mit vollständigem Audit-Trail
- 🔁 Idempotent: verarbeitet jedes Dokument nur einmal
- 💬 RAG Chat: Fragen zu deinen Dokumenten stellen — im Browser (`/app/chat`) oder direkt im Telegram-Chat
- 🤖 Telegram-Bot: Vorschläge annehmen/ablehnen + RAG-Chat für Dokument-Fragen (optional)
- 🔌 MCP Server: Paperless-NGX + KI-Klassifikation als Tools für Claude Code und andere KI-Assistenten (optional)
- 🚀 Setup-Wizard: Geführtes Onboarding beim ersten Start (`/app/setup`) mit Paperless-Tag-Dropdowns und aus Ollama geladener Modell-Auswahl
- 📊 Embeddings-Dashboard: Vektor-DB-Inspektion und Similarity-Search (`/app/embeddings`)
- 📥 Inbox-View: Posteingang mit Dokumenten-Karten und Bulk-Aktionen (`/app/inbox`)
- 🏷️ Tag-Blacklist: Unerwünschte Tags dauerhaft unterdrücken (`/app/tags`)
- 🔔 Webhook-Support: Sofortige Verarbeitung + Embedding-Update via Paperless-Workflow-Webhooks
- ⚙️ Settings UI: Konfiguration im Browser ändern, ohne Container-Neustart (`/app/settings`); Paperless-Tag-Felder zeigen gespeicherte Werte vorausgewählt
- 🐳 Single-Container, Dockhand-ready, fertiges Image via [GitHub Container Registry](https://ghcr.io/pfriedrich84/archibot)

## Architektur

```
┌────────────────┐   poll     ┌──────────────────────┐
│ Paperless-NGX  │◀───────────│  Worker (APScheduler)│
│  (Tag: Post-   │            │   - fetch inbox docs │
│   eingang)     │──docs─────▶│   - build context    │
└────────────────┘            │   - call Ollama      │
        ▲                     │   - store suggestion │
        │                     └──────────┬───────────┘
        │ PATCH                          │
        │ (nach Freigabe)                ▼
        │                     ┌──────────────────────┐
        │                     │   SQLite + vec0      │
        │                     │   - suggestions      │
        │                     │   - tag whitelist    │
        │                     │   - embeddings       │
        │                     │   - audit log        │
        │                     └──────────┬───────────┘
        │                                │
        │                                ▼
        │                     ┌──────────────────────┐
        └─────────────────────│ FastAPI + Svelte GUI │
                              │   - /app/review      │
                              │   - /app/chat        │
                              │   - /app/inbox       │
                              │   - /app/tags        │
                              │   - /app/embeddings  │
                              │   - /app/stats       │
                              │   - /app/settings    │
                              │   - /app/setup       │
                              │   - /app/errors      │
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

# 2. Ollama-Modelle ziehen (auf dem Ollama-Host)
ollama pull gemma4:e4b
ollama pull qwen3-embedding:4b
ollama pull qwen3:4b              # OCR-Korrektur (optional)
ollama pull qwen3-vl:4b           # Vision-OCR (optional)

# 3. Starten
docker compose up -d

# 4. GUI öffnen → Setup-Wizard führt durch die Ersteinrichtung
open http://localhost:8088
```

Weitere Optionen (selbst bauen, lokale Entwicklung): **[docs/installation.md](./docs/installation.md)**

## Modell-Empfehlungen (6GB VRAM)

**Empfohlen (Balanced/Qualitaet):**
- Klassifikation: `gemma4:e4b` (Default)
- Embeddings: `qwen3-embedding:4b`
- OCR text-only: `qwen3:4b`
- OCR vision: `qwen3-vl:4b` mit `OCR_MODE=vision_light`

**Alternative Klassifikationsmodelle (testen):**
- `qwen3:4b` (oft gut bei strukturierter Extraktion)
- `llama3.1:8b` (nur sinnvoll, wenn dein Host/Ollama-Offloading stabil laeuft)

> Wichtig: Wenn du `OLLAMA_EMBED_MODEL` oder `OLLAMA_EMBED_DIM` aenderst,
> fuehre danach einen **Full Reindex** aus, damit sqlite-vec mit der neuen
> Embedding-Dimension neu aufgebaut wird.

## Dokumentation

| Dokument | Beschreibung |
|---|---|
| **[Installation](./docs/installation.md)** | Quickstart, Docker-Setup, lokale Entwicklung |
| **[Konfiguration](./docs/configuration.md)** | Alle Umgebungsvariablen im Detail |
| **[Review-Workflow](./docs/workflow.md)** | Klassifikation, Review, Tag-Management |
| **[CLI Commands](./docs/cli.md)** | Manuelle Pipeline-Steuerung und Container-Reset |
| **[MCP Server](./docs/mcp.md)** | KI-Tools für Claude Code und andere Assistenten |
| **[Deployment](./docs/deployment.md)** | Dockhand, Reverse Proxy, Backup |
| **[Architektur](./docs/architecture.md)** | Datenfluss-Diagramme und System-Kontext |
| **[Webhooks](./docs/webhooks.md)** | Sofortige Verarbeitung statt Polling |

## Lizenz

MIT — siehe `LICENSE`.

---

Developed & maintained by [@pfriedrich84](https://github.com/pfriedrich84), AI‑assisted.
