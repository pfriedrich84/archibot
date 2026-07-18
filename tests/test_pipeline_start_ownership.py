from pathlib import Path

import pytest

from scripts.check_pipeline_start_ownership import (
    legacy_reference_fingerprints,
    load_legacy_fingerprint_baseline,
    productive_files,
    scan_php,
    scan_python,
    scan_repository,
    scan_retired_transport,
    scan_sqlite_product_state,
    scan_text,
)

ROOT = Path(__file__).resolve().parents[1]


def test_productive_pipeline_start_and_legacy_inventory_freeze_is_clean():
    violations, legacy_matches = scan_repository(ROOT)

    assert violations == []
    assert legacy_matches == load_legacy_fingerprint_baseline()


def test_step_10_guard_denies_new_files_requirements_and_renamed_legacy_schema(tmp_path):
    probes = {
        "app/new_store.py": "import sqlite3\nconnection = sqlite3.connect('/data/new-state.db')\n",
        "requirements-extra.txt": "sqlite-vec>=0.1.3\nAPScheduler>=3.10\n",
        "docker/renamed-worker.conf": "command=python /app/run.py --database=/data/renamed.db\n",
        "scripts/rebuild": "PRAGMA custom_future_setting=ON; CREATE TABLE archived_poll_cycles(id INTEGER);\n",
        "config/runtime.yaml": "repository: processed_documents\n",
        "manifest.json": '{"query": "CREATE TABLE IF NOT EXISTS suggestions (document_id INTEGER)"}\n',
        "workers/renamed-runtime": "dependency=absurd-sdk\ncommand=python -m app.event_worker start-workers\n",
        "requirements-queue.txt": "absurd_queue dependency\n",
        "config/queue.env": "ABSURD_QUEUE_URL=postgresql://queue\n",
        "scripts/import-worker": "import absurd_queue\n",
        "docker/supervisor/queue.conf": "command=/opt/AbsurdQueue/bin/absurd-worker\n",
        "manifest-queue.json": '{"transport": "absurd"}\n',
    }
    for relative, source in probes.items():
        path = tmp_path / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(source, encoding="utf-8")

    violations, _legacy_matches = scan_repository(tmp_path)
    violating_paths = {item.path for item in violations}

    assert set(probes) <= violating_paths
    assert {path.relative_to(tmp_path).as_posix() for path in productive_files(tmp_path)} >= set(
        probes
    )
    assert {item.rule for item in violations} >= {
        "SQLite runtime/API restored",
        "SQLite file-backed state restored",
        "SQLite-specific DDL restored",
        "legacy processed-document state restored",
        "legacy suggestions table restored",
        "retired SQLite vector dependency restored",
        "retired scheduler dependency restored",
        "retired queue transport restored",
        "retired Python recovery worker restored",
    }


def test_step_10_guard_exceptions_are_exact_path_and_exact_rule():
    assert scan_sqlite_product_state("laravel/phpunit.xml", 'DB_CONNECTION="sqlite"') == []
    assert scan_sqlite_product_state("new/phpunit.xml", 'DB_CONNECTION="sqlite"')
    assert scan_sqlite_product_state("app/framework-copy.php", "'driver' => 'sqlite'")

    # Test/framework files may spell their injected adapter, but cannot hide a
    # restored productive schema or retired dependency in the same file.
    phpunit_probe = 'DB_CONNECTION="sqlite"\nrepository=processed_documents\n'
    violations = scan_sqlite_product_state("laravel/phpunit.xml", phpunit_probe)
    assert {item.rule for item in violations} == {"legacy processed-document state restored"}


def test_legacy_freeze_rejects_extra_references_in_an_existing_file():
    baseline = load_legacy_fingerprint_baseline()
    relative = "AGENTS.md"
    original = (ROOT / relative).read_text(encoding="utf-8")

    extra_distinct = legacy_reference_fingerprints(
        relative, original + "\n# compatibility must not add classifier.db references\n"
    )
    extra_duplicate = legacy_reference_fingerprints(
        relative, original + "\n# retained processed_documents processed_documents\n"
    )
    extra_absurd = legacy_reference_fingerprints(
        relative, original + "\n# Adding any Absurd reference is frozen.\n"
    )

    for actual in (extra_distinct, extra_duplicate, extra_absurd):
        assert actual != baseline
        assert actual - baseline


@pytest.mark.parametrize(
    "source",
    [
        "import absurd_queue\n",
        "ABSURD_QUEUE_URL=postgresql://queue\n",
        "absurd_queue dependency\n",
        "AbsurdQueue\n",
        "absurd-queue\n",
        "/srv/queue/AbsUrD/worker\n",
        'transport = "absurd"\n',
    ],
)
def test_removed_transport_guard_rejects_case_insensitive_substring_variants(source):
    violations = scan_retired_transport("config/productive-probe", source)

    assert {item.rule for item in violations} == {"retired queue transport restored"}


