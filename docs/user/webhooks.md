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
- Erforderlich: ein nicht leeres, instanzspezifisches Webhook-Secret fuer Authentifizierung. Ohne effektives Secret lehnt ArchiBot jeden Webhook fail-closed ab.

## 1. ArchiBot konfigurieren

Empfohlen: In ArchiBot als globale Admin-Einstellung `webhook.secret` unter `/admin/settings` pflegen. Das Secret ist bewusst nicht benutzerbezogen: Paperless ruft ArchiBot maschinell auf, ohne ArchiBot-Benutzersitzung.

Alternativ fuer Deployment-/Bootstrap-Kompatibilitaet in der `.env`:

```bash
# Ausgabe direkt in einen geschuetzten Passwortmanager uebernehmen, nicht ins Shell-Log kopieren.
openssl rand -hex 32
```

```env
# Webhook-Secret (erforderlich; durch den zufaellig erzeugten Wert ersetzen).
PAPERLESS_WEBHOOK_SECRET=<generate-a-unique-random-secret>
PAPERLESS_WEBHOOK_MAX_BYTES=262144
PAPERLESS_WEBHOOK_RATE_LIMIT_PER_MINUTE=60
```

`WEBHOOK_SECRET` bleibt ein Deployment-Fallback, falls `PAPERLESS_WEBHOOK_SECRET` nicht gesetzt ist. Eine nicht leere globale Admin-Einstellung `webhook.secret` hat Vorrang. Ein leerer gespeicherter Wert ueberschreibt eine gueltige Deployment-Konfiguration nicht. Fehlt das effektive Secret oder fehlt/stimmt der `X-Webhook-Secret` Header nicht, wird vor Payload-Parsing und Persistenz mit `403` abgelehnt.

ArchiBot prueft zusaetzlich den deklarierten und den tatsaechlichen Roh-Body vor dem Parsing. Der sichere Default ist 262144 Bytes; der Middleware-Reader liest hoechstens Limit plus ein Byte und spult den Stream vor dem Controller sicher zurueck. Groessere oder nicht sicher les-/rueckspulbare Requests erhalten `413`, auch bei fehlendem oder falschem `Content-Length`. Danach zaehlt jeder Ingressversuch — auch mit fehlendem/falschem Secret oder aktivem Development-Bypass — auf ein stabiles, gehashtes Client-IP-Limit. Standardmaessig sind es 60 Requests pro Minute ueber beide Aliase gemeinsam; `429` enthaelt `Retry-After` und wird nicht persistiert.

Setze vorgelagerte Limits als Defense-in-depth ebenfalls: begrenze den Request-Body fuer genau diese beiden Pfade im Reverse Proxy/Webserver und richte PHPs `post_max_size` passend zum Betriebsbedarf ein. Das vorgelagerte Limit sollte nicht groesser als noetig sein und mit `PAPERLESS_WEBHOOK_MAX_BYTES` abgestimmt werden; beachte, dass ein kleineres Proxy-/PHP-Limit seine eigene `413`-Antwort ausloesen kann. Diese Grenzen ersetzen das ArchiBot-Limit nicht, weil Deployments Proxies umgehen oder anders konfigurieren koennen.

Nur fuer isolierte lokale Entwicklung kann `PAPERLESS_WEBHOOK_DEVELOPMENT_BYPASS=true` gesetzt werden. Der Bypass funktioniert ausschliesslich bei `APP_ENV=local` oder `development`, ist standardmaessig aus und bleibt in `production` sowie `testing` trotz Flag geschlossen. Bei aktivem Bypass erscheinen eine Startup-Warnung im Log und eine Warnung in den Admin-Einstellungen. Er ist kein produktiver Betriebsmodus.

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
- **Webhook-Parameter:** mindestens `document_url` mit Wert `{{ doc_url }}`; empfohlen zusaetzlich `event` passend zum Workflow-Trigger
- **Webhook-Kopfzeilen:** `X-Webhook-Secret: <WEBHOOK_SECRET>` (erforderlich)

Paperless-NGX Workflow-Webhooks senden nicht automatisch eine ArchiBot-kompatible Dokument-ID. Die Workflow-Webhook-Parameter werden mit Paperless' Workflow-Platzhaltern gerendert. In aktuellen Paperless-NGX-Versionen gibt es keinen nackten `{{ id }}`-Platzhalter; verfuegbar ist aber `{{ doc_url }}` mit der Dokument-URL. ArchiBot kann daraus die Dokument-ID lesen.

Wenn Paperless einen leeren Body sendet, akzeptiert ArchiBot den Webhook als Polling-Hinweis: Die Delivery wird gespeichert und eine Poll-Reconciliation wird eingereiht. Das ist robuster fuer Default-Workflows, aber weniger praezise als ein Payload mit `document_url`.

**Empfohlene Webhook-Parameter fuer einen "Dokument hinzugefuegt"-Workflow:**

| Key | Value |
|---|---|
| `event` | `document_created` |
| `document_url` | `{{ doc_url }}` |

Mit **Payload als JSON senden** ergibt das:

```json
{"event":"document_created","document_url":"https://paperless.example/documents/123/"}
```

**Empfohlene Webhook-Parameter fuer einen "Dokument geaendert"-Workflow:**

