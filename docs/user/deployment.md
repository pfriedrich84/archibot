# Deployment

Anleitungen fuer verschiedene Deployment-Szenarien.

## Docker Compose (Standard)

Siehe [Installation](./installation.md) fuer die grundlegende Einrichtung. Der Standard-Stack startet den ArchiBot-App-Container zusammen mit PostgreSQL/pgvector. Der event-driven Queue-Pfad wird auf Laravel Database Queues mit festen Python-Actor-Kommandos migriert und benoetigt keinen separaten Broker-Service. Paperless-NGX und der AI-Provider (Ollama-kompatibel oder OpenAI-kompatibler `/v1`-Endpoint) laufen weiterhin extern oder in verbundenen Compose-Netzwerken.

Das ArchiBot-App-Image wird automatisch ueber GitHub Container Registry bereitgestellt:

```
ghcr.io/pfriedrich84/archibot:latest
```

### Verfuegbare Tags

| Tag | Beschreibung |
|---|---|
| `latest` | Aktueller Stand von `main` |
| `v0.1.0`, `v0.1` | Versionierte Releases |
| `sha-<hash>` | Spezifischer Commit |

## Deployment via Dockhand

Fuer Dockhand-basierte Setups (z.B. Homelab mit zentraler Stack-Verwaltung):

1. **Repo vorbereiten** — Privates GitHub-Repo `pfriedrich84/archibot`
2. **Deploy Key** — SSH Deploy Key in GitHub hinterlegen (read-only)
3. **Dockhand konfigurieren:**
   - Settings → Git → Repo hinzufuegen
   - Stacks → Create from Git → Compose Path: `docker-compose.yml`
4. **Env-Datei bereitstellen** — Auf dem Docker-Host anlegen:
   ```bash
   mkdir -p /opt/stacks/archibot
   # .env mit allen Variablen anlegen:
   nano /opt/stacks/archibot/.env
   ```
   In Dockhand: External Env File → `/opt/stacks/archibot/.env`
5. **Auto-Sync** — Aktivieren oder Webhook fuer automatische Updates einrichten

### Reverse Proxy

Der Classifier laeuft hinter Zoraxy (oder einem anderen Reverse Proxy).
Keine Ports direkt gegen das Internet freigeben.

Die Web-GUI wird von Laravel/Svelte direkt auf Port `8088` ausgeliefert. Authentifizierung erfolgt ueber Paperless-NGX-Login und Laravel-Sessions; die fruehere globale GUI-Basic-Auth gibt es nicht mehr.

## Persistente Daten

Datei- und Konfigurationsdaten liegen in `DATA_DIR` (Default: `/data`) im Compose-Volume `archibot_data`. Die App-Datenbank liegt in einem eigenen Volume:

```yaml
volumes:
  archibot_data:
  archibot_postgres:
```

### Persistente Daten

| Ort | Beschreibung |
|---|---|
| PostgreSQL-Volume `archibot_postgres` | App-Datenbank (Sessions, Settings, Review Queue, Pipeline Runs/Events, Audit, MCP-Tokens, Embeddings) |
| App-Volume `archibot_data` / `DATA_DIR` | App-Key, Logs, Custom Prompts und importierte Legacy-Konfiguration |
| `DATA_DIR/laravel/app_key` | Persistenter Laravel-App-Key fuer verschluesselte Secrets |
| `DATA_DIR/config.env` | Legacy-Settings, die beim ersten Laravel-Setup einmalig importiert werden |
| `DATA_DIR/config.bak.*` | Automatische Backups von config.env |

### Backup

Fuer ein vollstaendiges Backup muessen `archibot_data` und `archibot_postgres` gesichert werden. PostgreSQL sollte per Dump oder mit gestopptem Stack auf Volume-Ebene gesichert werden:

```bash
# App-Daten (/data)
docker run --rm -v archibot_data:/data -v $(pwd):/backup \
  alpine tar czf /backup/archibot-data.tar.gz -C /data .

# PostgreSQL-Dump
docker exec archibot-postgres pg_dump -U archibot archibot > archibot-postgres.sql

```

### Reset

Container-State zuruecksetzen: siehe [CLI-Dokumentation](../developer/cli.md#reset). Der Operator-Befehl `archibot reset` bleibt gueltig; er delegiert im Container an den Laravel/PostgreSQL-Reset.

```bash
# Nur DB zuruecksetzen
docker exec archibot archibot reset --yes

# Voller Factory-Reset (inkl. Config)
docker exec archibot archibot reset --yes --include-config
```

## Netzwerk-Anforderungen

| Verbindung | Richtung | Beschreibung |
|---|---|---|
| ArchiBot App → Paperless | HTTP | API-Zugriff (Dokumente, Metadaten) |
| ArchiBot App → AI-Provider | HTTP | Strukturierte LLM-Klassifikation/OCR/Judge und Embeddings via Ollama oder OpenAI-kompatiblem Endpoint; Chat/RAG ist deaktiviert ([Issue #221](https://github.com/pfriedrich84/archibot/issues/221)) |
| ArchiBot App → PostgreSQL | TCP 5432 | App-State, pgvector Embeddings, Pipeline-/Audit-Tabellen |
| Browser → ArchiBot App | HTTP | Web-GUI (Port 8088) |
| Paperless → ArchiBot App | HTTP | Webhook (optional, Port 8088) |
