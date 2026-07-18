#!/usr/bin/env python3
"""Structural freeze for Laravel-owned Pipeline Start and retired runtimes."""

from __future__ import annotations

import ast
import hashlib
import json
import re
import sys
from collections import Counter
from dataclasses import dataclass
from pathlib import Path

START_OWNER = "laravel/app/Services/Pipeline/DocumentPipelineStarter.php"
PYTHON_ACTOR_OWNER = "app/actor_runner.py"
PYTHON_LAUNCH_OWNER = "laravel/app/Services/Actors/PythonActorRunner.php"
PYTHON_EMBEDDING_TRANSITION_OWNER = "app/actors/embedding.py"
PYTHON_FENCED_LIFECYCLE_SQL_OWNERS = {
    "app/jobs/actor_execution.py",
    "app/jobs/commands.py",
    "app/jobs/pipeline_runs.py",
    "app/jobs/progress.py",
    "app/jobs/webhook_delivery.py",
}
PHP_REVIEWED_DYNAMIC_SQL_OWNERS = {
    # Closed migration loops interpolate only the three literal durable source
    # table/foreign-key pairs declared in the migration itself.
    "laravel/database/migrations/2026_07_19_000000_add_actor_execution_fencing.php",
}

LEGACY_FINGERPRINT_BASELINE = Path(__file__).with_name("pipeline_start_legacy_fingerprints.json")

LEGACY_PATTERN = re.compile(
    r"app\.db|app\.vector_store|app\.pipeline\.(?:document_processing|committer)|"
    r"app\.absurd_queue|\babsurd\b|absurd[-_]sdk|LegacyPythonState|classifier\.db|"
    r"processed_documents|doc_embeddings|doc_embedding_meta|"
    r"(?:FROM|INTO|UPDATE|JOIN|DELETE\s+FROM)\s+suggestions\b",
    re.IGNORECASE,
)
MUTATORS = (
    "create|createQuietly|forceCreate|forceCreateQuietly|createMany|createManyQuietly|"
    "firstOrCreate|createOrFirst|updateOrCreate|incrementOrCreate|save|saveQuietly|"
    "saveOrFail|saveMany|saveManyQuietly|push|pushQuietly|update|updateQuietly|delete|forceDelete|"
    "insert|insertGetId|insertOrIgnore|insertUsing|upsert"
)
CREATION_MUTATORS = (
    "create|createQuietly|forceCreate|forceCreateQuietly|createMany|createManyQuietly|"
    "firstOrCreate|createOrFirst|updateOrCreate|incrementOrCreate|save|saveQuietly|"
    "saveOrFail|saveMany|saveManyQuietly|push|pushQuietly|insert|insertGetId|"
    "insertOrIgnore|insertUsing|upsert"
)
CREATION_ONLY_MUTATORS = (
    "create|createQuietly|forceCreate|forceCreateQuietly|createMany|createManyQuietly|"
    "firstOrCreate|createOrFirst|updateOrCreate|incrementOrCreate|saveMany|"
    "saveManyQuietly|insert|insertGetId|insertOrIgnore|insertUsing|upsert"
)
EXISTING_MODEL_SAVE_MUTATORS = "save|saveQuietly|saveOrFail|push|pushQuietly"
# Lifecycle owners get a deliberately closed Eloquent vocabulary. Static calls
# not listed here are factories by default; builder calls not listed here lose
# persisted provenance by default. Instance save/push is forbidden entirely,
# preventing new framework/model factories from gaining a persistence escape.
PIPELINE_RUN_STATIC_QUERY_ROOTS = {
    "query",
    "where",
    "whereKey",
    "whereIn",
    "whereNotIn",
    "whereNull",
    "whereNotNull",
    "latest",
    "oldest",
    "orderBy",
    "select",
    "with",
    "withCount",
}
PIPELINE_RUN_BUILDER_METHODS = {
    "where",
    "whereKey",
    "whereIn",
    "whereNotIn",
    "whereNull",
    "whereNotNull",
    "whereRaw",
    "whereDoesntHave",
    "orWhere",
    "orWhereNull",
    "latest",
    "oldest",
    "orderBy",
    "select",
    "with",
    "withCount",
    "limit",
    "offset",
    "lockForUpdate",
    "sharedLock",
}
PIPELINE_RUN_MODEL_RETRIEVALS = {"find", "findOrFail", "first", "firstOrFail", "sole"}
# Model-bearing collection/lazy results must retain provenance. Treat paginators
# conservatively as collections too: a later collection-like extraction may
# yield a persisted PipelineRun even when an intermediate transform is unknown.
PIPELINE_RUN_COLLECTION_RETRIEVALS = {
    "all",
    "get",
    "cursor",
    "lazy",
    "lazyById",
    "lazyByIdDesc",
    "paginate",
    "simplePaginate",
    "cursorPaginate",
}
PIPELINE_RUN_VALUE_RETRIEVALS = {
    "value",
    "exists",
    "doesntExist",
    "count",
    "pluck",
}
PIPELINE_RUN_COLLECTION_MODEL_EXTRACTIONS = {
    "first",
    "firstOrFail",
    "last",
    "sole",
    "pop",
    "shift",
    "get",
}
PIPELINE_RUN_BUILDER_MUTATIONS = {"update", "delete", "forceDelete", "increment", "decrement"}
PIPELINE_RUN_EXISTING_MUTATIONS = {
    "update",
    "updateQuietly",
    "delete",
    "forceDelete",
}
PIPELINE_RUN_INSTANCE_PERSISTENCE = {
    "save",
    "saveQuietly",
    "saveOrFail",
    "push",
    "pushQuietly",
}
# These methods manufacture or conditionally manufacture a model. They are
# forbidden outside the sole Start owner as calls in their own right; a later
# save and inferred "existing" provenance are intentionally irrelevant.
PIPELINE_RUN_FACTORY_METHODS = {
    "make",
    "newInstance",
    "newModelInstance",
    "newFromBuilder",
    "replicate",
    "create",
    "createQuietly",
    "forceCreate",
    "forceCreateQuietly",
    "createMany",
    "createManyQuietly",
    "firstOrNew",
    "firstOrCreate",
    "createOrFirst",
    "updateOrCreate",
    "incrementOrCreate",
}
PIPELINE_RUN_EXISTING_FLUENT_METHODS = {"fill", "forceFill", "load", "refresh", "fresh"}
# These explicit relationships leave PipelineRun provenance and return builders
# for different persisted models. They must not taint PipelineItem/Event writes
# as PipelineRun creation.
PIPELINE_RUN_RELATION_ESCAPES = {"items", "events"}
PIPELINE_RUN_MUTATION_OWNERS = {
    START_OWNER,
    "laravel/app/Http/Controllers/PipelineRunController.php",
    "laravel/app/Jobs/RunPythonActorJob.php",
    "laravel/app/Services/Actors/PythonActorRunner.php",
    "laravel/app/Services/Pipeline/PipelineRecoveryDispatcher.php",
}
PIPELINE_RUN_LIFECYCLE_OWNERS = PIPELINE_RUN_MUTATION_OWNERS - {START_OWNER}
# The only variable functions permitted in a lifecycle owner are the two
# explicitly typed, private runProcess completion hooks. They are supplied only
# by literal closures at internal call sites; strings and generic callables are
# intentionally not accepted.
LIFECYCLE_INVOKABLE_CLOSURE_PARAMETERS = {
    "laravel/app/Services/Actors/PythonActorRunner.php": {"onFailure", "onSuccess"},
}
# Lifecycle-owner files use a lexical invariant rather than receiver provenance.
# Method names are normalized before substring matching, so framework additions
# such as forceCreateQuietly or a project helper such as bulk_upsert cannot open
# another callback/provenance gap. These semantics intentionally reject broader
# names such as restore/makeHidden in the narrowly scoped owner files.
LIFECYCLE_CREATION_SEMANTICS = {
    "create",
    "insert",
    "upsert",
    "persist",
    "store",
    "save",
    "push",
    "replicate",
    "newinstance",
    "newmodel",
    "newfrombuilder",
    "make",
}
# Closed, read/retrieval/update vocabulary shared by lifecycle owners. This is
# explicit rather than inferred: unknown model/builder/collection methods deny.
LIFECYCLE_SAFE_MODEL_METHODS = {
    name.lower()
    for name in (
        PIPELINE_RUN_STATIC_QUERY_ROOTS
        | PIPELINE_RUN_BUILDER_METHODS
        | PIPELINE_RUN_MODEL_RETRIEVALS
        | PIPELINE_RUN_COLLECTION_RETRIEVALS
        | PIPELINE_RUN_VALUE_RETRIEVALS
        | PIPELINE_RUN_COLLECTION_MODEL_EXTRACTIONS
        | PIPELINE_RUN_BUILDER_MUTATIONS
        | PIPELINE_RUN_EXISTING_MUTATIONS
        | PIPELINE_RUN_EXISTING_FLUENT_METHODS
        | {"each", "map", "filter", "reduce", "values", "through", "fresh"}
    )
}
# This is the reviewed literal service/helper vocabulary actually used by each
# narrow lifecycle owner. Adding a new call requires explicit policy review;
# known audit/event seams are included and remain unaffected.
LIFECYCLE_SAFE_LITERAL_METHODS = {
    "laravel/app/Http/Controllers/PipelineRunController.php": {
        "all",
        "audit",
        "count",
        "diagnosticeventtype",
        "documentprocessinggateopen",
        "errortype",
        "event",
        "events",
        "gateattributes",
        "get",
        "ip",
        "items",
        "latest",
        "limit",
        "linkedauditlogs",
        "load",
        "map",
        "metadata",
        "opaquereference",
        "orwhere",
        "paginate",
        "query",
        "raw",
        "redactedmessage",
        "render",
        "runpayload",
        "through",
        "toisostring",
        "typedscalar",
        "update",
        "user",
        "useragent",
        "value",
        "values",
        "webhookeventtype",
        "where",
        "with",
        "withcount",
    },
    "laravel/app/Jobs/RunPythonActorJob.php": {
        "consumecommand",
        "findorfail",
        "fresh",
        "isfuture",
        "issue",
        "suppresses",
        "lockforupdate",
        "query",
        "runcommandifeligible",
        "rundocumentpipeline",
        "runembeddingindexbuild",
        "runpipelineifeligible",
        "runpollreconciliation",
        "runreindex",
        "runreindexocr",
        "runreviewcommit",
        "runsyncentityapproval",
        "runwebhookdelivery",
        "runwebhookifeligible",
        "transaction",
        "update",
    },
    "laravel/app/Services/Actors/PythonActorRunner.php": {
        "assertcommandtype",
        "assertdurableexecution",
        "assertinvocation",
        "assertsource",
        "durablesourceidentity",
        "fromprocessoutput",
        "geterroroutput",
        "getexitcode",
        "getoutput",
        "info",
        "issuccessful",
        "log",
        "max",
        "query",
        "refresh",
        "run",
        "runcommandactor",
        "runprocess",
        "update",
        "warning",
        "where",
        "wherekey",
    },
    "laravel/app/Services/Pipeline/PipelineRecoveryDispatcher.php": {
        "activeactorstatuses",
        "activestatuses",
        "actorsourceexists",
        "actorsourcehasqueuedorrunningclaim",
        "actorsourceisinflight",
        "documentpipeline",
        "each",
        "embeddingindexbuild",
        "equalto",
        "event",
        "exists",
        "finalizecancelrequestedruns",
        "find",
        "first",
        "get",
        "hasactivecommandactor",
        "hasactivepipelineactor",
        "hasactivewebhookactor",
        "hasinflightpipelineactor",
        "hasneweractiveactorexecution",
        "hasneweractorsourcedispatch",
        "isactorprocessalive",
        "isafter",
        "isfuture",
        "latest",
        "limit",
        "lock",
        "lockforupdate",
        "markactorexecutionpermanentfailure",
        "markactorexecutionsuperseded",
        "markactorsourceretryable",
        "oldest",
        "orwhere",
        "pollreconciliation",
        "query",
        "reconcileactorexecutiontoterminalsource",
        "recordactorrecoveryevent",
        "recoverablecommandtypes",
        "recoveractorexecutions",
        "recoverdocumentpipelineruns",
        "recoverpendingcommands",
        "recoverprocesswebhookdeliveries",
        "recoverprocesswebhookdelivery",
        "recoverqueuedwebhookdeliveries",
        "recoverretryingactorexecution",
        "recoverstaleactorexecution",
        "redispatchactorsource",
        "redispatchcommand",
        "redispatchdocumentrun",
        "redispatchwebhookdelivery",
        "reindex",
        "reindexocr",
        "release",
        "releaseembeddingblockedrun",
        "releaseembeddingblockedruns",
        "releaseembeddingblockedwebhookdeliveries",
        "releaseembeddingblockedwebhookdelivery",
        "replaypending",
        "retryablewebhookerrors",
        "reviewcommit",
        "reviewcommitjoborfail",
        "stalequeuedcutoff",
        "stalequeuedminutes",
        "stalerunningcutoff",
        "stalerunningminutes",
        "start",
        "subhour",
        "subminutes",
        "syncentityapproval",
        "syncentityapprovaljoborfail",
        "todatetimestring",
        "touch",
        "transaction",
        "update",
        "value",
        "webhookdelivery",
        "where",
        "wheredoesnthave",
        "wherein",
        "wherekey",
        "wherenotin",
        "wherenull",
        "whereraw",
        "withinconservativelivenesswindow",
    },
}
PIPELINE_RUN_TABLE_MUTATION_OWNERS = {
    START_OWNER,
    "laravel/app/Console/Commands/ArchibotReset.php",
}


