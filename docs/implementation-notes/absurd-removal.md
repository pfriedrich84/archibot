# Retired queue transport removal and upgrade notes

Status: implemented and CI-validated. Laravel Database Queues are the sole productive transport.

## Final runtime

Laravel Database Queues are the only actor transport. `RunPythonActorJob` invokes the fixed allowlist in `App\Services\Actors\PythonActorRunner`, which launches `python -m app.actor_runner` with one durable Command, Pipeline Run or Webhook Delivery ID. Python actor modules expose plain functions and never register decorators, start workers or enqueue recovery work.

The same Laravel transport is used for manual review commits, webhook refresh/delete work, scheduled and manual polls, document runs, embedding builds/reindex, OCR reindex and source-linked recovery. Confidence cannot create a review commit; only an authorized manual review decision can create that queued Command.

## Clean install

The vendored queue SQL and its installation migration were deleted. A clean migration therefore creates no schema objects for the retired transport. Docker installs no retired SDK and Supervisor starts only Laravel web, database queue, scheduler and durable-recovery processes (plus the optional MCP server); there is no Python queue or recovery worker.

## Persistent-volume upgrade

An existing PostgreSQL volume may already contain the historical queue schema. The migration deliberately **leaves it inert** instead of dropping it during deployment:

- no current process, dependency, setting or migration reads or writes it;
- retaining it avoids destroying old queue evidence during an application upgrade;
- operators may export and remove it later under their own retention/change-control policy, after confirming rollback is no longer required.

Do not assume the current migration rollback command removes that historical schema: the installation migration no longer exists in the current image.

## Rollback

Stop the current queue worker, scheduler and recovery loop before rolling back the application. Redeploy an image from before the transport removal against the unchanged PostgreSQL volume before invoking any old migration rollback. On an upgraded volume, the retained migration record and inert schema let the older image see its original objects. On a database created after the removal, an older image will install its historical schema when its migrations run.

Dropping the historical schema is irreversible queue-state deletion and weakens this rollback path. Export it and obtain explicit operator approval before removal. Durable ArchiBot Commands, Pipeline Runs, Webhook Deliveries, Actor Executions and Laravel `jobs` remain the authoritative recovery state regardless of that historical queue state.

## Reintroduction guard

`scripts/check_pipeline_start_ownership.py` scans productive source, manifests, migrations, Docker/supervisor inputs, environment examples and extensionless scripts deny-by-default. It rejects the retired SDK/backend, Python event/recovery workers, old environment names and worker launch commands. Historical documentation and the canonical repository prohibition remain readable but are not runtime exceptions.
