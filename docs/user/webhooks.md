# Webhook-Konfiguration

Anleitung zur Einrichtung von Webhooks, damit Paperless-NGX ArchiBot sofort nach dem Einlesen oder Aendern eines Dokuments benachrichtigt — als Alternative oder Ergaenzung zum Polling.

## Ueberblick

Bei `POLL_INTERVAL_SECONDS > 0` pollt der Worker die Inbox regelmaessig. Mit Webhooks wird die Verarbeitung sofort ausgeloest, ohne auf einen Poll zu warten.

**Empfohlener Webhook-Endpoint:**

| Endpoint | Zweck |
|----------|-------|
| `POST /webhook` | Paperless-Dokumentereignis empfangen, Delivery speichern, deduplizieren und Verarbeitung einreihen |

`/webhook` ist der einfache Alias fuer den event-driven Endpoint. `/api/webhooks/paperless` ist der kanonische event-driven Endpoint und bleibt kompatibel. Legacy-Endpoints `/webhook/new` und `/webhook/edit` sind nicht mehr Ziel der Architektur und duerfen fuer neue Setups nicht verwendet werden.

**Polling und Webhooks koennen parallel laufen.** Der Idempotenz-Check verhindert, dass ein Dokument doppelt verarbeitet wird.

## Voraussetzungen

- Paperless-NGX >= 2.0
- Der ArchiBot-Container muss fuer Paperless erreichbar sein (gleiches Docker-Netzwerk oder Netzwerk-Route)
- Optional, aber empfohlen: ein Webhook-Secret fuer Authentifizierung

## 1. ArchiBot konfigurieren

In der `.env`:

```env
# Webhook-Secret (empfohlen). Leerer String = keine Authentifizierung.
WEBHOOK_SECRET=mein-geheimer-webhook-token
```

Alternativ kann fuer den event-driven Endpoint `PAPERLESS_WEBHOOK_SECRET` gesetzt werden. Die Authentifizierung greift nur, wenn ein Secret gesetzt ist.

## 2. Paperless-NGX konfigurieren

Paperless-NGX unterstuetzt Workflow-Webhooks direkt in der GUI — kein Script und keine Datei-Mounts noetig.

**Ein Webhook fuer alle relevanten Dokument-Events:**

| Trigger | Webhook-URL | Zweck |
|---------|-------------|-------|
| Dokument hinzugefuegt und Dokument geaendert | `http://<host>:8088/webhook` | Delivery speichern, deduplizieren und Verarbeitung einreihen |

Du kannst in Paperless einen Workflow mit mehreren Triggern oder zwei Workflows mit derselben URL konfigurieren. ArchiBot erkennt das Ereignis aus dem Payload.

**Einstellungen pro Workflow:**

- **Aktionstyp:** Webhook
- **Webhook-URL:** `http://<host>:8088/webhook`
- **Webhook-Payload als JSON senden:** AN
- **Dokument einbeziehen:** AN (optional — ArchiBot holt das Dokument sowieso via API)
- **Webhook-Kopfzeilen:** `X-Webhook-Secret: <WEBHOOK_SECRET>` (wenn konfiguriert)

**Payload-Format** (wird automatisch von Paperless gesendet):

```json
{
  "event": "document_created",
  "object": {
    "id": 123,
    "correspondent": "Example Corp",
    "document_type": "Invoice",
    "storage_path": null,
    "tags": [1, 5],
    "created": "2026-04-14",
    "content": "...raw text content...",
    "mime_type": "application/pdf",
    "filename": "2026-04-14_example_corp.pdf"
  }
}
```

Der Endpoint akzeptiert sowohl dieses Workflow-Format als auch das Legacy-Format:

```json
{"document_id": 123}
```

## 3. Netzwerk-Setup

Der ArchiBot-Container muss fuer Paperless ueber das Netzwerk erreichbar sein.

### Gleicher Docker-Compose-Stack

Wenn Paperless und ArchiBot im selben `docker-compose.yml` laufen, koennen sie sich ueber den Service-Namen erreichen:

```text
http://archibot:8088/webhook
```

### Separate Docker-Compose-Stacks

Wenn Paperless und ArchiBot in unterschiedlichen Stacks laufen, muessen sie ein gemeinsames Docker-Netzwerk teilen.

**Im ArchiBot `docker-compose.yml`:**