def test_removed_transport_guard_policy_exception_is_exact_and_cannot_hide_same_file_bypass():
    policy_line = next(
        line
        for line in (ROOT / "AGENTS.md").read_text(encoding="utf-8").splitlines()
        if "absurd" in line.lower()
    )

    assert scan_retired_transport("AGENTS.md", policy_line) == []
    assert scan_retired_transport("config/AGENTS.md", policy_line)
    assert scan_retired_transport("AGENTS.md", policy_line + "\nABSURD_QUEUE_URL=x")
    assert scan_retired_transport("AGENTS.md", policy_line + " absurd_queue")


def test_removed_transport_guard_scanner_path_has_no_blanket_exception():
    violations = scan_retired_transport(
        "scripts/check_pipeline_start_ownership.py", "dependency = 'absurd_queue'"
    )

    assert {item.rule for item in violations} == {"retired queue transport restored"}


def test_python_structural_policy_rejects_direct_or_aliased_fenced_actor_execution():
    fixtures = [
        "from app.actors.document import _handle_document_pipeline_impl as run\nrun(42)\n",
        "from app.actors import document as actor\nactor._handle_document_pipeline_impl(42)\n",
        "import app.actors.embedding as actor\nrun = actor._build_initial_embedding_index_impl\nrun()\n",
        "import app.actors.document as actor\ngetattr(actor, supplied_name)(42)\n",
        "import app.actors.document\napp.actors.document._handle_document_pipeline_impl(42)\n",
    ]

    for source in fixtures:
        violations = scan_python("app/legacy_worker.py", source)
        assert {item.rule for item in violations} == {
            "fenced actor execution outside Laravel runner"
        }


def test_python_structural_policy_rejects_public_actor_runner_bypasses():
    fixtures = [
        "from app.actor_runner import run_document_pipeline as run\nrun(42)\n",
        "import app.actor_runner as runner\nrunner.run_reindex_command(7)\n",
        "import app.actor_runner as runner\ngetattr(runner, supplied_name)(42)\n",
    ]

    for source in fixtures:
        violations = scan_python("scripts/legacy_launch.py", source)
        assert {item.rule for item in violations} == {
            "fenced actor execution outside Laravel runner"
        }


def test_python_structural_policy_rejects_embedding_gate_transition_outside_actor():
    fixtures = [
        "from app.jobs.embedding_index import finish_embedding_index_build as finish\nfinish(1, status='complete')\n",
        "import app.jobs.embedding_index as state\nstate.start_embedding_index_build(embedding_model='x', dimensions=3, content_scope='x', document_count=0)\n",
    ]

    for source in fixtures:
        violations = scan_python("app/legacy_worker.py", source)
        assert {item.rule for item in violations} == {
            "embedding gate transition outside fenced actor"
        }


def test_python_structural_policy_rejects_raw_and_dynamic_pipeline_run_writes():
    raw = scan_python(
        "app/adversarial.py",
        """import sqlalchemy
sql = f"INSERT INTO {schema}.pipeline_runs (status) VALUES ('queued')"
connection.execute(sqlalchemy.text(sql))
""",
    )
    dynamic = scan_python(
        "scripts/adversarial.py",
        """table = supplied_table
cursor.execute(f"UPDATE {table} SET status = 'queued'")
""",
    )

    aliased = scan_python(
        "app/adversarial_alias.py",
        """target = supplied_table
sql = "DELETE FROM " + target + " WHERE id = :id"
renamed = sql
connection.execute(renamed, {"id": 1})
""",
    )

    assert {item.rule for item in raw} >= {"dynamic SQL write target"}
    assert {item.rule for item in dynamic} == {"dynamic SQL write target"}
    assert {item.rule for item in aliased} == {"dynamic SQL write target"}


def test_php_structural_policy_rejects_model_save_query_and_class_aliases():
    source = r"""<?php
use App\Models\PipelineRun as Run;
Run::query()->firstOrCreate(['id' => 1]);
$record = new Run;
$record->save();
$model = Run::class;
$model::updateOrCreate(['id' => 2], ['status' => 'queued']);
"""

    rules = {item.rule for item in scan_php("laravel/app/Adversarial.php", source)}

    assert "PipelineRun model write" in rules
    assert "PipelineRun new/save alias" in rules
    assert "dynamic model class write" in rules

    dynamic_method = scan_php(
        "laravel/app/AdversarialDynamic.php",
        "<?php use App\\Models\\PipelineRun as Run; Run::{$method}(['id' => 1]);",
    )
    assert {item.rule for item in dynamic_method} == {"dynamic PipelineRun model method"}


def test_php_structural_policy_rejects_dynamic_table_raw_sql_and_alias_chains():
    source = r"""<?php
$table = request('table');
DB::table($table)->insert(['status' => 'queued']);
DB::table('pipeline_'.$table)->upsert($rows, ['id']);
$builder = DB::table('pipeline_runs');
$alias = $builder;
$alias->upsert($rows, ['id']);
$sql = "INSERT INTO {$table} (status) VALUES ('queued')";
DB::statement($sql);
"""

    rules = {item.rule for item in scan_php("laravel/app/Adversarial.php", source)}

    assert "dynamic DB table write" in rules
    assert "pipeline_runs table alias write" in rules
    assert "dynamic raw SQL write" in rules