| Key | Value |
|---|---|
| `event` | `document_updated` |
| `document_url` | `{{ doc_url }}` |

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
| `X-Webhook-Secret` | Wert der globalen ArchiBot-Einstellung `webhook.secret`, sonst `PAPERLESS_WEBHOOK_SECRET` oder `WEBHOOK_SECRET` | Erforderlich; fehlendes effektives Secret und fehlender/falscher Header werden fail-closed abgelehnt |

**Body** (Workflow-Format oder Legacy-Format):

```json
{"event": "document_created", "object": {"id": 123}}
```

```json
{"document_id": 123}
```

```json
{"document_url": "https://paperless.example/documents/123/"}
```

**Responses:**

| Status | Bedeutung |
|---|---|
| `200` | Webhook-Delivery wurde gespeichert oder als Duplikat erkannt |
| `403` | Effektives Webhook-Secret fehlt oder Header ist ungueltig |
| `413` | Deklarierter oder tatsaechlicher Request-Body ueberschreitet das Limit |
| `429` | Client-Rate-Limit fuer beide Webhook-Aliase ist erschoepft |
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

### Upgrade bestehender Installationen ohne Secret

Der Wechsel von einer aelteren, bereits eingerichteten Installation ohne Webhook-Secret ist eine koordinierte Migration. Nach dem Upgrade oeffnet ein fehlender Wert den Endpoint nicht mehr, sondern liefert absichtlich `403`.

1. Plane vor dem Upgrade ein begrenztes Wartungs- und Rollbackfenster und pausiere die Paperless-Webhook-Workflows. Lasse die 600-Sekunden-Reconciliation als Auffangnetz aktiv.
2. Erzeuge ein instanzspezifisches Secret und hinterlege es geschuetzt. Trage denselben Wert fuer ArchiBot (`PAPERLESS_WEBHOOK_SECRET` vor dem Rollout oder danach `webhook.secret` in den Admin-Einstellungen) und fuer den Paperless-Header `X-Webhook-Secret` vor, ohne ihn in Tickets oder Logs zu kopieren.
3. Rolle ArchiBot mit dem konfigurierten Secret aus. Der Setup-Wizard bleibt bei noch nicht eingerichteten Installationen gesperrt, bis ein generierter Wert eingegeben wurde; bekannte Beispiel-Platzhalter gelten als fehlend.
4. Sende bei weiterhin pausiertem produktivem Workflow einen synthetischen Test. Verifiziere `200` und genau eine Delivery; ein falscher Header muss `403` ohne Delivery ergeben.
5. Aktiviere den aktualisierten Paperless-Workflow und beobachte `403`, `413`, `429` und `5xx`, bevor du das Wartungsfenster schliesst.
6. Begrenzter Rollback: pausiere Paperless erneut und rolle nur innerhalb des geplanten Fensters Anwendung und Konfiguration gemeinsam auf die vorige Version zurueck. Stelle niemals den alten fail-open Betrieb oeffentlich bereit; halte den Endpoint waehrend des Rollbacks per Netzwerkregel geschlossen und verlasse dich voruebergehend auf Poll-Reconciliation. Wiederhole nach erneutem Rollout den synthetischen Test.

### Secret sicher rotieren

Plane ein kurzes Wartungs- und Rollbackfenster, weil ArchiBot absichtlich nur ein effektives Secret gleichzeitig akzeptiert:

1. Erzeuge den neuen Wert und bewahre den bisherigen Wert bis zum Abschluss im Passwortmanager auf.
2. Pausiere den Paperless-Workflow oder plane ein kurzes Fenster, in dem Polling verpasste Ereignisse abfaengt.
3. Setze zuerst das neue Secret in ArchiBot (`webhook.secret` oder Deployment-Konfiguration) und starte bei Deployment-Aenderungen ArchiBot neu.
4. Sende einen synthetischen Test-Webhook mit dem neuen Header; pruefe `200` und genau eine neue Webhook Delivery. Pruefe ausserdem, dass der alte Wert `403` liefert und keine Delivery erzeugt.
5. Aktualisiere unmittelbar danach den Paperless-Workflow und reaktiviere ihn. Beobachte `403`, `413`, `429` und `5xx` waehrend des begrenzten Fensters.
6. Rollback-Reihenfolge: Paperless kurz pausieren, in ArchiBot den bisherigen Wert wiederherstellen, einen Test mit dem bisherigen Wert ausfuehren, dann Paperless auf den bisherigen Header zurueckstellen und reaktivieren.
7. Nach erfolgreichem Ablauf den alten Wert widerrufen/loeschen und das Wartungsfenster schliessen. Verpasste Ereignisse werden durch die 600-Sekunden-Reconciliation nachgeholt.

Gib weder alten noch neuen Wert in Logs, Responses, Tickets oder Screenshots aus.

### Leerer Payload

Wenn Paperless einen leeren Body sendet, speichert ArchiBot die Delivery und reiht eine Poll-Reconciliation ein. In Webhook Deliveries steht dann `webhook_action = poll_reconciliation`. Das ist ein Fallback fuer Paperless-Default-Webhooks ohne Parameter.

Fuer bessere Latenz und eindeutige Zuordnung trotzdem empfohlen:

- Paperless **Parameter verwenden** aktivieren
- Paperless **Payload als JSON senden** aktivieren
- Paperless Parameter `document_url` mit Wert `{{ doc_url }}` senden

### 422 Unprocessable Content

ArchiBot konnte keine Dokument-ID aus einem nicht-leeren Payload lesen. Pruefen:

- Paperless sendet einen Parameter `document_url` mit Wert `{{ doc_url }}`, oder eine direkt numerische `document_id`
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