@dataclass(frozen=True)
class Violation:
    path: str
    line: int
    rule: str


EXCLUDED_PARTS = {
    ".agent-evidence",
    ".git",
    ".graphify",
    ".pi",
    ".pytest_cache",
    ".ruff_cache",
    ".venv",
    "__pycache__",
    "docs",
    "node_modules",
    "storage",
    "tests",
    "vendor",
}


def productive_files(root: Path) -> list[Path]:
    """Return every productive source/config/runtime file, deny-by-default.

    This intentionally scans migrations, routes, Docker/supervisor files, CI,
    and root scripts/config rather than maintaining a directory allowlist.
    """
    paths: set[Path] = set()
    for path in root.rglob("*"):
        parts = path.relative_to(root).parts
        if not path.is_file() or any(
            part in EXCLUDED_PARTS or part.endswith(".egg-info") for part in parts
        ):
            continue
        # Scan every readable repository file rather than treating an extension
        # list as an ownership allowlist. This includes extensionless launchers,
        # environment examples, supervisor/Docker inputs and future config types.
        paths.add(path)
    return sorted(paths)


def _line(text: str, offset: int) -> int:
    return text.count("\n", 0, offset) + 1


def legacy_reference_fingerprints(relative: str, text: str) -> Counter[tuple[str, str]]:
    """Fingerprint every normalized legacy occurrence, including duplicates.

    The digest combines the normalized matched token with its normalized source
    line. Counts are retained, so an additional reference in an already-known
    file—or even a duplicate on the same line—cannot hide behind a path-level
    inventory entry.
    """
    lines = text.splitlines()
    fingerprints: Counter[tuple[str, str]] = Counter()
    for match in LEGACY_PATTERN.finditer(text):
        line_index = text.count("\n", 0, match.start())
        semantic_line = " ".join(lines[line_index].strip().lower().split())
        matched_reference = " ".join(match.group(0).strip().lower().split())
        digest = hashlib.sha256(f"{matched_reference}\0{semantic_line}".encode()).hexdigest()[:20]
        fingerprints[(relative, digest)] += 1
    return fingerprints


def load_legacy_fingerprint_baseline() -> Counter[tuple[str, str]]:
    raw = json.loads(LEGACY_FINGERPRINT_BASELINE.read_text(encoding="utf-8"))
    return Counter(
        {
            (path, fingerprint): count
            for path, fingerprints in raw.items()
            for fingerprint, count in fingerprints.items()
        }
    )


def _string_fragment(node: ast.AST) -> str:
    if isinstance(node, ast.Constant) and isinstance(node.value, str):
        return node.value
    if isinstance(node, ast.Name):
        return "__DYNAMIC__"
    if isinstance(node, ast.JoinedStr):
        return "".join(_string_fragment(value) for value in node.values)
    if isinstance(node, ast.FormattedValue):
        return "__DYNAMIC__"
    if isinstance(node, ast.BinOp) and isinstance(node.op, (ast.Add, ast.Mod)):
        return _string_fragment(node.left) + "__DYNAMIC__" + _string_fragment(node.right)
    if isinstance(node, ast.Call) and node.args:
        return _string_fragment(node.args[0])
    return ""


def _python_name(node: ast.AST) -> str | None:
    return node.id if isinstance(node, ast.Name) else None


def _dotted_name(node: ast.AST) -> str | None:
    if isinstance(node, ast.Name):
        return node.id
    if isinstance(node, ast.Attribute):
        parent = _dotted_name(node.value)
        return f"{parent}.{node.attr}" if parent else None
    return None


def _python_scope_nodes(scope: ast.AST) -> list[ast.AST]:
    nodes: list[ast.AST] = []

    def visit(node: ast.AST, *, root: bool = False) -> None:
        nodes.append(node)
        if not root and isinstance(
            node, (ast.FunctionDef, ast.AsyncFunctionDef, ast.ClassDef, ast.Lambda)
        ):
            return
        for child in ast.iter_child_nodes(node):
            visit(child)

    visit(scope, root=True)
    return nodes


