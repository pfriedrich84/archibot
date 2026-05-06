# Agent Commands und Workflows

Diese Datei sammelt wiederverwendbare Workflows fuer Coding-Agents.

## Pre-Commit-Checks

Fuehre vor groesseren Aenderungen oder vor einem Commit aus:

1. `ruff check app/ tests/`
2. `ruff format --check app/ tests/`
3. `pytest tests/ -v`

Falls Lint oder Format fehlschlagen:

1. `ruff format app/ tests/`
2. `ruff check --fix app/ tests/`
3. Danach erneut `ruff check app/ tests/` und `ruff format --check app/ tests/`

Am Ende kurz zusammenfassen:

- Welche Checks bestanden / fehlgeschlagen sind
- Ob Auto-Fixes angewendet wurden
- Ob der Code commit-ready ist

## Lokale CI-Simulation

Fuehre die CI-Checks in der Reihenfolge aus `.github/workflows/ci.yml` aus:

### Python

1. `ruff check app/ tests/`
2. `ruff format --check app/ tests/`
3. `pytest tests/ -v`
4. `pip check`
5. `pip-audit --skip-editable` mit den Ausnahmen aus `.pip-audit-known-vulnerabilities`
6. `python scripts/check_dependency_age.py --min-days 3`
7. `archibot --help`
8. `python -m app.cli --help`
9. `python -c "import app.cli; import app.mcp_server"`
10. `python -c "from app.db import init_db; init_db()"`
11. `python -c "from app.pipeline.classifier import _load_system_prompt; assert len(_load_system_prompt()) > 100"`

### Laravel / Frontend

Im Ordner `laravel/`:

1. `COMPOSER_ALLOW_SUPERUSER=1 composer test` falls als root ausgefuehrt wird, sonst `composer test`
2. `npm run lint:check`
3. `npm run format:check`
4. `npm run types:check`
5. `npm run build`

Falls ein Check fehlschlaegt: die verbleibenden Checks nach Moeglichkeit trotzdem ausfuehren, damit eine vollstaendige Uebersicht entsteht.

## Dependency-Update mit 3-Tage-Regel

Argument: Paketname, optional mit Zielversion.

1. Version ermitteln, falls keine Zielversion angegeben ist:

   ```bash
   curl -s https://pypi.org/pypi/<paket>/json | python3 -c "import sys,json; print(json.load(sys.stdin)['info']['version'])"
   ```

2. Upload-Datum pruefen:

   ```bash
   curl -s https://pypi.org/pypi/<paket>/<version>/json | python3 -c "import sys,json; print(json.load(sys.stdin)['urls'][0]['upload_time'])"
   ```

3. Falls juenger als 3 Tage: abbrechen, ausser es handelt sich um einen CVE-Fix. Dann `.dependency-age-allowlist` mit CVE und Ablaufdatum aktualisieren.
4. Obergrenze anheben:
   - Direkte Dependency: `pyproject.toml`
   - Transitive Dependency: `constraints.txt`
5. Installieren: `pip install -c constraints.txt -e ".[dev]"`
6. Checks ausfuehren:
   - `ruff check app/ tests/`
   - `ruff format --check app/ tests/`
   - `pytest tests/ -v`
   - `python scripts/check_dependency_age.py --min-days 3`
7. Zusammenfassen, was geaendert wurde und ob alle Checks bestanden haben.

Nicht automatisch committen, sofern nicht explizit verlangt.