def test_non_language_runtime_config_cannot_hide_pipeline_run_insert_or_actor_launch():
    insert = scan_text(
        "docker/supervisord.conf",
        "command=/bin/sh -c \"psql -c 'INSERT INTO pipeline_runs(status) VALUES (queued)'\"",
    )
    launch = scan_text(
        "docker/worker.conf",
        "command=/usr/bin/python3 -m app.actor_runner process-document --pipeline-run-id 7",
    )

    assert {item.rule for item in insert} == {"pipeline_runs INSERT outside owner"}
    assert {item.rule for item in launch} == {"Python actor runner launch outside Laravel owner"}


def test_php_structural_policy_traces_container_query_fill_save_and_create_aliases():
    source = r"""<?php
use App\Models\PipelineRun as Run;
$containerRun = app(Run::class);
$copy = $containerRun;
$copy->fill($payload);
$save = 'save';
$copy->$save();
$found = Run::query()->find(7);
$found->forceFill($payload)->save();
$builder = Run::query();
$builderResult = $builder->findOrFail(9);
$builderResult->fill($payload)->save();
$create = 'create';
$builder->$create($payload);
$callableCreate = [$builder, 'create'];
$callableCreate($payload);
$modelClass = Run::class;
$modelClassAlias = $modelClass;
$resolved = resolve($modelClassAlias);
$resolved->fill($payload)->save();
resolve(Run::class)->fill($payload)->save();
Run::findOrFail(8)->fill($payload)->save();
"""

    rules = {item.rule for item in scan_php("laravel/app/Adversarial.php", source)}

    assert "PipelineRun instance/builder write" in rules
    assert "aliased PipelineRun write method" in rules
    assert "callable-aliased PipelineRun write" in rules
    assert "container-resolved PipelineRun write" in rules
    assert "PipelineRun query-result write" in rules


@pytest.mark.parametrize(
    "source",
    [
        # Exact reviewer probes.
        "<?php $run = PipelineRun::where('id', 1)->first(); $run->save();",
        "<?php $document->pipelineRuns()->create($payload);",
        "<?php $document->pipelineRuns()->save($run);",
        "<?php $run = Container::getInstance()->make(PipelineRun::class); $run->save();",
        # Retrieval, container, relationship and assignment-chain variants.
        "<?php $query = PipelineRun::whereKey(1); $alias = $query->firstOrFail(); $alias->forceFill($payload)->save();",
        "<?php PipelineRun::latest()->whereNotNull('id')->sole()->delete();",
        "<?php $class = PipelineRun::class; $run = Container::getInstance()->make($class); $copy = $run; $copy->saveOrFail();",
        "<?php $relation = $document->pipelineRuns(); $copy = $relation; $copy->createMany($rows);",
        "<?php $document->pipelineRuns()->where('kind', 'document')->saveMany($runs);",
        "<?php function mutate(PipelineRun $run) { $run->fill($payload)->save(); }",
        "<?php $run = custom_resolver(PipelineRun::class); $run->push();",
        "<?php function runs() { return $this->hasMany(PipelineRun::class); } $document->runs()->create($payload);",
    ],
)
def test_php_structural_policy_denies_all_pipeline_run_provenance_writes(source):
    violations = scan_php("laravel/app/Adversarial.php", source)

    assert violations, source
    assert any("PipelineRun" in item.rule for item in violations), source