def scan_python(relative: str, text: str) -> list[Violation]:
    tree = ast.parse(text, filename=relative)
    violations: list[Violation] = []
    fenced_actor_names = {
        "_handle_document_pipeline_impl",
        "_build_initial_embedding_index_impl",
    }
    runner_actor_names = {
        "run_document_pipeline",
        "run_embedding_index_build_command",
        "run_reindex_command",
        "main",
    }
    fenced_actor_aliases = set(fenced_actor_names)
    fenced_module_aliases: set[str] = set()
    embedding_transition_names = {"start_embedding_index_build", "finish_embedding_index_build"}
    embedding_transition_aliases = set(embedding_transition_names)
    embedding_state_module_aliases: set[str] = set()
    for imported in ast.walk(tree):
        if isinstance(imported, ast.ImportFrom) and imported.module in {
            "app.actors.document",
            "app.actors.embedding",
            "app.actor_runner",
        }:
            for alias in imported.names:
                permitted_names = (
                    runner_actor_names
                    if imported.module == "app.actor_runner"
                    else fenced_actor_names
                )
                if alias.name in permitted_names:
                    fenced_actor_aliases.add(alias.asname or alias.name)
        elif isinstance(imported, ast.ImportFrom) and imported.module == "app.jobs.embedding_index":
            for alias in imported.names:
                if alias.name in embedding_transition_names:
                    embedding_transition_aliases.add(alias.asname or alias.name)
        elif isinstance(imported, ast.ImportFrom) and imported.module == "app.jobs":
            for alias in imported.names:
                if alias.name == "embedding_index":
                    embedding_state_module_aliases.add(alias.asname or alias.name)
        elif isinstance(imported, ast.ImportFrom) and imported.module == "app.actors":
            for alias in imported.names:
                if alias.name in {"document", "embedding"}:
                    fenced_module_aliases.add(alias.asname or alias.name)
        elif isinstance(imported, ast.Import):
            for alias in imported.names:
                if alias.name in {
                    "app.actors.document",
                    "app.actors.embedding",
                    "app.actor_runner",
                }:
                    fenced_module_aliases.add(alias.asname or alias.name.split(".")[-1])
                elif alias.name == "app.jobs.embedding_index":
                    embedding_state_module_aliases.add(alias.asname or alias.name.split(".")[-1])

    changed = True
    while changed:
        changed = False
        for assignment in ast.walk(tree):
            if not isinstance(assignment, (ast.Assign, ast.AnnAssign)) or assignment.value is None:
                continue
            targets = (
                assignment.targets if isinstance(assignment, ast.Assign) else [assignment.target]
            )
            target_names = {_python_name(target) for target in targets} - {None}
            value = assignment.value
            aliases_fenced_actor = (
                isinstance(value, ast.Name) and value.id in fenced_actor_aliases
            ) or (
                isinstance(value, ast.Attribute)
                and value.attr in fenced_actor_names | runner_actor_names
                and isinstance(value.value, ast.Name)
                and value.value.id in fenced_module_aliases
            )
            aliases_fenced_module = (
                isinstance(value, ast.Name) and value.id in fenced_module_aliases
            )
            aliases_embedding_transition = (
                isinstance(value, ast.Name) and value.id in embedding_transition_aliases
            ) or (
                isinstance(value, ast.Attribute)
                and value.attr in embedding_transition_names
                and isinstance(value.value, ast.Name)
                and value.value.id in embedding_state_module_aliases
            )
            aliases_embedding_module = (
                isinstance(value, ast.Name) and value.id in embedding_state_module_aliases
            )
            before = (
                len(fenced_actor_aliases),
                len(fenced_module_aliases),
                len(embedding_transition_aliases),
                len(embedding_state_module_aliases),
            )
            if aliases_fenced_actor:
                fenced_actor_aliases.update(target_names)
            if aliases_fenced_module:
                fenced_module_aliases.update(target_names)
            if aliases_embedding_transition:
                embedding_transition_aliases.update(target_names)
            if aliases_embedding_module:
                embedding_state_module_aliases.update(target_names)
            changed = changed or before != (
                len(fenced_actor_aliases),
                len(fenced_module_aliases),
                len(embedding_transition_aliases),
                len(embedding_state_module_aliases),
            )

    for node in ast.walk(tree):
        if isinstance(node, ast.ImportFrom) and node.module == "app.jobs.pipeline_start":
            violations.append(Violation(relative, node.lineno, "retired Pipeline Start import"))
        if isinstance(node, ast.Import) and any(
            alias.name == "app.jobs.pipeline_start" for alias in node.names
        ):
            violations.append(Violation(relative, node.lineno, "retired Pipeline Start import"))
        if relative != PYTHON_ACTOR_OWNER and isinstance(node, ast.Call):
            called = node.func.id if isinstance(node.func, ast.Name) else None
            dotted_call = _dotted_name(node.func)
            attribute_call = (
                isinstance(node.func, ast.Attribute)
                and node.func.attr in fenced_actor_names | runner_actor_names
                and (
                    (
                        isinstance(node.func.value, ast.Name)
                        and node.func.value.id in fenced_module_aliases
                    )
                    or dotted_call
                    in {
                        "app.actors.document._handle_document_pipeline_impl",
                        "app.actors.embedding._build_initial_embedding_index_impl",
                        "app.actor_runner.run_document_pipeline",
                        "app.actor_runner.run_embedding_index_build_command",
                        "app.actor_runner.run_reindex_command",
                        "app.actor_runner.main",
                    }
                )
            )
            dynamic_lookup = (
                isinstance(node.func, ast.Name)
                and node.func.id == "getattr"
                and len(node.args) >= 2
                and isinstance(node.args[0], ast.Name)
                and node.args[0].id in fenced_module_aliases
            )
            if called in fenced_actor_aliases or attribute_call or dynamic_lookup:
                violations.append(
                    Violation(
                        relative, node.lineno, "fenced actor execution outside Laravel runner"
                    )
                )
            embedding_transition_call = called in embedding_transition_aliases or (
                isinstance(node.func, ast.Attribute)
                and node.func.attr in embedding_transition_names
                and (
                    (
                        isinstance(node.func.value, ast.Name)
                        and node.func.value.id in embedding_state_module_aliases
                    )
                    or dotted_call
                    in {
                        "app.jobs.embedding_index.start_embedding_index_build",
                        "app.jobs.embedding_index.finish_embedding_index_build",
                    }
                )
            )
            if (
                relative not in {PYTHON_EMBEDDING_TRANSITION_OWNER, "app/jobs/embedding_index.py"}
                and embedding_transition_call
            ):
                violations.append(
                    Violation(
                        relative, node.lineno, "embedding gate transition outside fenced actor"
                    )
                )

        fragment = _string_fragment(node)
        normalized = re.sub(r"[\s`\"']+", " ", fragment).lower()
        if re.search(r"\binsert\s+into\s+(?:public\s*\.\s*)?pipeline_runs\b", normalized):
            violations.append(
                Violation(relative, getattr(node, "lineno", 0), "pipeline_runs INSERT")
            )
        if (
            relative not in PYTHON_FENCED_LIFECYCLE_SQL_OWNERS
            and "__dynamic__" in normalized
            and re.search(
                r"\b(?:insert\s+into|update|delete\s+from)\s+[^\s,()]*__dynamic__",
                normalized,
            )
        ):
            violations.append(
                Violation(relative, getattr(node, "lineno", 0), "dynamic SQL write target")
            )

    scopes: list[ast.AST] = [
        tree,
        *[
            node
            for node in ast.walk(tree)
            if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef, ast.Lambda))
        ],
    ]
    for scope in scopes:
        nodes = _python_scope_nodes(scope)
        dynamic_values: set[str] = set()
        sql_values: set[str] = set()
        changed = True
        while changed:
            changed = False
            for node in nodes:
                if not isinstance(node, (ast.Assign, ast.AnnAssign)) or node.value is None:
                    continue
                targets = node.targets if isinstance(node, ast.Assign) else [node.target]
                names = {_python_name(target) for target in targets} - {None}
                fragment = _string_fragment(node.value)
                referenced = {
                    item.id for item in ast.walk(node.value) if isinstance(item, ast.Name)
                }
                is_sql = bool(
                    re.search(r"\b(?:insert\s+into|update|delete\s+from)\b", fragment, re.I)
                )
                before = (len(dynamic_values), len(sql_values))
                if "__DYNAMIC__" in fragment or referenced & dynamic_values:
                    dynamic_values.update(names)
                if is_sql or referenced & sql_values:
                    sql_values.update(names)
                changed = changed or before != (len(dynamic_values), len(sql_values))

        for node in nodes:
            if not isinstance(node, ast.Call) or not node.args:
                continue
            method = node.func.attr.lower() if isinstance(node.func, ast.Attribute) else ""
            argument_name = _python_name(node.args[0])
            if (
                relative not in PYTHON_FENCED_LIFECYCLE_SQL_OWNERS
                and method in {"execute", "executemany", "executescript"}
                and argument_name in sql_values
                and argument_name in dynamic_values
            ):
                violations.append(Violation(relative, node.lineno, "dynamic SQL write target"))
    return violations


def scan_text(relative: str, text: str) -> list[Violation]:
    normalized = re.sub(r"[\s`\"']+", " ", text).lower()
    violations: list[Violation] = []
    if relative != START_OWNER and re.search(
        r"\binsert\s+into\s+(?:public\s*\.\s*)?pipeline_runs\b", normalized
    ):
        violations.append(Violation(relative, 1, "pipeline_runs INSERT outside owner"))
    if relative not in {PYTHON_ACTOR_OWNER, PYTHON_LAUNCH_OWNER} and re.search(
        r"(?:^|[\s;/])(?:python\w*\s+)?-m\s+app\.actor_runner\b", normalized
    ):
        violations.append(
            Violation(relative, 1, "Python actor runner launch outside Laravel owner")
        )
    return violations


def _strip_php_comments(text: str) -> str:
    without_tags = re.sub(r"<\?(?:php)?|\?>", " ", text, flags=re.IGNORECASE)
    return re.sub(r"/\*.*?\*/|//[^\n]*|#[^\n]*", " ", without_tags, flags=re.DOTALL)