```yaml
services:
  archibot:
    networks:
      - paperless

networks:
  paperless:
    external: true
    name: ix-paperless-ngx_default   # Name des Paperless-Netzwerks
```

### Verschiedene Hosts

Wenn Paperless und ArchiBot auf verschiedenen Maschinen laufen, muss der ArchiBot-Port (default: 8088) erreichbar sein:

```text
http://classifier-host:8088/webhook
```

> **Hinweis:** Die Webhook-Endpoints sind nicht durch Basic-Auth geschuetzt. Die Authentifizierung erfolgt ueber den `X-Webhook-Secret` Header.

## Webhook-Referenz

### POST /webhook — empfohlener Paperless-Webhook

Empfaengt Paperless-Dokumentereignisse, speichert sie in `webhook_deliveries`, dedupliziert identische Lieferungen und reiht die Verarbeitung ein.

**Header:**

| Header | Wert | Pflicht? |
|---|---|---|
| `Content-Type` | `application/json` | Ja |
| `X-Webhook-Secret` | Wert von `WEBHOOK_SECRET` oder `PAPERLESS_WEBHOOK_SECRET` | Nur wenn ein Secret gesetzt ist |

**Body** (Workflow-Format oder Legacy-Format):

```json
{"event": "document_created", "object": {"id": 123}}
```

```json
{"document_id": 123}
```

**Responses:**

| Status | Bedeutung |
|---|---|
| `200` | Webhook-Delivery wurde gespeichert oder als Duplikat erkannt |
| `403` | Webhook-Secret ungueltig |
| `422` | Body ungueltig (fehlende/falsche `document_id`) |
| `5xx` | Delivery wurde ggf. gespeichert, aber die nachgelagerte Einreihung ist fehlgeschlagen; Paperless soll den Webhook erneut versuchen |

### Kompatibilitaets-Endpoints

`POST /api/webhooks/paperless` ist derselbe event-driven Handler wie `/webhook` und der kanonische Endpoint fuer neue Setups.

`POST /webhook/new` und `POST /webhook/edit` sind Legacy-Endpoints und koennen entfernt werden. Sie duerfen nicht als neuer Integrationspunkt dokumentiert oder erweitert werden.

## Fehlerbehebung

### Webhook kommt nicht an

1. **Netzwerk pruefen:** Kann Paperless ArchiBot erreichen?
   ```bash
   # Aus dem Paperless-Container heraus testen:
   docker exec paperless curl -s http://archibot:8088/healthz
   # Erwartete Antwort: {"status":"ok"}
   ```

2. **Workflow-Logs pruefen:** Hat Paperless den Webhook gesendet?
   ```bash
   docker logs paperless 2>&1 | grep -i "webhook"
   ```

3. **Webhook-Delivery-Liste pruefen:** Neue Setups mit `/webhook` erscheinen in ArchiBot unter Webhook Deliveries.

### 403 Forbidden

Das Webhook-Secret stimmt nicht ueberein. Pruefen:

- `WEBHOOK_SECRET` / `PAPERLESS_WEBHOOK_SECRET` in der ArchiBot `.env`
- `X-Webhook-Secret` Header in den Workflow-Kopfzeilen
- Keine Leerzeichen oder Zeilenumbrueche im Secret

### Dokument wird nicht verarbeitet

- Ist die Webhook-Delivery in ArchiBot sichtbar?
- Hat das Dokument den Inbox-Tag (`PAPERLESS_INBOX_TAG_ID`)?
- Wurde es bereits verarbeitet? Der Idempotenz-Check kann doppelte Arbeit verhindern.
- Button "Reprocess" in der Inbox-GUI erzwingt erneute Verarbeitung.

## Webhook vs. Polling

| | Polling | Webhook |
|---|---|---|
| **Latenz** | Bis zu `POLL_INTERVAL_SECONDS` | Sofort nach Consume |
| **Zuverlaessigkeit** | Sehr hoch (holt alles nach) | Abhaengig von Netzwerk |
| **Setup** | Keine Konfiguration noetig | Workflow in Paperless noetig |
| **Empfehlung** | Aktiv als Fallback | Zusaetzlich fuer schnelle Reaktion |

**Empfohlenes Setup:** Beides aktiviert. Der Webhook sorgt fuer sofortige Verarbeitung, der Poll dient als Sicherheitsnetz falls ein Webhook verloren geht.