@pytest.mark.parametrize(
    "source",
    [
        # Exact lifecycle-owner adversarial probes: none may create a run.
        "<?php use App\\Models\\PipelineRun; PipelineRun::create($payload);",
        "<?php use App\\Models\\PipelineRun; $run = new PipelineRun; $run->save();",
        "<?php use App\\Models\\PipelineRun; $run = app(PipelineRun::class); $run->save();",
        "<?php use App\\Models\\PipelineRun; $run = PipelineRun::query(); $run->create($payload);",
        "<?php use App\\Models\\PipelineRun; $run = PipelineRun::whereKey(1)->first(); $run->firstOrCreate($payload);",
        "<?php use App\\Models\\PipelineRun; $method = 'save'; $run = new PipelineRun; $run->$method();",
        "<?php use App\\Models\\PipelineRun; $document->pipelineRuns()->save(new PipelineRun);",
        "<?php use App\\Models\\PipelineRun; $run = PipelineRun::find(1); $run->replicate()->save();",
        "<?php use App\\Models\\PipelineRun; $run = PipelineRun::find(1); $copy = $run->newInstance(); $copy->saveQuietly();",
        "<?php use App\\Models\\PipelineRun; PipelineRun::query()->forceCreateQuietly($payload);",
        "<?php use App\\Models\\PipelineRun; $run = PipelineRun::query(); $run->createOrFirst($attributes);",
        # Factory APIs are creation-tainted by default, not maintained as a
        # finite denylist. These exact bypasses previously inherited persisted
        # provenance from query()/static calls.
        "<?php use App\\Models\\PipelineRun; PipelineRun::make($payload);",
        "<?php use App\\Models\\PipelineRun; $run = PipelineRun::make($payload); $run->save();",
        "<?php use App\\Models\\PipelineRun; PipelineRun::query()->make($payload);",
        "<?php use App\\Models\\PipelineRun; $query = PipelineRun::query(); $run = $query->make($payload); $run->save();",
        "<?php use App\\Models\\PipelineRun; PipelineRun::query()->newModelInstance($payload);",
        "<?php use App\\Models\\PipelineRun; $query = PipelineRun::query(); $run = $query->newModelInstance($payload); $run->save();",
        "<?php use App\\Models\\PipelineRun; PipelineRun::query()->newFromBuilder($payload);",
        "<?php use App\\Models\\PipelineRun; PipelineRun::query()->newInstance($payload);",
        "<?php use App\\Models\\PipelineRun; PipelineRun::query()->replicate();",
        "<?php use App\\Models\\PipelineRun; PipelineRun::query()->forceCreate($payload);",
        "<?php use App\\Models\\PipelineRun; PipelineRun::query()->createQuietly($payload);",
        "<?php use App\\Models\\PipelineRun; PipelineRun::query()->firstOrNew($attributes);",
        "<?php use App\\Models\\PipelineRun; PipelineRun::query()->firstOrCreate($attributes);",
        "<?php use App\\Models\\PipelineRun; PipelineRun::query()->updateOrCreate($attributes, $values);",
        "<?php use App\\Models\\PipelineRun; $run = PipelineRun::find(1); $copy = $run->newFromBuilder($payload); $copy->save();",
        "<?php use App\\Models\\PipelineRun; $run = PipelineRun::newFromBuilder($payload); $run->save();",
        "<?php use App\\Models\\PipelineRun; PipelineRun::newInstance($payload);",
        "<?php use App\\Models\\PipelineRun; PipelineRun::replicate();",
        "<?php use App\\Models\\PipelineRun; PipelineRun::forceCreate($payload);",
        "<?php use App\\Models\\PipelineRun; PipelineRun::createQuietly($payload);",
        "<?php use App\\Models\\PipelineRun; PipelineRun::firstOrNew($attributes);",
        "<?php use App\\Models\\PipelineRun; PipelineRun::firstOrCreate($attributes);",
        "<?php use App\\Models\\PipelineRun; PipelineRun::updateOrCreate($attributes, $values);",
        "<?php use App\\Models\\PipelineRun; $run = PipelineRun::futureFactory($payload); $run->save();",
        "<?php use App\\Models\\PipelineRun; $query = PipelineRun::query(); $run = $query->futureFactory($payload); $run->save();",
    ],
)
def test_lifecycle_owners_reject_every_pipeline_run_creation_sink(source):
    for owner in (
        "laravel/app/Http/Controllers/PipelineRunController.php",
        "laravel/app/Jobs/RunPythonActorJob.php",
        "laravel/app/Services/Actors/PythonActorRunner.php",
        "laravel/app/Services/Pipeline/PipelineRecoveryDispatcher.php",
    ):
        violations = scan_php(owner, source)
        assert violations, (owner, source)


@pytest.mark.parametrize(
    "source",
    [
        "<?php use App\\Models\\PipelineRun; function updateRun(PipelineRun $run) { $run->update($payload); }",
        "<?php use App\\Models\\PipelineRun; function updateRun(PipelineRun $run) { $run->updateQuietly($payload); }",
        "<?php use App\\Models\\PipelineRun; $run = PipelineRun::query()->lockForUpdate()->findOrFail(1); $alias = $run; $alias->update($payload);",
        "<?php use App\\Models\\PipelineRun; PipelineRun::query()->whereKey(1)->update($payload);",
        "<?php use App\\Models\\PipelineRun; $run = PipelineRun::find(1); $run->update($payload);",
        "<?php use App\\Models\\PipelineRun; $run = PipelineRun::whereKey(1)->firstOrFail(); $run->updateQuietly($payload);",
        "<?php use App\\Models\\PipelineRun; $query = PipelineRun::query()->where('status', 'queued'); $run = $query->first(); $run->update($payload);",
    ],
)
def test_lifecycle_owners_allow_only_existing_parameter_or_query_mutations(source):
    violations = scan_php("laravel/app/Jobs/RunPythonActorJob.php", source)

    assert violations == []


