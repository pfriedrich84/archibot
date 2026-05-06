# Agent Checks

Relevante Checks vor Abschluss von Code-Aenderungen.

## Python

```bash
ruff check app/ tests/
ruff format --check app/ tests/
pytest tests/ -v
```

## Laravel / Frontend

Im Ordner `laravel/`:

```bash
COMPOSER_ALLOW_SUPERUSER=1 composer test
npm run lint:check
npm run format:check
npm run types:check
npm run build
```

Wenn du nicht als root arbeitest, nutze `composer test` ohne `COMPOSER_ALLOW_SUPERUSER=1`.

## Auswahlregel

- Python-Code geaendert: Python-Checks ausfuehren.
- Laravel/PHP/Svelte/TypeScript geaendert: Laravel-/Frontend-Checks ausfuehren.
- Docker/CI/Dependencies geaendert: passende CI-/Dependency-Checks aus [`commands.md`](commands.md) ergaenzen.
- Nur Dokumentation geaendert: normalerweise keine Tests noetig; trotzdem Links und betroffene Beispiele pruefen.
