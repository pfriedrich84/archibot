# SQLite disposition

Status: implemented and CI-validated. Productive SQLite processing has been removed. This inventory classifies every intentional SQLite reference that remains after productive SQLite processing was deleted.

## Product/runtime classification

ArchiBot retains **no productive SQLite state and no runtime SQLite cache**. PostgreSQL owns setup, queues, sessions/cache tables, pipeline state, review suggestions, entity approvals, OCR corrections, audit/error history and polling state; pgvector owns vector search. Python has no local database path, schema initialization, migration, processing repository, vector/search repository, suggestion repository or poll-cycle repository. The runtime image therefore does not install `sqlite-vec` or PHP's SQLite extension.

Laravel's stock `config/database.php` still contains the framework-provided `sqlite` connection definition, and `laravel/database/.gitignore` still excludes local `*.sqlite` files. These are development-framework capabilities, not selected ArchiBot product state or a retained cache. Product startup fails closed unless Laravel selects `pgsql` and Python's `DATABASE_URL` uses a PostgreSQL SQLAlchemy scheme; the same validation applies to `config.env` overrides. Actor runner, event worker, operator CLI, MCP server lifecycle and the container entry point cannot select SQLite through a runtime compatibility flag.

## Test-only and guard references

- The Laravel test job and `laravel/phpunit.xml` use a fresh, ephemeral SQLite database. CI installs `sqlite3`/`pdo_sqlite` only for this isolated test process. It is test infrastructure, contains fixtures only, and is never mounted or shipped as product state.
- Product actor SQL in `app/jobs/actor_execution.py` is PostgreSQL-only. Laravel's process integration fixture (`laravel/tests/Fixtures/production_actor_process.py`) explicitly injects an ephemeral test engine and a module-local SQL adapter after loading PostgreSQL-only product configuration. That fixture is confined to the test tree and requires Laravel's SQLite test environment; no product actor, CLI, server or environment switch imports or selects it.
- Python tests and scanner fixtures may spell retired module/table/database names to prove structural guards reject their reintroduction. The repository-wide deny-by-default scan covers productive source, configuration, manifests, root scripts, Docker and supervisor inputs, including extensionless and newly named files. Its exact-path exceptions suppress only the SQLite adapter vocabulary required by PHPUnit/framework test infrastructure; all legacy schema and dependency rules still apply inside those files.
- Accepted ADRs, migration histories and implementation notes retain historical SQLite wording where needed to explain the decision and deletion. Active architecture and user behavior do not describe SQLite as an available backend.

There is no unrelated bounded SQLite cache to retain. Laravel's application cache uses the PostgreSQL `cache` and `cache_locks` tables; OCR corrections use PostgreSQL `document_ocr_corrections`.

## Upgrade, rollback and persistent volumes

The removal intentionally does not delete an existing `/data/classifier.db` during startup or reset. On upgrade the file is inert: current code neither opens nor modifies it, and `archibot reset` delegates only to Laravel's PostgreSQL-owned `archibot:reset` service. This preserves downgrade and forensic/export options instead of mutating an operator's persistent volume implicitly.

Before upgrading, operators who need historical legacy processing/suggestion/audit data must stop the old worker, take a verified backup/export of `classifier.db`, and retain it with the upgrade record. PostgreSQL rows created by the migrated pipeline are not backported into that file. An older rollback image cannot understand newer PostgreSQL pipeline state and may see only its old file, so workers must remain stopped until the rollback/export decision is explicit. After the new PostgreSQL path is validated and rollback is no longer required, operators may archive or remove the inert file manually according to their retention policy.

A clean installation creates no `classifier.db` and no legacy processing tables. Existing PostgreSQL persistent-volume migration and poll-candidate export-first rollback notes remain in the [Pipeline Start inventory](pipeline-start-caller-inventory.md#retention-and-rollback-safety).