@pytest.mark.parametrize(
    "source",
    [
        # Exact get()->first() probes: collection retrieval and model extraction
        # must not launder fresh-model factories into lifecycle-owner saves.
        "<?php use App\\Models\\PipelineRun; PipelineRun::get()->first()->replicate()->save();",
        "<?php use App\\Models\\PipelineRun; PipelineRun::get()->first()->newInstance()->save();",
        "<?php use App\\Models\\PipelineRun; $run = PipelineRun::get()->first(); $copy = $run->replicate(); $copy->save();",
        "<?php use App\\Models\\PipelineRun; $run = PipelineRun::get()->first(); $copy = $run->newInstance(); $copy->save();",
        # Eager, cursor, lazy, transformed, alias, and index variants.
        "<?php use App\\Models\\PipelineRun; PipelineRun::all()->last()->replicate()->saveQuietly();",
        "<?php use App\\Models\\PipelineRun; PipelineRun::query()->cursor()->filter($fn)->first()->newInstance()->save();",
        "<?php use App\\Models\\PipelineRun; PipelineRun::query()->lazy()->map($fn)->sole()->make($payload)->save();",
        "<?php use App\\Models\\PipelineRun; $runs = PipelineRun::get(); $run = $runs->pop(); $run->replicate()->save();",
        "<?php use App\\Models\\PipelineRun; $runs = PipelineRun::cursor(); $run = $runs->shift(); $run->newInstance()->save();",
        "<?php use App\\Models\\PipelineRun; $runs = PipelineRun::all(); $run = $runs[0]; $run->replicate()->save();",
        "<?php use App\\Models\\PipelineRun; $run = PipelineRun::get()[0]; $run->newInstance()->save();",
        "<?php use App\\Models\\PipelineRun; $run = PipelineRun::get()->filter($fn)[0]; $run->make($payload)->save();",
        "<?php use App\\Models\\PipelineRun; PipelineRun::get()->replicate()->save();",
        "<?php use App\\Models\\PipelineRun; $runs = PipelineRun::lazy(); $runs->futureCollectionMacro()->save();",
        # Unknown collection transforms conservatively retain PipelineRun taint.
        "<?php use App\\Models\\PipelineRun; $runs = PipelineRun::get(); $changed = $runs->futureCollectionMacro(); $run = $changed->first(); $run->newInstance()->save();",
    ],
)
def test_lifecycle_owners_reject_collection_factory_bypasses(source):
    for owner in (
        "laravel/app/Http/Controllers/PipelineRunController.php",
        "laravel/app/Jobs/RunPythonActorJob.php",
        "laravel/app/Services/Actors/PythonActorRunner.php",
        "laravel/app/Services/Pipeline/PipelineRecoveryDispatcher.php",
    ):
        violations = scan_php(owner, source)
        assert violations, (owner, source)


@pytest.mark.parametrize(
    "source",
    [
        "<?php use App\\Models\\PipelineRun; $run = PipelineRun::get()->first(); $run->update($payload);",
        "<?php use App\\Models\\PipelineRun; $run = PipelineRun::all()->last(); $run->updateQuietly($payload);",
        "<?php use App\\Models\\PipelineRun; $run = PipelineRun::cursor()->sole(); $run->update($payload);",
        "<?php use App\\Models\\PipelineRun; $run = PipelineRun::lazy()->firstOrFail(); $run->update($payload);",
        "<?php use App\\Models\\PipelineRun; $runs = PipelineRun::get(); $run = $runs->pop(); $run->update($payload);",
        "<?php use App\\Models\\PipelineRun; $runs = PipelineRun::all(); $run = $runs->shift(); $run->update($payload);",
        "<?php use App\\Models\\PipelineRun; $runs = PipelineRun::get(); $run = $runs[0]; $run->update($payload);",
        "<?php use App\\Models\\PipelineRun; $run = PipelineRun::get()[0]; $run->update($payload);",
        "<?php use App\\Models\\PipelineRun; $run = PipelineRun::lazy()->filter($fn)[0]; $run->update($payload);",
    ],
)
def test_lifecycle_owners_preserve_existing_collection_model_provenance(source):
    violations = scan_php("laravel/app/Jobs/RunPythonActorJob.php", source)

    assert violations == []


@pytest.mark.parametrize(
    "source",
    [
        # Exact reviewer foreach/destructuring probes and variants. Factory
        # calls and instance persistence are independently forbidden.
        "<?php use App\\Models\\PipelineRun; foreach (PipelineRun::get() as $run) { $run->replicate(); }",
        "<?php use App\\Models\\PipelineRun; $runs = PipelineRun::get(); foreach ($runs as $run) { $run->newInstance(); }",
        "<?php use App\\Models\\PipelineRun; foreach (PipelineRun::cursor() as $key => $run) { $run->make($payload); }",
        "<?php use App\\Models\\PipelineRun; foreach (PipelineRun::lazy() as [$run, $other]) { $run->newFromBuilder($payload); }",
        "<?php use App\\Models\\PipelineRun; [$run] = PipelineRun::get(); $run->replicate();",
        "<?php use App\\Models\\PipelineRun; list($run, $other) = PipelineRun::all(); $run->newInstance();",
        "<?php use App\\Models\\PipelineRun; $runs = PipelineRun::get(); [$run] = $runs; $run->save();",
        "<?php use App\\Models\\PipelineRun; $runs = PipelineRun::get(); foreach ($runs as $run) { $run->saveQuietly(); }",
        "<?php use App\\Models\\PipelineRun; foreach (PipelineRun::cursor() as $run) { $run->push(); }",
        "<?php use App\\Models\\PipelineRun; foreach (PipelineRun::lazy() as $run) { $copy = clone $run; }",
        "<?php use App\\Models\\PipelineRun; $runs = PipelineRun::all(); [$run] = $runs; $copy = clone $run;",
    ],
)
def test_lifecycle_owners_reject_foreach_destructuring_factory_clone_and_save(source):
    for owner in (
        "laravel/app/Http/Controllers/PipelineRunController.php",
        "laravel/app/Jobs/RunPythonActorJob.php",
        "laravel/app/Services/Actors/PythonActorRunner.php",
        "laravel/app/Services/Pipeline/PipelineRecoveryDispatcher.php",
    ):
        assert scan_php(owner, source), (owner, source)


