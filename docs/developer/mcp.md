# MCP Server

Der optionale MCP-Prozess bleibt als Integrationspunkt vorhanden, registriert aktuell aber **keine Tools oder Resources**. Alle 24 frueheren Tools und beide Resources wurden inventarisiert und bis zu einem vollstaendigen, berechtigungsgebundenen Laravel/PostgreSQL-Seam retired. Die Entscheidung und Rueckkehrkriterien fuer jede Registrierung stehen in der [MCP disposition matrix](../implementation-notes/mcp-disposition-matrix.md).

## Sicherheits- und Datenmodell

- MCP-Startup initialisiert oder liest keine `classifier.db` und startet keinen privilegierten globalen Paperless-/AI-Client.
- Direkte Paperless-Reads gelten nicht als Ersatz fuer den geforderten Laravel/PostgreSQL-Datenseam.
- Klassifikation, Dokumentupdates, Review-/Entity-Mutationen, Suche, Retrieval, Suggestions, Systemstatus und Summary-Resources sind nicht registriert.
- Eine spaetere dokumentbezogene Operation muss vor Inhalt oder Mutation einen frisch verifizierten Laravel-MCP-Benutzer und dessen Live-Paperless-Berechtigung pruefen und geschlossen fehlschlagen.
- Spaetere Mutationen muessen dieselben durable Command/Review/Pipeline-/Audit-Pfade wie die Laravel-UI verwenden.
- Spaetere Reads muessen PostgreSQL-backed, berechtigungsgefiltert und redigiert sein.
- Identitaetslose FastMCP-Resources bleiben unregistriert.

## Aktivierung und Transport

Der Prozess kann weiterhin fuer Integrations- und Rueckkehrtests gestartet werden:

```bash
ENABLE_MCP=true
MCP_LARAVEL_AUTH_ENABLED=true
MCP_LARAVEL_PATH=/app/laravel
```

`MCP_TRANSPORT` unterstuetzt `stdio`, `sse` und `streamable-http`; `MCP_HOST` und `MCP_PORT` konfigurieren Netzwerktransporte. Da keine Registrierungen exponiert werden, sind `MCP_ENABLE_WRITE`, `MCP_API_KEY` und `MCP_CLASSIFY_RATE_LIMIT` fuer das produktive MCP-Verhalten inert. Sie werden in einem spaeteren Konfigurations-Cleanup entfernt.

Der vorhandene Auth-Guard akzeptiert fuer zukuenftige Seams ausschliesslich einen durch `php artisan archibot:mcp-token-verify` verifizierten Benutzerkontext mit verknuepftem Paperless-Token. Statische Keys oder unauthentifizierter stdio-Modus duerfen keine zukuenftige Registrierung freischalten.