def _braced_variable_invocation_offsets(code: str) -> list[int]:
    """Find ``{$...}`` calls without imposing a brace-nesting limit."""
    offsets: list[int] = []
    for token in re.finditer(r"\{\s*\$", code):
        depth = 0
        quote: str | None = None
        escaped = False
        closing = None
        for position in range(token.start(), len(code)):
            character = code[position]
            if quote is not None:
                if escaped:
                    escaped = False
                elif character == "\\":
                    escaped = True
                elif character == quote:
                    quote = None
                continue
            if character in {"'", '"'}:
                quote = character
            elif character == "{":
                depth += 1
            elif character == "}":
                depth -= 1
                if depth == 0:
                    closing = position + 1
                    break
        if closing is None:
            continue
        # The token may be nested in more braces. Peel every enclosing close;
        # unlike a recursive regex this remains correct at arbitrary depth.
        position = closing
        while position < len(code):
            while position < len(code) and code[position].isspace():
                position += 1
            if position < len(code) and code[position] == "}":
                position += 1
                continue
            break
        if position < len(code) and code[position] == "(":
            offsets.append(token.start())
    return offsets


def scan_php(relative: str, text: str) -> list[Violation]:
    code = _strip_php_comments(text)
    violations: list[Violation] = []
    aliases = {"PipelineRun"}
    aliases.update(
        match.group(1) or "PipelineRun"
        for match in re.finditer(r"use\s+App\\Models\\PipelineRun(?:\s+as\s+(\w+))?\s*;", code)
    )
    alias_pattern = "|".join(map(re.escape, aliases))

    if relative in PIPELINE_RUN_LIFECYCLE_OWNERS:
        # Dynamic dispatch is forbidden outright in lifecycle owners. Receiver
        # provenance is deliberately irrelevant: callbacks, service objects,
        # model aliases, and static class aliases must all use literal methods.
        dynamic_patterns = (
            r"(?:->|::)\s*(?:\{\s*)?\$(?:[A-Za-z_]\w*(?:\s*\[[^]]+\])?|\{[^}]+\})(?:\s*\})?\s*\(",
            r"(?:->|::)\s*\{[^}\n]+\}\s*\(",
            # A variable class receiver is dynamic even with a literal method.
            r"\$[A-Za-z_]\w*\s*::\s*[A-Za-z_]\w*\s*\(",
            r"\b(?:call_user_func(?:_array)?|forward_static_call(?:_array)?|Closure\s*::\s*fromCallable)\s*\(",
            # PHP callable arrays: [$object, 'method'], [$object, $method],
            # [Type::class, 'method'], including legacy array(...) syntax. A
            # quoted first item is excluded so ordinary string pairs remain
            # data; destructuring is excluded by the trailing assignment.
            r"\[\s*(?:\$[A-Za-z_]\w*(?:\s*->\s*[A-Za-z_]\w*)?|(?:[A-Za-z_\\][A-Za-z0-9_\\]*|self|static|parent)\s*::\s*class)\s*,\s*(?:['\"][^'\"]+['\"]|\$[A-Za-z_]\w*|[A-Za-z_\\][A-Za-z0-9_\\]*\s*::\s*[A-Za-z_]\w*)\s*\](?!\s*=)",
            r"\barray\s*\(\s*(?:\$[A-Za-z_]\w*(?:\s*->\s*[A-Za-z_]\w*)?|(?:[A-Za-z_\\][A-Za-z0-9_\\]*|self|static|parent)\s*::\s*class)\s*,\s*(?:['\"][^'\"]+['\"]|\$[A-Za-z_]\w*|[A-Za-z_\\][A-Za-z0-9_\\]*\s*::\s*[A-Za-z_]\w*)\s*\)",
        )
        for pattern in dynamic_patterns:
            for match in re.finditer(pattern, code, re.IGNORECASE | re.DOTALL):
                violations.append(
                    Violation(
                        relative,
                        _line(code, match.start()),
                        "dynamic method/callable forbidden in lifecycle owner",
                    )
                )

        # Variable-function and invokable-container execution defaults to
        # denied. The sole exception is a named Closure parameter on the
        # private process wrapper; requiring the type in this same source keeps
        # a future rename/type widening from silently extending execution.
        allowed_closures = LIFECYCLE_INVOKABLE_CLOSURE_PARAMETERS.get(relative, set())
        typed_allowed_closures: set[str] = set()
        closure_invocation_span: tuple[int, int] | None = None
        run_process = re.search(
            r"private\s+function\s+runProcess\s*\((.*?)\)\s*:\s*void\s*\{",
            code,
            re.IGNORECASE | re.DOTALL,
        )
        if run_process is not None:
            signature = run_process.group(1)
            typed_allowed_closures = {
                name
                for name in allowed_closures
                if re.search(rf"(?:\?|\\)?Closure\s+\${re.escape(name)}\b", signature, re.I)
            }
            opening_brace = run_process.end() - 1
            depth = 0
            for position in range(opening_brace, len(code)):
                if code[position] == "{":
                    depth += 1
                elif code[position] == "}":
                    depth -= 1
                    if depth == 0:
                        closure_invocation_span = (opening_brace, position)
                        break
        # Reject `${` lexically everywhere in a lifecycle owner. There is no
        # legitimate use, so expression parsing and regex nesting depth are
        # intentionally irrelevant. Braced `{$...}` variable calls are found
        # with a balanced scan, including arbitrarily nested outer braces.
        for match in re.finditer(r"\$\{", text):
            violations.append(
                Violation(
                    relative,
                    _line(text, match.start()),
                    "variable function/invokable container forbidden in lifecycle owner",
                )
            )
        for offset in _braced_variable_invocation_offsets(code):
            violations.append(
                Violation(
                    relative,
                    _line(code, offset),
                    "variable function/invokable container forbidden in lifecycle owner",
                )
            )

        # All remaining `$`-initiated calls use unbraced syntax. Only the exact
        # `$onFailure(...)` and `$onSuccess(...)` spellings can use the audited
        # Closure exception; variable variables and offsets remain forbidden.
        for match in re.finditer(
            r"\${1,}[A-Za-z_]\w*(?:\s*(?:\[[^]\n]+\]|\{[^{}\n]+\}))*\s*\(",
            code,
            re.IGNORECASE,
        ):
            direct_name = re.fullmatch(r"\$([A-Za-z_]\w*)\s*\(", match.group(0), re.IGNORECASE)
            invocation_is_audited_closure = (
                direct_name is not None
                and direct_name.group(1) in typed_allowed_closures
                and closure_invocation_span is not None
                and closure_invocation_span[0] < match.start() < closure_invocation_span[1]
            )
            if not invocation_is_audited_closure:
                violations.append(
                    Violation(
                        relative,
                        _line(code, match.start()),
                        "variable function/invokable container forbidden in lifecycle owner",
                    )
                )
        for match in re.finditer(
            r"(?:\)\s*|\]\s*|\b(?:app|resolve)\s*\([^;]*?\)\s*)\(",
            code,
            re.IGNORECASE | re.DOTALL,
        ):
            violations.append(
                Violation(
                    relative,
                    _line(code, match.start()),
                    "variable function/invokable container forbidden in lifecycle owner",
                )
            )

        # String-built callables are forbidden even when they are only
        # assigned and never invoked. This closes delayed/container invocation
        # and concatenation gaps without trying to infer variable provenance.
        for match in re.finditer(r"\$\w+\s*=\s*([^;]+);", code, re.DOTALL):
            expression = match.group(1)
            literal_parts = re.findall(r"['\"]([^'\"]*)['\"]", expression)
            nonliteral = re.sub(r"['\"][^'\"]*['\"]", "", expression)
            if literal_parts and re.fullmatch(r"[\s.()]*", nonliteral):
                callable_name = "".join(literal_parts).strip()
                if re.fullmatch(
                    r"[A-Za-z_\\][A-Za-z0-9_\\]*(?:(?:::|@)[A-Za-z_]\w*)?",
                    callable_name,
                ):
                    violations.append(
                        Violation(
                            relative,
                            _line(code, match.start()),
                            "string callable assignment forbidden in lifecycle owner",
                        )
                    )

        # A quoted/concatenated PipelineRun callable is forbidden wherever it
        # appears, not just in an assignment. Join literal tokens statement by
        # statement so 'Pipeline'.'Run::'.'create' cannot evade the check.
        mutation_words = {
            "create",
            "createquietly",
            "forcecreate",
            "forcecreatequietly",
            "createmany",
            "firstorcreate",
            "createorfirst",
            "updateorcreate",
            "incrementorcreate",
            "insert",
            "upsert",
            "persist",
            "store",
            "save",
            "savequietly",
            "push",
            "replicate",
            "newinstance",
            "newmodelinstance",
            "newfrombuilder",
            "make",
            "update",
            "updatequietly",
            "delete",
            "forcedelete",
            "increment",
            "decrement",
            "touch",
        }
        literal_concat = r"['\"][^'\"]*['\"](?:[\s.()]*['\"][^'\"]*['\"])*"
        for match in re.finditer(literal_concat, code):
            joined_literals = re.sub(
                r"\s+",
                "",
                "".join(re.findall(r"['\"]([^'\"]*)['\"]", match.group(0))).lower(),
            )
            callable_suffix = joined_literals.partition("pipelinerun::")[2]
            normalized_suffix = re.sub(r"[^a-z0-9]", "", callable_suffix)
            if callable_suffix and any(
                normalized_suffix.startswith(word) for word in mutation_words
            ):
                violations.append(
                    Violation(
                        relative,
                        _line(code, match.start()),
                        "PipelineRun mutation string forbidden in lifecycle owner",
                    )
                )
        for match in re.finditer(r"\$\w+\s*=\s*([^;]+);", code, re.DOTALL):
            expression = match.group(1)
            if re.search(r"\bPipelineRun\s*::\s*class\b", expression, re.I) and (
                "." in expression or re.search(r"['\"]\s*::", expression)
            ):
                violations.append(
                    Violation(
                        relative,
                        _line(code, match.start()),
                        "PipelineRun callable construction forbidden in lifecycle owner",
                    )
                )

        # Reflection, forwarding helpers, and language-level string execution
        # are prohibited independently of inferred target or invocation.
        execution_patterns = (
            r"\bReflection(?:Method|Function)\b",
            r"\bforward_static_call(?:_array)?\s*\(",
            r"\beval\s*\(",
            r"(?<!->)(?<!::)(?<![A-Za-z0-9_])\\?assert\s*\(",
        )
        for pattern in execution_patterns:
            for match in re.finditer(pattern, code, re.IGNORECASE):
                violations.append(
                    Violation(
                        relative,
                        _line(code, match.start()),
                        "reflection/forward/eval execution forbidden in lifecycle owner",
                    )
                )

        # Literal creation semantics are also unconditional. Normalize away
        # separators and case, then use substring matching to cover current and
        # future prefix/suffix variants rather than maintaining a method list.
        for match in re.finditer(
            r"(?:->|::)\s*([A-Za-z_]\w*)\s*\(",
            code,
            re.IGNORECASE,
        ):
            normalized_method = re.sub(r"[^a-z0-9]", "", match.group(1).lower())
            if any(semantic in normalized_method for semantic in LIFECYCLE_CREATION_SEMANTICS):
                violations.append(
                    Violation(
                        relative,
                        _line(code, match.start()),
                        "lexical creation-semantic method forbidden in lifecycle owner",
                    )
                )
            elif normalized_method not in (
                LIFECYCLE_SAFE_MODEL_METHODS | LIFECYCLE_SAFE_LITERAL_METHODS[relative]
            ):
                violations.append(
                    Violation(
                        relative,
                        _line(code, match.start()),
                        "literal method outside lifecycle owner allowlist",
                    )
                )

    if relative != START_OWNER:
        forbidden_mutators = (
            CREATION_MUTATORS if relative in PIPELINE_RUN_MUTATION_OWNERS else MUTATORS
        )
        patterns = (
            rf"\b(?:{alias_pattern})\s*::\s*(?:{forbidden_mutators})\s*\(",
            rf"\b(?:{alias_pattern})\s*::\s*query\s*\(\s*\)\s*->\s*(?:{forbidden_mutators})\s*\(",
        )
        for pattern in patterns:
            for match in re.finditer(pattern, code, re.IGNORECASE | re.DOTALL):
                violations.append(
                    Violation(relative, _line(code, match.start()), "PipelineRun model write")
                )
        for match in re.finditer(rf"\$(\w+)\s*=\s*new\s+(?:{alias_pattern})\b", code):
            if re.search(
                rf"\${re.escape(match.group(1))}\s*->\s*(?:save|\{{\s*\$\w+\s*\}})\s*\(",
                code[match.end() :],
            ):
                violations.append(
                    Violation(relative, _line(code, match.start()), "PipelineRun new/save alias")
                )

    # Track PipelineRun instances/builders through container resolution,
    # find/query results, assignment aliases and dynamic method aliases. Known
    # lifecycle writers are narrowly allowlisted; every other productive path
    # is denied even when fill/save or create are separated across statements.
    pipeline_aliases: set[str] = set()
    builder_aliases: set[str] = set()
    model_class_aliases = {
        match.group(1)
        for match in re.finditer(rf"\$(\w+)\s*=\s*(?:{alias_pattern})\s*::\s*class\s*;", code, re.I)
    }
    class_alias_changed = True
    while class_alias_changed:
        class_alias_changed = False
        for match in re.finditer(r"\$(\w+)\s*=\s*\$(\w+)\s*;", code):
            destination, source = match.groups()
            if source in model_class_aliases and destination not in model_class_aliases:
                model_class_aliases.add(destination)
                class_alias_changed = True
    class_alias_pattern = "|".join(map(re.escape, model_class_aliases)) or "__NO_CLASS_ALIAS__"
    container_model = rf"(?:(?:{alias_pattern})\s*::\s*class|\$(?:{class_alias_pattern}))"
    model_source = rf"(?:new\s+(?:{alias_pattern})\b|(?:app|resolve)\s*\(\s*{container_model}\s*\)|(?:{alias_pattern})\s*::\s*(?:find(?:OrFail)?|first(?:OrFail)?|query)\s*\()"
    for match in re.finditer(rf"\$(\w+)\s*=\s*{model_source}", code, re.IGNORECASE):
        pipeline_aliases.add(match.group(1))
        if re.search(rf"(?:{alias_pattern})\s*::\s*query\s*\(", match.group(0), re.I):
            builder_aliases.add(match.group(1))
    changed = True
    while changed:
        changed = False
        for match in re.finditer(r"\$(\w+)\s*=\s*\$(\w+)\s*;", code):
            destination, source = match.groups()
            if source in pipeline_aliases and destination not in pipeline_aliases:
                pipeline_aliases.add(destination)
                changed = True
            if source in builder_aliases and destination not in builder_aliases:
                builder_aliases.add(destination)
                changed = True
            if source in model_class_aliases and destination not in model_class_aliases:
                model_class_aliases.add(destination)
                changed = True
        for match in re.finditer(
            r"\$(\w+)\s*=\s*\$(\w+)\s*->\s*(?:find(?:OrFail)?|first(?:OrFail)?)\s*\(", code, re.I
        ):
            destination, source = match.groups()
            if source in builder_aliases and destination not in pipeline_aliases:
                pipeline_aliases.add(destination)
                changed = True

    method_aliases = {
        match.group(1): match.group(2).lower()
        for match in re.finditer(
            rf"\$(\w+)\s*=\s*['\"]({MUTATORS}|fill|forceFill)['\"]\s*;",
            code,
            re.IGNORECASE,
        )
    }
    if relative not in PIPELINE_RUN_MUTATION_OWNERS:
        for alias in pipeline_aliases:
            direct = re.finditer(
                rf"\${re.escape(alias)}\s*->\s*(?:fill|forceFill|save|create|firstOrCreate|updateOrCreate)\s*\(",
                code,
                re.IGNORECASE,
            )
            for match in direct:
                violations.append(
                    Violation(
                        relative, _line(code, match.start()), "PipelineRun instance/builder write"
                    )
                )
            for method_alias, method in method_aliases.items():
                if method in {
                    "fill",
                    "forcefill",
                    "save",
                    "create",
                    "firstorcreate",
                    "updateorcreate",
                }:
                    for match in re.finditer(
                        rf"\${re.escape(alias)}\s*->\s*\${re.escape(method_alias)}\s*\(", code
                    ):
                        violations.append(
                            Violation(
                                relative,
                                _line(code, match.start()),
                                "aliased PipelineRun write method",
                            )
                        )
            for match in re.finditer(
                rf"\$(\w+)\s*=\s*\[\s*\${re.escape(alias)}\s*,\s*['\"](?:create|firstOrCreate|updateOrCreate|save|fill|forceFill)['\"]\s*\]\s*;",
                code,
                re.IGNORECASE,
            ):
                callable_alias = match.group(1)
                call = re.search(rf"\${re.escape(callable_alias)}\s*\(", code[match.end() :])
                if call:
                    violations.append(
                        Violation(
                            relative,
                            _line(code, match.start()),
                            "callable-aliased PipelineRun write",
                        )
                    )
        for match in re.finditer(
            rf"(?:app|resolve)\s*\(\s*(?:{alias_pattern})\s*::\s*class\s*\)(?:\s*->\s*\w+\s*\([^;]*?\))*\s*->\s*(?:save|create|firstOrCreate|updateOrCreate)\s*\(",
            code,
            re.IGNORECASE | re.DOTALL,
        ):
            violations.append(
                Violation(
                    relative, _line(code, match.start()), "container-resolved PipelineRun write"
                )
            )
        for match in re.finditer(
            rf"(?:{alias_pattern})\s*::\s*(?:find(?:OrFail)?|query)\s*\([^;]*?->\s*(?:fill|forceFill|save|create|firstOrCreate|updateOrCreate)\s*\(",
            code,
            re.IGNORECASE | re.DOTALL,
        ):
            violations.append(
                Violation(relative, _line(code, match.start()), "PipelineRun query-result write")
            )

    # Deny-by-default Eloquent provenance tracking. Any static PipelineRun call
    # is a potential model/builder source (where(), first(), sole(), scopes,
    # macros, and future retrieval APIs need no scanner update). Any expression
    # receiving PipelineRun::class through a container is likewise tainted, as
    # is the pipelineRuns relationship. Taint propagates through arbitrary
    # assignment chains and fluent calls before reaching a write sink.
    tainted: set[str] = set(pipeline_aliases)
    tainted.update(
        match.group(1)
        for match in re.finditer(
            rf"\b(?:{alias_pattern})\s+\$(\w+)\b",
            code,
            re.IGNORECASE,
        )
    )
    relationship_names = {"pipelineRuns"}
    relationship_names.update(
        match.group(1)
        for match in re.finditer(
            rf"function\s+(\w+)\s*\([^)]*\)[^{{]*\{{[^}}]*"
            rf"(?:hasMany|hasOne|morphMany|morphOne|belongsToMany)\s*\([^}};]*"
            rf"\b(?:{alias_pattern})\s*::\s*class\b",
            code,
            re.IGNORECASE | re.DOTALL,
        )
    )
    relationship_pattern = "|".join(map(re.escape, relationship_names))
    statements = list(re.finditer(r"([^;{}]+);", code, re.DOTALL))

    def pipeline_source(expression: str) -> bool:
        static_source = re.search(
            rf"\b(?:{alias_pattern})\s*::\s*[A-Za-z_]\w*\s*\(",
            expression,
            re.IGNORECASE,
        )
        relation_source = re.search(
            rf"(?:->|::)\s*(?:{relationship_pattern})\s*\(", expression, re.I
        )
        # Passing PipelineRun::class into any resolver/factory call is tainted;
        # a future container facade or helper does not need to be enumerated.
        container_source = re.search(
            rf"(?:\w+\s*::\s*\w+|\w+|->\s*\w+)\s*\([^;]*"
            rf"\b(?:{alias_pattern})\s*::\s*class\b",
            expression,
            re.IGNORECASE | re.DOTALL,
        ) or re.search(
            rf"\bmake\s*\([^;]*\$(?:{class_alias_pattern})\b",
            expression,
            re.IGNORECASE | re.DOTALL,
        )
        return bool(static_source or relation_source or container_source)

    changed = True
    while changed:
        changed = False
        for statement_match in statements:
            statement = statement_match.group(1)
            assignment = re.match(r"\s*\$(\w+)\s*=\s*(.*)\Z", statement, re.DOTALL)
            if assignment is None:
                continue
            destination, expression = assignment.groups()
            uses_taint = any(
                re.match(rf"\s*\${re.escape(source)}\b", expression) for source in tainted
            )
            escapes_pipeline_run = uses_taint and re.match(
                rf"\s*\$\w+\s*->\s*(?:{'|'.join(PIPELINE_RUN_RELATION_ESCAPES)})\s*\(",
                expression,
                re.IGNORECASE,
            )
            if (
                (pipeline_source(expression) or uses_taint)
                and not escapes_pipeline_run
                and destination not in tainted
            ):
                tainted.add(destination)
                changed = True

        # Preserve model-bearing provenance through foreach value targets and
        # every PHP destructuring form. This is conservative by design: keys or
        # nested targets may be scalars, but must never launder a PipelineRun.
        for match in re.finditer(
            r"foreach\s*\((?P<source>.*?)\s+as\s+(?P<target>.*?)\)",
            code,
            re.IGNORECASE | re.DOTALL,
        ):
            source = match.group("source")
            if not pipeline_source(source) and not any(
                re.search(rf"\${re.escape(alias)}\b", source) for alias in tainted
            ):
                continue
            for destination in re.findall(r"\$(\w+)", match.group("target")):
                if destination not in tainted:
                    tainted.add(destination)
                    changed = True
        for match in re.finditer(
            r"(?:list\s*\((?P<list>[^)]*)\)|\[(?P<bracket>[^]]*)\])\s*=\s*(?P<source>[^;]+)",
            code,
            re.IGNORECASE | re.DOTALL,
        ):
            source = match.group("source")
            if not pipeline_source(source) and not any(
                re.search(rf"\${re.escape(alias)}\b", source) for alias in tainted
            ):
                continue
            for destination in re.findall(
                r"\$(\w+)", match.group("list") or match.group("bracket")
            ):
                if destination not in tainted:
                    tainted.add(destination)
                    changed = True

    if relative != START_OWNER:
        static_allowed = (
            PIPELINE_RUN_STATIC_QUERY_ROOTS
            | PIPELINE_RUN_MODEL_RETRIEVALS
            | PIPELINE_RUN_COLLECTION_RETRIEVALS
            | PIPELINE_RUN_VALUE_RETRIEVALS
        )
        allowed_static_lower = {name.lower() for name in static_allowed}
        factory_lower = {name.lower() for name in PIPELINE_RUN_FACTORY_METHODS}

        # A PipelineRun static call is read/query-only unless explicitly
        # allowlisted. Unknown methods default to factories everywhere, not
        # merely in known lifecycle files.
        for match in re.finditer(
            rf"\b(?:{alias_pattern})\s*::\s*([A-Za-z_]\w*)\s*\(", code, re.IGNORECASE
        ):
            if match.group(1).lower() not in allowed_static_lower:
                violations.append(
                    Violation(
                        relative, _line(code, match.start()), "PipelineRun factory outside owner"
                    )
                )

        # Constructors and container resolution manufacture a fresh model and
        # are creation sinks even when the result is discarded.
        for match in re.finditer(rf"\bnew\s+(?:{alias_pattern})\b", code, re.IGNORECASE):
            violations.append(
                Violation(
                    relative, _line(code, match.start()), "PipelineRun construction outside owner"
                )
            )
        for match in re.finditer(
            rf"(?:\b(?:app|resolve)\s*\(\s*(?:{alias_pattern})\s*::\s*class\s*\)|"
            rf"\bContainer\s*::\s*getInstance\s*\(\s*\)\s*->\s*make\s*\(\s*"
            rf"(?:{alias_pattern})\s*::\s*class\s*\))",
            code,
            re.IGNORECASE,
        ):
            violations.append(
                Violation(
                    relative,
                    _line(code, match.start()),
                    "PipelineRun container factory outside owner",
                )
            )

        # Factory calls and clone are denied on every PipelineRun-tainted
        # value independently of whether a later mutation can be observed.
        for alias in tainted:
            for match in re.finditer(
                rf"\${re.escape(alias)}\s*->\s*([A-Za-z_]\w*)\s*\(", code, re.IGNORECASE
            ):
                if match.group(1).lower() in factory_lower:
                    violations.append(
                        Violation(
                            relative,
                            _line(code, match.start()),
                            "PipelineRun instance factory outside owner",
                        )
                    )
            for match in re.finditer(rf"\bclone\s+\${re.escape(alias)}\b", code, re.IGNORECASE):
                violations.append(
                    Violation(
                        relative, _line(code, match.start()), "clone of PipelineRun-tainted value"
                    )
                )
        for match in re.finditer(r"\bclone\s+(?P<source>[^;]+)", code, re.IGNORECASE):
            if pipeline_source(match.group("source")):
                violations.append(
                    Violation(
                        relative, _line(code, match.start()), "clone of PipelineRun-tainted value"
                    )
                )

        # Catch unassigned fluent factories such as query()->make() without
        # scanning unrelated calls later in a large array expression.
        for match in re.finditer(
            rf"\b(?:{alias_pattern})\s*::\s*[A-Za-z_]\w*\s*\([^;()]*\)"
            rf"(?:\s*->\s*[A-Za-z_]\w*\s*\([^;()]*\))*?"
            rf"\s*->\s*(?:{'|'.join(PIPELINE_RUN_FACTORY_METHODS)})\s*\(",
            code,
            re.IGNORECASE | re.DOTALL,
        ):
            prefix = code[match.start() : match.end()]
            if not re.search(
                rf"->\s*(?:{'|'.join(PIPELINE_RUN_RELATION_ESCAPES)})\s*\(",
                prefix,
                re.IGNORECASE,
            ):
                violations.append(
                    Violation(
                        relative,
                        _line(code, match.start()),
                        "fluent PipelineRun factory outside owner",
                    )
                )

    # Lifecycle owners may mutate only a typed existing model, an explicitly
    # retrieved model, or an explicitly rooted query builder. Unknown static or
    # fluent calls are creation-tainted by default. In particular, make(),
    # newInstance(), newModelInstance(), newFromBuilder(), replicate(), and any
    # future factory cannot acquire mutation provenance merely because it is
    # absent from a denylist. Instance save/push remains forbidden in all cases.
    if relative in PIPELINE_RUN_MUTATION_OWNERS - {START_OWNER}:
        # Lifecycle files have no instance-persistence exception. Existing
        # PipelineRuns must use update()/updateQuietly() or a query update, so a
        # save/push cannot become a hidden create after provenance laundering.
        for alias in tainted:
            for match in re.finditer(
                rf"\${re.escape(alias)}\s*(?:->\s*[A-Za-z_]\w*\s*\([^;]*?\)\s*)*"
                rf"->\s*(?:{'|'.join(PIPELINE_RUN_INSTANCE_PERSISTENCE)})\s*\(",
                code,
                re.IGNORECASE | re.DOTALL,
            ):
                violations.append(
                    Violation(
                        relative,
                        _line(code, match.start()),
                        "PipelineRun instance persistence forbidden in lifecycle owner",
                    )
                )

        states: dict[str, str] = {alias: "existing" for alias in tainted}
        states.update(
            {
                match.group(1): "existing"
                for match in re.finditer(rf"\b(?:{alias_pattern})\s+\$(\w+)\b", code, re.IGNORECASE)
            }
        )
        static_allowed = (
            PIPELINE_RUN_STATIC_QUERY_ROOTS
            | PIPELINE_RUN_MODEL_RETRIEVALS
            | PIPELINE_RUN_COLLECTION_RETRIEVALS
            | PIPELINE_RUN_VALUE_RETRIEVALS
        )

        # A static method outside the closed query/retrieval vocabulary is a
        # creation attempt in a lifecycle owner, even if the result is not yet
        # saved. DocumentPipelineStarter is the only creation owner.
        for match in re.finditer(
            rf"\b(?:{alias_pattern})\s*::\s*([A-Za-z_]\w*)\s*\(", code, re.IGNORECASE
        ):
            if match.group(1).lower() not in {name.lower() for name in static_allowed}:
                violations.append(
                    Violation(
                        relative, _line(code, match.start()), "unknown/static PipelineRun factory"
                    )
                )

        def classify_expression(expression: str) -> str | None:
            if re.search(
                rf"\bnew\s+(?:{alias_pattern})\b|"
                rf"\b(?:app|resolve)\s*\([^;]*\b(?:{alias_pattern})\s*::\s*class\b|"
                rf"\b\w+(?:::\w+)?\s*\([^;]*\b(?:{alias_pattern})\s*::\s*class\b",
                expression,
                re.IGNORECASE | re.DOTALL,
            ):
                return "creation"

            static = re.search(
                rf"\b(?:{alias_pattern})\s*::\s*([A-Za-z_]\w*)\s*\(",
                expression,
                re.IGNORECASE,
            )
            state: str | None = None
            chain_start = 0
            if static:
                method = static.group(1).lower()
                chain_start = static.end()
                if method in {name.lower() for name in PIPELINE_RUN_MODEL_RETRIEVALS}:
                    state = "existing"
                elif method in {name.lower() for name in PIPELINE_RUN_COLLECTION_RETRIEVALS}:
                    state = "collection"
                elif method in {name.lower() for name in PIPELINE_RUN_VALUE_RETRIEVALS}:
                    return None
                elif method in {name.lower() for name in PIPELINE_RUN_STATIC_QUERY_ROOTS}:
                    state = "builder"
                else:
                    return "creation"
            else:
                source = re.match(r"\s*\$(\w+)\b", expression)
                if source is None or source.group(1) not in states:
                    return None
                state = states[source.group(1)]
                chain_start = source.end()
                # Array/index extraction from a model-bearing collection yields
                # an existing hydrated model, not fresh-model provenance.
                if state == "collection" and re.match(
                    r"\s*\[[^]]+\]", expression[chain_start:], re.DOTALL
                ):
                    state = "existing"

            for method_match in re.finditer(
                r"->\s*([A-Za-z_]\w*)\s*\(", expression[chain_start:], re.IGNORECASE
            ):
                method = method_match.group(1).lower()
                if state == "builder":
                    if method in {name.lower() for name in PIPELINE_RUN_BUILDER_METHODS}:
                        continue
                    if method in {name.lower() for name in PIPELINE_RUN_MODEL_RETRIEVALS}:
                        state = "existing"
                        continue
                    if method in {name.lower() for name in PIPELINE_RUN_COLLECTION_RETRIEVALS}:
                        state = "collection"
                        continue
                    if method in {name.lower() for name in PIPELINE_RUN_VALUE_RETRIEVALS}:
                        return None
                    if method in {name.lower() for name in PIPELINE_RUN_BUILDER_MUTATIONS}:
                        return None
                    return "creation"
                if state == "collection":
                    if method in {
                        name.lower() for name in PIPELINE_RUN_COLLECTION_MODEL_EXTRACTIONS
                    }:
                        state = "existing"
                        continue
                    if method in MUTATORS.lower().split("|"):
                        return "creation"
                    # Collection/LazyCollection transforms, including macros and
                    # future APIs, remain model-provenance-tainted by default.
                    # This deliberately prefers a false positive to allowing an
                    # unknown chain to launder PipelineRun provenance.
                    continue
                if state == "existing":
                    if method in {
                        name.lower()
                        for name in PIPELINE_RUN_EXISTING_FLUENT_METHODS
                        | PIPELINE_RUN_EXISTING_MUTATIONS
                    }:
                        continue
                    if method in {name.lower() for name in PIPELINE_RUN_RELATION_ESCAPES}:
                        return None
                    return "creation"
                return "creation"
            # Support direct extraction such as PipelineRun::get()[0] and an
            # arbitrary collection transform followed by array access.
            if state == "collection" and re.search(r"\)\s*\[[^]]+\]\s*\Z", expression, re.DOTALL):
                return "existing"
            return state

        changed = True
        while changed:
            changed = False
            for statement_match in statements:
                assignment = re.match(
                    r"\s*\$(\w+)\s*=\s*(.*)\Z", statement_match.group(1), re.DOTALL
                )
                if assignment is None:
                    continue
                destination, expression = assignment.groups()
                state = classify_expression(expression)
                if state is not None and states.get(destination) != state:
                    states[destination] = state
                    changed = True

        for alias, state in states.items():
            if state == "builder":
                allowed_mutations = PIPELINE_RUN_BUILDER_MUTATIONS
            elif state == "existing":
                allowed_mutations = PIPELINE_RUN_EXISTING_MUTATIONS
            else:
                allowed_mutations = set()

            for match in re.finditer(
                rf"\${re.escape(alias)}\s*->\s*([A-Za-z_]\w*)\s*\(", code, re.IGNORECASE
            ):
                method = match.group(1)
                lowered = method.lower()
                if lowered in MUTATORS.lower().split("|") and method not in allowed_mutations:
                    violations.append(
                        Violation(
                            relative,
                            _line(code, match.start()),
                            "creation-tainted PipelineRun mutation",
                        )
                    )
                if state == "builder" and lowered not in {
                    name.lower()
                    for name in (
                        PIPELINE_RUN_BUILDER_METHODS
                        | PIPELINE_RUN_MODEL_RETRIEVALS
                        | PIPELINE_RUN_VALUE_RETRIEVALS
                        | PIPELINE_RUN_BUILDER_MUTATIONS
                    )
                }:
                    violations.append(
                        Violation(relative, _line(code, match.start()), "unknown builder factory")
                    )

            # Inspect the complete fluent chain, not only the first call. This
            # catches `$run->replicate()->save()` and
            # `$builder->where(...)->make()` without enumerating factories.
            for chain_match in re.finditer(
                rf"\${re.escape(alias)}\s*(?P<chain>(?:->\s*[A-Za-z_]\w*\s*\([^;]*?\))+)",
                code,
                re.IGNORECASE | re.DOTALL,
            ):
                chain_state = state
                for called in re.findall(
                    r"->\s*([A-Za-z_]\w*)\s*\(", chain_match.group("chain"), re.IGNORECASE
                ):
                    lowered = called.lower()
                    if chain_state == "builder":
                        if lowered in {name.lower() for name in PIPELINE_RUN_BUILDER_METHODS}:
                            continue
                        if lowered in {name.lower() for name in PIPELINE_RUN_MODEL_RETRIEVALS}:
                            chain_state = "existing"
                            continue
                        if lowered in {name.lower() for name in PIPELINE_RUN_COLLECTION_RETRIEVALS}:
                            chain_state = "collection"
                            continue
                        if lowered in {
                            name.lower()
                            for name in PIPELINE_RUN_VALUE_RETRIEVALS
                            | PIPELINE_RUN_BUILDER_MUTATIONS
                        }:
                            break
                        violations.append(
                            Violation(
                                relative,
                                _line(code, chain_match.start()),
                                "unknown builder factory",
                            )
                        )
                        break
                    if chain_state == "collection":
                        if lowered in {
                            name.lower() for name in PIPELINE_RUN_COLLECTION_MODEL_EXTRACTIONS
                        }:
                            chain_state = "existing"
                            continue
                        if lowered in MUTATORS.lower().split("|"):
                            violations.append(
                                Violation(
                                    relative,
                                    _line(code, chain_match.start()),
                                    "creation-tainted PipelineRun mutation",
                                )
                            )
                            break
                        # Unknown transforms retain collection provenance.
                        continue
                    if chain_state == "existing":
                        if lowered in {
                            name.lower()
                            for name in PIPELINE_RUN_EXISTING_FLUENT_METHODS | allowed_mutations
                        }:
                            continue
                        if lowered in {name.lower() for name in PIPELINE_RUN_RELATION_ESCAPES}:
                            break
                        violations.append(
                            Violation(
                                relative,
                                _line(code, chain_match.start()),
                                "unknown existing-model factory",
                            )
                        )
                        break
                    break

            for method_alias, method in method_aliases.items():
                if method not in {name.lower() for name in allowed_mutations}:
                    for match in re.finditer(
                        rf"\${re.escape(alias)}\s*->\s*\${re.escape(method_alias)}\s*\(", code
                    ):
                        violations.append(
                            Violation(
                                relative,
                                _line(code, match.start()),
                                "aliased PipelineRun creation sink",
                            )
                        )

        # Fluent, unassigned builder factories are also forbidden. The first
        # unknown method after an explicit query root is creation-tainted.
        for statement_match in statements:
            statement = statement_match.group(1)
            if not pipeline_source(statement):
                continue
            state = classify_expression(statement.strip())
            if state == "creation":
                violations.append(
                    Violation(
                        relative, _line(code, statement_match.start()), "fluent PipelineRun factory"
                    )
                )
            if re.search(rf"->\s*(?:{CREATION_ONLY_MUTATORS})\s*\(", statement, re.IGNORECASE):
                violations.append(
                    Violation(
                        relative,
                        _line(code, statement_match.start()),
                        "fluent PipelineRun creation sink",
                    )
                )

    # Relationship create/save operations create or attach Pipeline Runs and
    # therefore belong exclusively to the sole Start owner, even inside files
    # allowed to mutate the lifecycle of an already-owned run.
    if relative != START_OWNER:
        for match in re.finditer(
            rf"(?:->|::)\s*(?:{relationship_pattern})\s*\(\s*\)"
            rf"(?:\s*->\s*[A-Za-z_]\w*\s*\([^;]*?\))*"
            rf"\s*->\s*(?:{CREATION_MUTATORS})\s*\(",
            code,
            re.IGNORECASE | re.DOTALL,
        ):
            violations.append(
                Violation(relative, _line(code, match.start()), "PipelineRun relationship write")
            )

    if relative not in PIPELINE_RUN_MUTATION_OWNERS:
        for alias in tainted:
            for match in re.finditer(
                rf"\${re.escape(alias)}\s*->(?:\s*[A-Za-z_]\w*\s*\([^;]*?\)\s*->)*"
                rf"\s*(?:{MUTATORS}|\{{?\s*\$\w+\s*\}}?)\s*\(",
                code,
                re.IGNORECASE | re.DOTALL,
            ):
                violations.append(
                    Violation(relative, _line(code, match.start()), "tainted PipelineRun write")
                )
        # Also reject a fluent source-to-sink chain with no intermediate
        # variable, including Container::getInstance()->make(...), arbitrary
        # Eloquent retrieval/scopes, and relationship chains.
        for statement_match in statements:
            statement = statement_match.group(1)
            if pipeline_source(statement) and re.search(
                rf"->\s*(?:{MUTATORS}|\{{?\s*\$\w+\s*\}}?)\s*\(",
                statement,
                re.IGNORECASE,
            ):
                violations.append(
                    Violation(
                        relative,
                        _line(code, statement_match.start()),
                        "fluent PipelineRun source write",
                    )
                )

    # Track query-builder aliases and helper-return aliases structurally so a
    # write cannot evade the owner rule by separating construction and use.
    table_aliases: set[str] = set()
    for match in re.finditer(
        r"\$(\w+)\s*=\s*DB\s*::\s*table\s*\(\s*['\"]pipeline_runs['\"]\s*\)", code
    ):
        table_aliases.add(match.group(1))
    changed = True
    while changed:
        changed = False
        for match in re.finditer(r"\$(\w+)\s*=\s*\$(\w+)\s*;", code):
            if match.group(2) in table_aliases and match.group(1) not in table_aliases:
                table_aliases.add(match.group(1))
                changed = True
    if relative not in PIPELINE_RUN_TABLE_MUTATION_OWNERS:
        for alias in table_aliases:
            for match in re.finditer(
                rf"\${re.escape(alias)}\s*->\s*(?:(?:{MUTATORS})|\{{?\s*\$\w+\s*\}}?)\s*\(",
                code,
            ):
                violations.append(
                    Violation(
                        relative, _line(code, match.start()), "pipeline_runs table alias write"
                    )
                )
        for match in re.finditer(rf"\b(?:{alias_pattern})\s*::\s*\{{?\s*\$\w+\s*\}}?\s*\(", code):
            violations.append(
                Violation(relative, _line(code, match.start()), "dynamic PipelineRun model method")
            )
        helpers = {
            match.group(1)
            for match in re.finditer(
                r"function\s+(\w+)\s*\([^)]*\)[^{]*\{[^}]*"
                r"(?:return\s+)?(?:PipelineRun\s*::\s*query\s*\(\s*\)|"
                r"DB\s*::\s*table\s*\(\s*['\"]pipeline_runs['\"]\s*\))",
                code,
                re.DOTALL,
            )
        }
        for helper in helpers:
            for match in re.finditer(
                rf"(?:\$this\s*->\s*)?{re.escape(helper)}\s*\([^)]*\)\s*->\s*(?:{MUTATORS})\s*\(",
                code,
            ):
                violations.append(
                    Violation(
                        relative, _line(code, match.start()), "pipeline_runs helper alias write"
                    )
                )

    # Dynamic table/model construction is forbidden for every productive PHP
    # write, not merely for names that happen to contain pipeline_runs today.
    for match in re.finditer(r"DB\s*::\s*table\s*\(\s*(?!['\"][^'\"]+['\"]\s*\))[^)]*\)", code):
        tail = code[match.end() : match.end() + 500]
        if re.search(rf"->\s*(?:{MUTATORS}|delete)\s*\(", tail):
            violations.append(
                Violation(relative, _line(code, match.start()), "dynamic DB table write")
            )
    for match in re.finditer(
        r"\$(\w+)\s*=\s*DB\s*::\s*table\s*\(\s*(?!['\"][^'\"]+['\"]\s*\))[^)]*\)", code
    ):
        if re.search(
            rf"\${re.escape(match.group(1))}\s*->\s*(?:{MUTATORS}|delete)\s*\(",
            code[match.end() :],
        ):
            violations.append(
                Violation(relative, _line(code, match.start()), "dynamic table alias write")
            )
    for match in re.finditer(r"\$(\w+)\s*=\s*[A-Za-z_\\][A-Za-z0-9_\\]*::class\s*;", code):
        if re.search(
            rf"\${re.escape(match.group(1))}\s*::\s*(?:{MUTATORS})\s*\(", code[match.end() :]
        ):
            violations.append(
                Violation(relative, _line(code, match.start()), "dynamic model class write")
            )
    for match in re.finditer(r"\bnew\s+\$\w+", code):
        violations.append(
            Violation(relative, _line(code, match.start()), "dynamic model construction")
        )
    for match in re.finditer(rf"\$\w+\s*::\s*(?:{MUTATORS})\s*\(", code):
        violations.append(
            Violation(relative, _line(code, match.start()), "dynamic model class write")
        )
    if relative not in PHP_REVIEWED_DYNAMIC_SQL_OWNERS:
        for match in re.finditer(r"DB\s*::\s*(?:insert|statement|unprepared)\s*\(\s*\$", code):
            violations.append(
                Violation(relative, _line(code, match.start()), "dynamic raw SQL write")
            )
        for match in re.finditer(
            r"DB\s*::\s*(?:insert|statement|unprepared)\s*\([^;]*(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)[^;]*\$",
            code,
            re.IGNORECASE | re.DOTALL,
        ):
            violations.append(
                Violation(relative, _line(code, match.start()), "interpolated raw SQL write")
            )

    normalized = re.sub(r"[\s`\"']+", " ", code).lower()
    if relative != START_OWNER and re.search(
        r"\binsert\s+into\s+(?:public\s*\.\s*)?pipeline_runs\b", normalized
    ):
        violations.append(Violation(relative, 1, "raw/formatted pipeline_runs INSERT"))
    if relative not in PIPELINE_RUN_TABLE_MUTATION_OWNERS:
        for match in re.finditer(
            r"DB\s*::\s*table\s*\(\s*['\"]pipeline_runs['\"]\s*\)\s*->\s*"
            rf"(?:{MUTATORS})\s*\(",
            code,
            re.IGNORECASE | re.DOTALL,
        ):
            violations.append(
                Violation(relative, _line(code, match.start()), "pipeline_runs query write")
            )
    return violations