@pytest.mark.parametrize(
    "method",
    [
        "replicate",
        "newInstance",
        "make",
        "newModelInstance",
        "newFromBuilder",
        "create",
        "forceCreate",
        "firstOrCreate",
        "updateOrCreate",
    ],
)
def test_pipeline_run_factories_are_rejected_without_a_save_or_provenance_inference(method):
    sources = [
        f"<?php use App\\Models\\PipelineRun; PipelineRun::{method}($payload);",
        f"<?php use App\\Models\\PipelineRun; PipelineRun::query()->{method}($payload);",
        f"<?php use App\\Models\\PipelineRun; $run = PipelineRun::find(1); $run->{method}($payload);",
    ]
    for source in sources:
        assert scan_php("laravel/app/Jobs/RunPythonActorJob.php", source), source


@pytest.mark.parametrize(
    "method",
    [
        "replicate",
        "newInstance",
        "make",
        "newModelInstance",
        "newFromBuilder",
        "create",
        "forceCreate",
        "createQuietly",
        "firstOrCreate",
        "updateOrCreate",
        "save",
        "saveQuietly",
        "saveOrFail",
        "push",
        "pushQuietly",
    ],
)
def test_lifecycle_owner_sink_invariant_is_lexical_and_receiver_independent(method):
    source = f"<?php $unrelatedReceiver->{method}($payload);"

    for owner in (
        "laravel/app/Http/Controllers/PipelineRunController.php",
        "laravel/app/Jobs/RunPythonActorJob.php",
        "laravel/app/Services/Actors/PythonActorRunner.php",
        "laravel/app/Services/Pipeline/PipelineRecoveryDispatcher.php",
    ):
        rules = {item.rule for item in scan_php(owner, source)}
        assert "lexical creation-semantic method forbidden in lifecycle owner" in rules, (
            owner,
            source,
        )


@pytest.mark.parametrize(
    "source",
    [
        "<?php use App\\Models\\PipelineRun; PipelineRun::get()->each(function ($run): void { $run->replicate(); });",
        "<?php use App\\Models\\PipelineRun; PipelineRun::get()->map(function ($run) { return $run->newInstance(); });",
        "<?php use App\\Models\\PipelineRun; PipelineRun::get()->filter(function ($run): bool { $run->make($payload); return true; });",
        "<?php use App\\Models\\PipelineRun; PipelineRun::get()->reduce(function ($carry, $run) { $run->save(); return $carry; }, null);",
        "<?php use App\\Models\\PipelineRun; PipelineRun::cursor()->each(fn ($run) => $run->newFromBuilder($payload));",
        "<?php use App\\Models\\PipelineRun; PipelineRun::lazy()->map(fn ($run) => $run->saveQuietly());",
        "<?php use App\\Models\\PipelineRun; PipelineRun::all()->filter(fn ($run) => $run->push());",
        "<?php use App\\Models\\PipelineRun; PipelineRun::get()->reduce(fn ($carry, $run) => $run->updateOrCreate($payload), null);",
    ],
)
def test_lifecycle_owners_reject_exact_collection_callback_probes(source):
    for owner in (
        "laravel/app/Http/Controllers/PipelineRunController.php",
        "laravel/app/Jobs/RunPythonActorJob.php",
        "laravel/app/Services/Actors/PythonActorRunner.php",
        "laravel/app/Services/Pipeline/PipelineRecoveryDispatcher.php",
    ):
        rules = {item.rule for item in scan_php(owner, source)}
        assert "lexical creation-semantic method forbidden in lifecycle owner" in rules, (
            owner,
            source,
        )


@pytest.mark.parametrize(
    "source",
    [
        "<?php $object->$method($payload);",
        "<?php $object->{$method}($payload);",
        "<?php $object->$methods['write']($payload);",
        "<?php $model::$method($payload);",
        "<?php $model::update($payload);",
        "<?php PipelineRun::$method($payload);",
        "<?php call_user_func([$object, $method], $payload);",
        "<?php call_user_func_array([PipelineRun::class, 'update'], [$payload]);",
        "<?php forward_static_call([$model, 'find'], 1);",
        "<?php $callback = [$object, 'update'];",
        "<?php $callback = [PipelineRun::class, $method];",
        "<?php $callback = [self::class, 'handle'];",
        "<?php $callback = [$object, Handler::METHOD];",
        "<?php $callback = array($object, 'update');",
    ],
)
def test_lifecycle_owners_reject_dynamic_method_and_callable_variants(source):
    for owner in (
        "laravel/app/Http/Controllers/PipelineRunController.php",
        "laravel/app/Jobs/RunPythonActorJob.php",
        "laravel/app/Services/Actors/PythonActorRunner.php",
        "laravel/app/Services/Pipeline/PipelineRecoveryDispatcher.php",
    ):
        rules = {item.rule for item in scan_php(owner, source)}
        assert "dynamic method/callable forbidden in lifecycle owner" in rules, (owner, source)


