# Agent-Sicherheitsregeln

Diese Datei sammelt Sicherheitsregeln fuer Coding-Agents in einem tool-neutralen Format.

## Erlaubte typische Aktionen

- Dateien lesen, suchen und gezielt bearbeiten.
- `ruff check ...` und `ruff format ...` ausfuehren.
- `pytest ...` ausfuehren.
- `git status`, `git diff`, `git log`, `git branch` zur Orientierung nutzen.
- `pip install ...`, `pip check ...` und `python scripts/check_dependency_age.py ...` fuer Dependency-Arbeiten nutzen.
- Projektdateien wie `.env.example` lesen.

## Verbotene / gefaehrliche Aktionen

- Keine Secrets aus `.env` ausgeben oder veraendern.
- Kein `rm -rf` auf Projekt- oder Datenverzeichnissen.
- Kein `git push --force`.
- Kein `git reset --hard`, ausser der Benutzer verlangt es ausdruecklich und bestaetigt Datenverlust.
- Keine Docker-Images oder Container loeschen (`docker rm`, `docker rmi`), ausser der Benutzer verlangt es ausdruecklich.

## Vor Commit / Abschluss

Vor einem Commit oder finaler Uebergabe mindestens passende Checks aus [`commands.md`](commands.md) ausfuehren. Fuer Python-Code normalerweise:

```bash
ruff check app/ tests/
ruff format --check app/ tests/
pytest tests/ -v
```
