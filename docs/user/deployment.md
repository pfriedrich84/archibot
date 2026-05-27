# Deployment

Anleitungen fuer verschiedene Deployment-Szenarien.

## Docker Compose (Standard)

Siehe [Installation](./installation.md) fuer die grundlegende Einrichtung.

Das Image wird automatisch ueber GitHub Container Registry bereitgestellt:

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

Datei- und Konfigurationsdaten liegen in `DATA_DIR` (Default: `/data`), das als Docker-Volume
gemountet werden sollte. Die App-Datenbank liegt im PostgreSQL-Volume:

```yaml
volumes:
  - classifier-data:/data
```

### Persistente Daten

| Ort | Beschreibung |
|---|---|
| PostgreSQL-Volume `archibot_postgres` | App-Datenbank (Sessions, Settings, Review Queue, Audit, MCP-Tokens, Embeddings) |
| `DATA_DIR/laravel/app_key` | Persistenter Laravel-App-Key fuer verschluesselte Secrets |
| `DATA_DIR/config.env` | Legacy-Settings, die beim ersten Laravel-Setup einmalig importiert werden |
| `DATA_DIR/config.bak.*` | Automatische Backups von config.env |

### Backup

Fuer ein vollstaendiges Backup genuegt es, das `DATA_DIR`-Volume zu sichern:

```bash
# Docker-Volume-Backup
docker run --rm -v classifier-data:/data -v $(pwd):/backup \
  alpine tar czf /backup/classifier-backup.tar.gz -C /data .
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
| Classifier → Paperless | HTTP | API-Zugriff (Dokumente, Metadaten) |
| Classifier → AI-Provider | HTTP | LLM-Inference (Chat, Embedding) via Ollama oder OpenAI-kompatiblem Endpoint |
| Classifier → Telegram | HTTPS | Bot-API (optional, Long-Polling) |
| Browser → Classifier | HTTP | Web-GUI (Port 8088) |
| Paperless → Classifier | HTTP | Webhook (optional, Port 8088) |