@pytest.mark.parametrize(
    "source",
    [
        # Exact reviewer probe: string concatenation plus variable invocation.
        "<?php $method = 'cre'.'ate'; $callable = [PipelineRun::class, $method]; $callable($payload);",
        "<?php $method = 'up'.('date');",
        "<?php $handler = 'PipelineHandler@handle';",
        "<?php $callable = 'PipelineRun::update';",
        "<?php $callable = 'App\\\\Models\\\\Pipeline'.'Run::forceCreate';",
        "<?php $callable = ('Pipeline'.'Run').'::saveQuietly';",
        "<?php $callable = PipelineRun::class.'::'.'update';",
        "<?php $callable = PipelineRun::class.$separator.$method;",
        "<?php $function = 'trim'; $function($payload);",
        "<?php $callbacks['success']($payload);",
        "<?php ${$callbackName}($payload);",
        # Exact braced-indirect reviewer probe and quote/expression variants.
        "<?php ${'name'}($payload);",
        '<?php ${"name"}($payload);',
        "<?php ${expr}($payload);",
        "<?php ${$callbacks['success']}($payload);",
        "<?php {$callback}($payload);",
        "<?php { $callbacks['success'] }($payload);",
        "<?php {$handler->callback}($payload);",
        "<?php {${expr}}($payload);",
        "<?php $$callback($payload);",
        "<?php $callbacks{0}($payload);",
        "<?php app(Handler::class)($payload);",
        "<?php (resolve(Handler::class))($payload);",
        "<?php $reflection = new ReflectionMethod(PipelineRun::class, 'update'); $reflection->invoke($run, $payload);",
        "<?php $reflection = new ReflectionMethod('Pipeline'.'Run', 'cre'.'ate'); $reflection->invokeArgs(null, [$payload]);",
        "<?php $reflection = new ReflectionFunction($function); $reflection->invoke($payload);",
        "<?php forward_static_call([PipelineRun::class, 'update'], $payload);",
        "<?php forward_static_call_array(['Pipeline'.'Run', 'create'], [$payload]);",
        "<?php eval('$run->update($payload);');",
        "<?php assert('$run->save()');",
        "<?php \\assert($condition);",
    ],
)
def test_lifecycle_owners_reject_callable_string_reflection_forward_and_eval_variants(source):
    for owner in (
        "laravel/app/Http/Controllers/PipelineRunController.php",
        "laravel/app/Jobs/RunPythonActorJob.php",
        "laravel/app/Services/Actors/PythonActorRunner.php",
        "laravel/app/Services/Pipeline/PipelineRecoveryDispatcher.php",
    ):
        assert scan_php(owner, source), (owner, source)


@pytest.mark.parametrize("depth", range(1, 11))
def test_lifecycle_owners_reject_generated_nested_braced_indirect_calls(depth):
    owners = (
        "laravel/app/Http/Controllers/PipelineRunController.php",
        "laravel/app/Jobs/RunPythonActorJob.php",
        "laravel/app/Services/Actors/PythonActorRunner.php",
        "laravel/app/Services/Pipeline/PipelineRecoveryDispatcher.php",
    )
    probes = (
        # `{$` is nested under depth-1 additional brace pairs before invocation.
        "<?php " + "{" * depth + "$callback" + "}" * depth + "($payload);",
        # Every `${` token is forbidden, independently of whether parsing the
        # nested expression would eventually identify an invocation.
        "<?php $" + "{" * depth + "$callbacks['success']" + "}" * depth + "($payload);",
    )

    for owner in owners:
        for source in probes:
            rules = {item.rule for item in scan_php(owner, source)}
            assert "variable function/invokable container forbidden in lifecycle owner" in rules, (
                owner,
                depth,
                source,
            )


@pytest.mark.parametrize(
    "source",
    [
        "<?php // ${not_executable_but_still_forbidden}\n",
        "<?php $message = '${also_forbidden}';",
    ],
)
def test_lifecycle_owners_reject_every_dollar_brace_token_anywhere(source):
    for owner in (
        "laravel/app/Http/Controllers/PipelineRunController.php",
        "laravel/app/Jobs/RunPythonActorJob.php",
        "laravel/app/Services/Actors/PythonActorRunner.php",
        "laravel/app/Services/Pipeline/PipelineRecoveryDispatcher.php",
    ):
        rules = {item.rule for item in scan_php(owner, source)}
        assert "variable function/invokable container forbidden in lifecycle owner" in rules