def scan_repository(root: Path) -> tuple[list[Violation], Counter[tuple[str, str]]]:
    violations: list[Violation] = []
    legacy_matches: Counter[tuple[str, str]] = Counter()
    for path in productive_files(root):
        relative = path.relative_to(root).as_posix()
        try:
            text = path.read_text(encoding="utf-8")
        except (UnicodeDecodeError, OSError):
            continue
        if relative not in {
            "scripts/check_pipeline_start_ownership.py",
            "scripts/pipeline_start_legacy_fingerprints.json",
        }:
            legacy_matches.update(legacy_reference_fingerprints(relative, text))
        violations.extend(scan_text(relative, text))
        if path.suffix == ".py":
            violations.extend(scan_python(relative, text))
        elif path.suffix == ".php":
            violations.extend(scan_php(relative, text))
    return violations, legacy_matches


def main() -> int:
    root = Path(__file__).resolve().parents[1]
    violations, legacy_matches = scan_repository(root)
    baseline = load_legacy_fingerprint_baseline()
    if legacy_matches != baseline:
        added = legacy_matches - baseline
        removed = baseline - legacy_matches
        for (path, fingerprint), count in sorted(added.items()):
            print(f"Uninventoried legacy reference: {path} {fingerprint} (+{count})")
        for (path, fingerprint), count in sorted(removed.items()):
            print(f"Retired/changed legacy reference: {path} {fingerprint} (-{count})")
        return 1
    for violation in violations:
        print(f"{violation.path}:{violation.line}: {violation.rule}")
    return int(bool(violations))


if __name__ == "__main__":
    sys.exit(main())
