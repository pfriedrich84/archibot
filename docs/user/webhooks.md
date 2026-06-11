# Webhook-Konfiguration

Anleitung zur Einrichtung von Webhooks, damit Paperless-NGX ArchiBot sofort nach dem Einlesen oder Aendern eines Dokuments benachrichtigt — als Alternative oder Ergaenzung zum Polling.

## Ueberblick

Bei `POLL_INTERVAL_SECONDS > 0` pollt der Worker die Inbox regelmaessig. Mit Webhooks wird die Verarbeitung sofort ausgeloest, ohne auf einen Poll zu warten.

**Empfohlener Webhook-Endpoint:**

| Endpoint | Zweck |
|----------|-------|
| `POST /api/webhooks/paperless` | Paperless-Dokumentereignis empfangen, Delivery speichern, deduplizieren und Verarbeitung einreihen |
| `POST /webhook` | Einfacher Alias fuer denselben event-driven Endpoint |

`/api/webhooks/paperless` ist der kanonische event-driven Endpoint. `/webhook` bleibt als kurzer Alias verfuegbar.

ArchiBot unterscheidet Paperless-Events automatisch:

- `document_created`/`created`/`added`/`consumed` startet die volle Klassifikations-Pipeline.
- `document_updated`/`changed`/`modified` aktualisiert nur das pgvector-Embedding und startet keine neue Klassifikation.
- `document_deleted`/`trashed` entfernt gespeicherte Embeddings fuer das Dokument.

**Polling und Webhooks koennen parallel laufen.** Der Idempotenz-Check verhindert, dass ein Dokument doppelt verarbeitet wird.

## Voraussetzungen

- Paperless-NGX >= 2.0
- Der ArchiBot-Container muss fuer Paperless erreichbar sein (gleiches Docker-Netzwerk oder Netzwerk-Route)
- Optional, aber empfohlen: ein Webhook-Secret fuer Authentifizierung

## 1. ArchiBot konfigurieren

Empfohlen: In ArchiBot als globale Admin-Einstellung `webhook.secret` unter `/admin/settings` pflegen. Das Secret ist bewusst nicht benutzerbezogen: Paperless ruft ArchiBot maschinell auf, ohne ArchiBot-Benutzersitzung.

Alternativ fuer Deployment-/Bootstrap-Kompatibilitaet in der `.env`:

```env
# Webhook-Secret (empfohlen). Leerer String = keine Authentifizierung.
WEBHOOK_SECRET=mein-geheimer-webhook-token
```

Fuer den event-driven Endpoint kann auch `PAPERLESS_WEBHOOK_SECRET` gesetzt werden. Die globale Admin-Einstellung hat Vorrang vor den Deployment-Umgebungsvariablen. Die Authentifizierung greift nur, wenn ein Secret gesetzt ist.

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
- **Parameter verwenden:** AN
- **Webhook-Payload als JSON senden:** AN
- **Dokument einbeziehen:** AUS (Paperless haengt sonst die Datei als Multipart-Anhang an; ArchiBot holt das Dokument selbst per API)
- **Webhook-Parameter:** mindestens `document_id` mit Wert `{{id}}`; empfohlen zusaetzlich `event` passend zum Workflow-Trigger
- **Webhook-Kopfzeilen:** `X-Webhook-Secret: <WEBHOOK_SECRET>` (wenn konfiguriert)

Paperless-NGX Workflow-Webhooks senden nicht automatisch eine ArchiBot-kompatible Dokument-ID. Laut Paperless-NGX Workflow-Dokumentation sendet die Webhook-Aktion einen POST an die URL; Body/Parameter und Header muessen explizit konfiguriert werden und duerfen Workflow-Platzhalter verwenden. Fuer praezise pro-Dokument-Verarbeitung benoetigt ArchiBot daher einen JSON-Body mit Dokument-ID.

Wenn Paperless einen leeren Body sendet, akzeptiert ArchiBot den Webhook als Polling-Hinweis: Die Delivery wird gespeichert und eine Poll-Reconciliation wird eingereiht. Das ist robuster fuer Default-Workflows, aber weniger praezise als ein Payload mit `document_id`.

**Empfohlene Webhook-Parameter fuer einen "Dokument hinzugefuegt"-Workflow:**

| Key | Value |
|---|---|
| `event` | `document_created` |
| `document_id` | `{{id}}` |

Mit **Payload als JSON senden** ergibt das:

```json
{"event":"document_created","document_id":123}
```

**Empfohlene Webhook-Parameter fuer einen "Dokument geaendert"-Workflow:**

| Key | Value |
|---|---|
| `event` | `document_updated` |
| `document_id` | `{{id}}` |

Der Endpoint akzeptiert ausserdem diese JSON-Formen:

```json
{"event":"document_created","object":{"id":123}}
```

```json
{"event":"document_created","document":123}
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

Empfaengt Paperless-Dokumentereignisse, speichert sie in `webhook_deliveries`, dedupliziert identische Lieferungen und reiht je nach Event entweder die volle Dokumentverarbeitung oder nur eine Embedding-Aktualisierung ein.

**Header:**

| Header | Wert | Pflicht? |
|---|---|---|
| `Content-Type` | `application/json` | Ja |
| `X-Webhook-Secret` | Wert der globalen ArchiBot-Einstellung `webhook.secret`, sonst `WEBHOOK_SECRET` oder `PAPERLESS_WEBHOOK_SECRET` | Nur wenn ein Secret gesetzt ist |

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

### Kompatibilitaets-Endpoint

`POST /webhook` ist derselbe event-driven Handler wie `/api/webhooks/paperless` und bleibt als kurzer Alias verfuegbar.

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

- `webhook.secret` in ArchiBot `/admin/settings` bzw. `WEBHOOK_SECRET` / `PAPERLESS_WEBHOOK_SECRET` in der ArchiBot `.env`
- `X-Webhook-Secret` Header in den Workflow-Kopfzeilen
- Keine Leerzeichen oder Zeilenumbrueche im Secret

### Leerer Payload

Wenn Paperless einen leeren Body sendet, speichert ArchiBot die Delivery und reiht eine Poll-Reconciliation ein. In Webhook Deliveries steht dann `webhook_action = poll_reconciliation`. Das ist ein Fallback fuer Paperless-Default-Webhooks ohne Parameter.

Fuer bessere Latenz und eindeutige Zuordnung trotzdem empfohlen:

- Paperless **Parameter verwenden** aktivieren
- Paperless **Payload als JSON senden** aktivieren
- Paperless Parameter `document_id` mit Wert `{{id}}` senden

### 422 Unprocessable Content

ArchiBot konnte keine Dokument-ID aus einem nicht-leeren Payload lesen. Pruefen:

- Paperless sendet einen Parameter `document_id` mit Wert `{{id}}`
- `X-Webhook-Secret` ist ein Header, nicht Parameter

Fehlerhafte nicht-leere Webhook-Aufrufe werden als `failed_permanent` in ArchiBot unter Webhook Deliveries gespeichert, damit Payload und Header diagnostiziert werden koennen.

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