def test_lifecycle_owner_invokes_only_named_typed_internal_completion_closures():
    audited = r"""<?php
use Closure;
private function runProcess(
    ?Closure $onFailure = null,
    ?Closure $onSuccess = null,
): void {
    if ($onFailure !== null) { $onFailure('failed'); }
    if ($onSuccess !== null) { $onSuccess(); }
}
"""
    owner = "laravel/app/Services/Actors/PythonActorRunner.php"
    assert scan_php(owner, audited) == []

    rejected = [
        "<?php function runProcess(?callable $onFailure) { $onFailure('failed'); }",
        "<?php function runProcess(?Closure $callback) { $callback(); }",
        "<?php function other(?Closure $onFailure) { $onFailure('failed'); }",
        "<?php $onSuccess();",
        # Even an allowlisted name is indirect when reached through braces,
        # an expression, variable-variable syntax, or an array lookup.
        "<?php private function runProcess(?Closure $onFailure): void { ${'onFailure'}('failed'); }",
        '<?php private function runProcess(?Closure $onSuccess): void { ${"onSuccess"}(); }',
        "<?php private function runProcess(?Closure $onFailure): void { ${onFailure}('failed'); }",
        "<?php private function runProcess(?Closure $onFailure): void { {$onFailure}('failed'); }",
        "<?php private function runProcess(?Closure $onSuccess): void { $$onSuccess(); }",
        "<?php private function runProcess(?Closure $onSuccess): void { $onSuccess[0](); }",
        "<?php private function runProcess(?Closure $onSuccess): void { $onSuccess{0}(); }",
    ]
    for source in rejected:
        rules = {item.rule for item in scan_php(owner, source)}
        assert "variable function/invokable container forbidden in lifecycle owner" in rules, source


@pytest.mark.parametrize(
    "method",
    [
        "forceCreateQuietly",
        "createMany",
        "createOrFirst",
        "incrementOrCreate",
        "prefixCreateSuffix",
        "bulk_insert_rows",
        "upsertDocuments",
        "persistChanges",
        "storeResult",
        "autoSaveState",
        "pushState",
        "replicateForRetry",
        "newInstanceFromPayload",
        "newModelForRun",
        "makeCopy",
    ],
)
def test_lifecycle_owners_reject_normalized_creation_semantics_and_future_variants(method):
    sources = [f"<?php $service->{method}($payload);", f"<?php Service::{method}($payload);"]

    for owner in (
        "laravel/app/Http/Controllers/PipelineRunController.php",
        "laravel/app/Jobs/RunPythonActorJob.php",
        "laravel/app/Services/Actors/PythonActorRunner.php",
        "laravel/app/Services/Pipeline/PipelineRecoveryDispatcher.php",
    ):
        for source in sources:
            rules = {item.rule for item in scan_php(owner, source)}
            assert "lexical creation-semantic method forbidden in lifecycle owner" in rules, (
                owner,
                source,
            )


def test_pipeline_run_controller_allows_only_reviewed_non_mutating_pagination_calls():
    owner = "laravel/app/Http/Controllers/PipelineRunController.php"
    safe = """<?php
$validated = $request->validate(['per_page' => ['integer']]);
$runs = PipelineRun::query()->paginate($validated['per_page'])->withQueryString()->through($presenter);
"""
    assert scan_php(owner, safe) == []

    for method in ("create", "save", "persistChanges", "upsertDocuments"):
        assert scan_php(owner, f"<?php $runs->{method}($payload);"), method


def test_lifecycle_owner_keeps_literal_audited_service_calls_and_denies_unknown_model_methods():
    safe_calls = {
        "laravel/app/Http/Controllers/PipelineRunController.php": "<?php $this->audit($request); $run->update($payload);",
        "laravel/app/Jobs/RunPythonActorJob.php": "<?php $runner->runReindex($command); $run->update($payload);",
        "laravel/app/Services/Actors/PythonActorRunner.php": "<?php $process->run(); $run->update($payload);",
        "laravel/app/Services/Pipeline/PipelineRecoveryDispatcher.php": "<?php $this->recordActorRecoveryEvent($execution); $run->update($payload);",
    }
    for owner, source in safe_calls.items():
        assert scan_php(owner, source) == [], (owner, source)

    unknown_model_calls = [
        "<?php use App\\Models\\PipelineRun; PipelineRun::futureFactory($payload);",
        "<?php use App\\Models\\PipelineRun; PipelineRun::query()->futureFactory($payload);",
    ]
    for source in unknown_model_calls:
        assert scan_php("laravel/app/Jobs/RunPythonActorJob.php", source), source


def test_lifecycle_owner_rejects_instance_persistence_but_allows_typed_and_query_update():
    forbidden = [
        "<?php use App\\Models\\PipelineRun; function f(PipelineRun $run) { $run->save(); }",
        "<?php use App\\Models\\PipelineRun; $run = PipelineRun::find(1); $run->saveQuietly();",
        "<?php use App\\Models\\PipelineRun; PipelineRun::whereKey(1)->first()->push();",
    ]
    for source in forbidden:
        assert scan_php("laravel/app/Jobs/RunPythonActorJob.php", source), source

    allowed = [
        "<?php use App\\Models\\PipelineRun; function f(PipelineRun $run) { $run->update($payload); }",
        "<?php use App\\Models\\PipelineRun; PipelineRun::query()->whereKey(1)->update($payload);",
    ]
    for source in allowed:
        assert scan_php("laravel/app/Jobs/RunPythonActorJob.php", source) == [], source


def test_php_structural_policy_rejects_query_builder_helper_alias():
    source = r"""<?php
function hiddenWriter() {
    return DB::table('pipeline_runs');
}
hiddenWriter()->insertOrIgnore(['status' => 'queued']);
"""

    rules = {item.rule for item in scan_php("laravel/app/Adversarial.php", source)}

    assert "pipeline_runs helper alias write" in rules
