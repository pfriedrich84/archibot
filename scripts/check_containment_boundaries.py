#!/usr/bin/env python3
"""Deny-by-default containment guard for disabled and mutation-capable surfaces.

The inventories are deliberately exact.  A reviewed route, navigation, MCP,
provider, or Paperless-mutation boundary must be re-baselined explicitly rather
than silently growing under a new name.
"""

from __future__ import annotations

import argparse
import ast
import hashlib
import json
import re
from collections.abc import Iterable
from dataclasses import dataclass
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BASELINE = ROOT / "scripts/containment_boundary_inventory.json"

EXPOSURE_FILES = (
    "laravel/routes/web.php",
    "laravel/routes/settings.php",
    "laravel/routes/console.php",
    "app/mcp_server.py",
    "laravel/resources/js/components/AppSidebar.svelte",
    "laravel/resources/js/components/AppHeader.svelte",
    "laravel/resources/js/layouts/settings/Layout.svelte",
    "app/config.py",
    "laravel/app/Http/Controllers/Admin/SettingsController.php",
    "laravel/app/Http/Controllers/DashboardController.php",
    "laravel/app/Services/Settings/PythonRuntimeConfigExporter.php",
    "laravel/resources/js/pages/admin/Settings.svelte",
)
CENTRAL_PAPERLESS_CLIENTS = {
    "app/clients/paperless.py",
    "laravel/app/Services/Paperless/PaperlessClient.php",
}
SOURCE_SUFFIXES = {".py", ".php", ".js", ".ts", ".svelte", ".sh"}
EXCLUDED_SOURCE_PARTS = {
    ".git",
    ".agent-evidence",
    ".graphify",
    ".pi",
    ".venv",
    ".venv312",
    "__pycache__",
    "node_modules",
    "vendor",
    "tests",
}
CLI_REGISTRATION_METHODS = {
    # Typer/Click command decorators and programmatic registration.
    "command",
    "addcommand",
    "addtyper",
    # argparse subcommand registration.
    "addparser",
    "registercommand",
}
DISABLED_SURFACE_TERMS = {
    "assistant",
    "chat",
    "rag",
    "retrieval",
    "retrieve",
    "semanticsearch",
    "hybridsearch",
    "searchdocuments",
    "findsimilardocuments",
    "getdocument",
}
FORBIDDEN_DOCUMENT_FIELDS = {
    "content",
    "ocr",
    "file",
    "files",
    "version",
    "versions",
    "versionlabel",
    "storagepath",
    "storagepathname",
    "storagepathid",
}
MODEL_AUTH_TERMS = {
    "confidence",
    "judge",
    "llm",
    "model",
    "prediction",
    "probability",
    "score",
    "verdict",
}
SAFE_DOCUMENT_PATCH_FIELDS = {
    "title",
    "created",
    "createddate",
    "correspondent",
    "documenttype",
    "tags",
}
MANUAL_STORAGE_PATH_SEAMS = {
    "app/clients/paperless.py",
    "app/jobs/review_commit.py",
    "laravel/app/Services/Paperless/PaperlessClient.php",
}
REVIEWED_PYTHON_MUTATION_SEAMS = {"app/clients/paperless.py", "app/jobs/review_commit.py"}


@dataclass(frozen=True)
class Violation:
    path: str
    rule: str
    detail: str


def _norm(value: str) -> str:
    return re.sub(r"[^a-z0-9]", "", value.lower())


def _disabled_surface_identifier(value: str) -> bool:
    normalized = _norm(value)
    return any(
        normalized == term or normalized.startswith(term) or normalized.endswith(term)
        for term in DISABLED_SURFACE_TERMS
    )


def _sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def _discover_exposure_paths(root: Path) -> set[str]:
    """Find every file capable of registering or linking a disabled surface."""
    paths = set(EXPOSURE_FILES)
    for file_path in _iter_sources(root):
        relative = file_path.relative_to(root).as_posix()
        source = file_path.read_text(encoding="utf-8")
        lower_path = relative.lower()
        if lower_path.startswith("laravel/routes/") or (
            file_path.suffix == ".py" and "mcp" in file_path.stem.lower()
        ):
            paths.add(relative)
        if file_path.suffix.lower() in {".js", ".ts", ".svelte"} and (
            re.search(r"\b(?:href|navigate|route)\s*[:=(]", source, re.I)
            or re.search(r"from\s+['\"][^'\"]*(?:route|navigation)", source, re.I)
        ):
            paths.add(relative)
        if any(part in lower_path for part in ("config", "setting", "provider")) and re.search(
            r"\b(?:provider|model|prompt|endpoint|base_url|api_key)\b", source, re.I
        ):
            paths.add(relative)
    return paths


def _productive_python_creation(node: ast.AST, aliases: dict[str, str]) -> bool:
    """Find Command/Review/Pipeline creation through imports and local aliases."""
    productive_models = {"command", "reviewsuggestion", "pipelinerun"}
    model_variables: set[str] = set()
    changed = True
    while changed:
        changed = False
        for child in ast.walk(node):
            if not isinstance(child, (ast.Assign, ast.AnnAssign)) or child.value is None:
                continue
            targets = child.targets if isinstance(child, ast.Assign) else [child.target]
            value = child.value
            model_source = False
            if isinstance(value, ast.Call) and isinstance(value.func, ast.Name):
                model_source = _norm(aliases.get(value.func.id, value.func.id)) in productive_models
            elif isinstance(value, ast.Name):
                model_source = value.id in model_variables
            for target in targets:
                if (
                    model_source
                    and isinstance(target, ast.Name)
                    and target.id not in model_variables
                ):
                    model_variables.add(target.id)
                    changed = True
    for child in ast.walk(node):
        if not isinstance(child, ast.Call):
            continue
        if isinstance(child.func, ast.Name):
            resolved = _norm(aliases.get(child.func.id, child.func.id))
            if resolved in productive_models:
                return True
            continue
        if not isinstance(child.func, ast.Attribute):
            continue
        method = _norm(child.func.attr)
        owner = child.func.value
        if method in {"create", "save", "firstorcreate", "updateorcreate"} and isinstance(
            owner, ast.Name
        ):
            resolved = _norm(aliases.get(owner.id, owner.id))
            if resolved in productive_models or owner.id in model_variables:
                return True
        if method in {"add", "addall", "merge"}:
            for argument in child.args:
                if isinstance(argument, ast.Call) and isinstance(argument.func, ast.Name):
                    resolved = _norm(aliases.get(argument.func.id, argument.func.id))
                    if resolved in productive_models:
                        return True
    return False


def _php_model_pattern(source: str) -> str:
    models = {"Command", "ReviewSuggestion", "PipelineRun"}
    for match in re.finditer(
        r"\buse\s+[^;]+\\(?:Command|ReviewSuggestion|PipelineRun)\s+as\s+(\w+)\s*;",
        source,
        re.I,
    ):
        models.add(match.group(1))
    return "(?:" + "|".join(sorted(map(re.escape, models))) + ")"


def _is_authorization_name(value: str) -> bool:
    normalized = _norm(value)
    return any(
        term in normalized
        for term in (
            "authoriz",
            "permission",
            "eligible",
            "approv",
            "accept",
            "canchange",
            "canmutate",
            "canreview",
            "assertcan",
            "allows",
            "denies",
        )
    )


def _discover_authorization_command_paths(root: Path) -> set[str]:
    """Discover every productive Command/Review/Pipeline writer or authorization gate."""
    paths: set[str] = set()
    authorization = re.compile(
        r"(?:def|function)\s+[A-Za-z_]*(?:authoriz|permission|eligible|approv|accept|"
        r"can(?:change|mutate|review)|assertcan)[A-Za-z_]*\s*\(|"
        r"\b(?:Gate\s*::\s*(?:authorize|allows|denies)|authorize)\s*\(|"
        r"->\s*(?:can|allows|denies|check)\s*\(",
        re.I,
    )
    for file_path in _iter_sources(root):
        source = file_path.read_text(encoding="utf-8")
        creation = bool(
            re.search(
                r"\b(?:create_command|queue_command|dispatch_command|store_review_suggestion|"
                r"create_pipeline_run)\s*\(|"
                r"\bINSERT\s+INTO\s+(?:commands|review_suggestions|pipeline_runs)\b",
                source,
                re.I,
            )
        )
        if file_path.suffix == ".py":
            try:
                tree = ast.parse(source)
            except SyntaxError:
                creation = True
            else:
                imported: dict[str, str] = {}
                for node in ast.walk(tree):
                    if isinstance(node, ast.ImportFrom):
                        for item in node.names:
                            imported[item.asname or item.name] = item.name
                creation = creation or _productive_python_creation(tree, imported)
                creation = creation or any(
                    isinstance(node, ast.Call)
                    and _is_authorization_name(_call_name(node, imported))
                    for node in ast.walk(tree)
                )
        elif file_path.suffix == ".php":
            model = _php_model_pattern(source)
            creation = creation or bool(
                re.search(
                    rf"\b{model}\s*::[\s\S]{{0,100}}?"
                    r"(?:create|firstOrCreate|updateOrCreate)\s*\(|"
                    rf"\bnew\s+{model}\b[\s\S]{{0,800}}?->\s*save\s*\(|"
                    r"\bDB\s*::\s*table\s*\(\s*['\"]"
                    r"(?:commands|review_suggestions|pipeline_runs)['\"]\s*\)"
                    r"[\s\S]{0,160}?->\s*(?:insert|upsert|updateOrInsert)\s*\(",
                    source,
                    re.I,
                )
            )
        if creation or authorization.search(source):
            paths.add(file_path.relative_to(root).as_posix())
    return paths


def _python_qualified_name(node: ast.AST, aliases: dict[str, str]) -> str:
    """Resolve a callable/object expression through imports, variables and helpers."""
    if isinstance(node, ast.Name):
        return aliases.get(node.id, node.id)
    if isinstance(node, ast.Attribute):
        owner = _python_qualified_name(node.value, aliases)
        return f"{owner}.{node.attr}" if owner else node.attr
    if isinstance(node, ast.Call):
        return _python_qualified_name(node.func, aliases)
    if isinstance(node, ast.Await):
        return _python_qualified_name(node.value, aliases)
    return ast.unparse(node)


def _python_bindings(tree: ast.AST) -> tuple[dict[str, str], dict[str, str]]:
    """Resolve imports, client/helper aliases and string constants to a fixed point."""
    aliases: dict[str, str] = {}
    constants: dict[str, str] = {}
    for node in ast.walk(tree):
        if isinstance(node, ast.Import):
            for imported in node.names:
                aliases[imported.asname or imported.name.split(".")[0]] = imported.name
        elif isinstance(node, ast.ImportFrom):
            for imported in node.names:
                aliases[imported.asname or imported.name] = imported.name
        elif isinstance(node, ast.arg) and node.annotation is not None:
            annotation = _python_qualified_name(node.annotation, aliases)
            if any(client in _norm(annotation) for client in ("httpx", "requests", "aiohttp")):
                aliases[node.arg] = annotation

    # A bounded fixed point resolves constructor results, helper-returned clients,
    # and callable alias chains without allowing reassignments to oscillate.
    for _ in range(8):
        changed = False
        for function in (
            node
            for node in ast.walk(tree)
            if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef))
        ):
            for returned in (
                child for child in ast.walk(function) if isinstance(child, ast.Return)
            ):
                if returned.value is None:
                    continue
                resolved = _python_qualified_name(returned.value, aliases)
                if (
                    any(client in _norm(resolved) for client in ("httpx", "requests", "aiohttp"))
                    and aliases.get(function.name) != resolved
                ):
                    aliases[function.name] = resolved
                    changed = True
        for node in ast.walk(tree):
            if not isinstance(node, (ast.Assign, ast.AnnAssign)) or node.value is None:
                continue
            targets = node.targets if isinstance(node, ast.Assign) else [node.target]
            text = _constant_string(node.value, constants)
            alias: str | None = None
            if isinstance(node.value, (ast.Name, ast.Attribute, ast.Call, ast.Await)):
                alias = _python_qualified_name(node.value, aliases)
            if (
                isinstance(node.value, ast.Call)
                and _norm(_python_qualified_name(node.value.func, aliases)) == "getattr"
                and len(node.value.args) >= 2
            ):
                attribute = _constant_string(node.value.args[1], constants)
                if attribute is not None:
                    alias = f"{_python_qualified_name(node.value.args[0], aliases)}.{attribute}"
            for target in targets:
                if not isinstance(target, ast.Name):
                    continue
                if text is not None and constants.get(target.id) != text:
                    constants[target.id] = text
                    changed = True
                if alias is not None and aliases.get(target.id) != alias:
                    aliases[target.id] = alias
                    changed = True
        if not changed:
            break
    return aliases, constants


def _python_cli_registrations(source: str) -> list[ast.Call]:
    try:
        tree = ast.parse(source)
    except SyntaxError:
        return []
    aliases, _ = _python_bindings(tree)
    calls: list[ast.Call] = []
    for node in ast.walk(tree):
        if not isinstance(node, ast.Call):
            continue
        name = _norm(_call_name(node, aliases))
        if name in CLI_REGISTRATION_METHODS:
            calls.append(node)
    return calls


def _discover_cli_registration_paths(root: Path) -> set[str]:
    paths: set[str] = set()
    for file_path in _iter_sources(root):
        if file_path.suffix != ".py":
            continue
        source = file_path.read_text(encoding="utf-8")
        if _python_cli_registrations(source) or re.search(
            r"^\s*(?:COMMANDS|CLI_COMMANDS)\s*:\s*[^=]+?=\s*\{", source, re.M
        ):
            paths.add(file_path.relative_to(root).as_posix())
    return paths


def _looks_like_paperless_mutation(path: str, source: str) -> bool:
    """Conservatively discover direct or wrapped Paperless write callsites."""
    if Path(path).suffix == ".py":
        try:
            tree = ast.parse(source)
        except SyntaxError:
            return True
        aliases, constants = _python_bindings(tree)
        for node in ast.walk(tree):
            if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef)) and _norm(node.name) in {
                "patchdocument",
                "patchrevieweddocument",
                "patchdocumentfields",
            }:
                return True
            if not isinstance(node, ast.Call):
                continue
            name = _norm(_call_name(node, aliases))
            if name in {
                "patchdocument",
                "patchrevieweddocument",
                "createtag",
                "createcorrespondent",
                "createdocumenttype",
            }:
                return True
            if _python_http_write(node, constants, aliases)[0]:
                return True
        return False
    return _php_has_dynamic_generic_http_method(_strip_php_comments(source)) or bool(
        re.search(
            r"(?:->|::)\s*(?:patchDocument|patchReviewedDocument|patchDocumentFields|"
            r"createTag|createCorrespondent|createDocumentType)\s*\(|"
            r"(?:Http::|->)\s*(?:patch|put|send|request)\s*\([^;]{0,500}documents?",
            source,
            re.I | re.S,
        )
    )


def _discover_mutation_paths(root: Path) -> set[str]:
    return {
        file_path.relative_to(root).as_posix()
        for file_path in _iter_sources(root)
        if _looks_like_paperless_mutation(
            file_path.relative_to(root).as_posix(), file_path.read_text(encoding="utf-8")
        )
    }


def inventory(root: Path) -> dict[str, dict[str, str]]:
    return {
        "authorization_command_files": {
            path: _sha(root / path) for path in sorted(_discover_authorization_command_paths(root))
        },
        "cli_registration_files": {
            path: _sha(root / path) for path in sorted(_discover_cli_registration_paths(root))
        },
        "exposure_files": {
            path: _sha(root / path) for path in sorted(_discover_exposure_paths(root))
        },
        "paperless_mutation_files": {
            path: _sha(root / path) for path in sorted(_discover_mutation_paths(root))
        },
    }


def load_baseline(path: Path = BASELINE) -> dict[str, dict[str, str]]:
    return json.loads(path.read_text(encoding="utf-8"))


def _iter_sources(root: Path) -> Iterable[Path]:
    """Yield productive source repo-wide, excluding tests, dependencies and evidence."""
    for path in root.rglob("*"):
        if not path.is_file() or path.suffix.lower() not in SOURCE_SUFFIXES:
            continue
        relative_parts = path.relative_to(root).parts
        if any(part in EXCLUDED_SOURCE_PARTS for part in relative_parts):
            continue
        yield path


def _constant_string(node: ast.AST, constants: dict[str, str]) -> str | None:
    if isinstance(node, ast.Constant) and isinstance(node.value, str):
        return node.value
    if isinstance(node, ast.Name):
        return constants.get(node.id)
    if isinstance(node, ast.BinOp) and isinstance(node.op, ast.Add):
        left = _constant_string(node.left, constants)
        right = _constant_string(node.right, constants)
        return None if left is None or right is None else left + right
    if isinstance(node, ast.JoinedStr):
        pieces: list[str] = []
        for value in node.values:
            if not isinstance(value, ast.Constant) or not isinstance(value.value, str):
                return None
            pieces.append(value.value)
        return "".join(pieces)
    return None


def _call_name(node: ast.Call, aliases: dict[str, str]) -> str:
    target = node.func
    if isinstance(target, ast.Name):
        resolved = aliases.get(target.id, target.id)
        return resolved.rsplit(".", 1)[-1]
    if isinstance(target, ast.Attribute):
        resolved = aliases.get(target.attr, target.attr)
        return resolved.rsplit(".", 1)[-1]
    return "<dynamic>"


def _expression_identifiers(node: ast.AST) -> set[str]:
    return {
        _norm(child.id if isinstance(child, ast.Name) else child.attr)
        for child in ast.walk(node)
        if isinstance(child, (ast.Name, ast.Attribute))
    }


def _python_http_write(
    call: ast.Call, constants: dict[str, str], aliases: dict[str, str]
) -> tuple[bool, str]:
    """Deny receiver-agnostic ambiguous HTTP calls and document writes.

    Receiver inference is deliberately not a security boundary. Outside the two
    exact central-client files, every attribute ``request``/``send`` call must
    carry method and endpoint string literals at the call boundary. Attribute
    ``patch``/``put`` calls are denied when their endpoint is document-like.
    """
    qualified_call = _python_qualified_name(call.func, aliases)
    terminal_call = (
        call.func.attr
        if isinstance(call.func, ast.Attribute)
        else qualified_call.rsplit(".", 1)[-1]
    )
    # For an attribute call the syntax, not a same-named local binding, decides
    # which boundary is invoked. This prevents alias-map poisoning from hiding
    # ``receiver.request(...)`` or ``receiver.patch(...)``.
    name = _norm(terminal_call)
    attribute_call = isinstance(call.func, ast.Attribute) or "." in qualified_call
    method: str | None = None
    endpoint: ast.AST | None = None
    if name in {"patch", "put"}:
        method = name.upper()
        endpoint = (
            call.args[0]
            if call.args
            else next(
                (kw.value for kw in call.keywords if kw.arg in {"url", "uri", "endpoint"}), None
            )
        )
    elif name in {"request", "send"} and terminal_call in {"request", "send"} and attribute_call:
        method_node = (
            call.args[0]
            if call.args
            else next((kw.value for kw in call.keywords if kw.arg == "method"), None)
        )
        endpoint = (
            call.args[1]
            if len(call.args) > 1
            else next(
                (kw.value for kw in call.keywords if kw.arg in {"url", "uri", "endpoint"}), None
            )
        )
        # Constant propagation is intentionally forbidden here: variables,
        # joins, concatenations, class attributes and helper returns are all
        # computed at the call boundary and therefore fail closed.
        literal_method = (
            method_node.value
            if isinstance(method_node, ast.Constant) and isinstance(method_node.value, str)
            else None
        )
        literal_endpoint = (
            endpoint.value
            if isinstance(endpoint, ast.Constant) and isinstance(endpoint.value, str)
            else None
        )
        if literal_method is None:
            return True, "<dynamic HTTP method>"
        if literal_endpoint is None:
            return True, "<dynamic HTTP endpoint>"
        method = literal_method
    if method is None or method.upper() not in {"PATCH", "PUT"}:
        return False, ""
    endpoint_text = _constant_string(endpoint, constants) if endpoint is not None else None
    endpoint_ids = _expression_identifiers(endpoint) if endpoint is not None else set()
    endpoint_literals = {
        child.value
        for child in ast.walk(endpoint or ast.Constant(value=""))
        if isinstance(child, ast.Constant) and isinstance(child.value, str)
    }
    documents = (endpoint_text is not None and "document" in _norm(endpoint_text)) or any(
        "document" in _norm(piece) for piece in endpoint_literals
    )
    dynamic_endpoint = endpoint_text is None and bool(
        endpoint_ids
        & {
            "url",
            "uri",
            "endpoint",
            "path",
            "target",
            "documentid",
            "documenturl",
            "documentendpoint",
            "paperlessurl",
        }
    )
    return documents or dynamic_endpoint, endpoint_text or "<dynamic endpoint>"


def _cli_registration_name(
    call: ast.Call, constants: dict[str, str], fallback: str | None = None
) -> str | None:
    candidate = (
        call.args[0]
        if call.args
        else next(
            (kw.value for kw in call.keywords if kw.arg in {"name", "command", "command_name"}),
            None,
        )
    )
    if candidate is None:
        return fallback
    return _constant_string(candidate, constants)


def scan_python(path: str, source: str) -> list[Violation]:
    violations: list[Violation] = []
    try:
        tree = ast.parse(source)
    except SyntaxError as exc:
        return [Violation(path, "unparseable Python in containment scope", str(exc))]

    aliases, constants = _python_bindings(tree)
    dictionaries: dict[str, ast.Dict] = {}
    for _ in range(8):
        changed = False
        for node in ast.walk(tree):
            if not isinstance(node, (ast.Assign, ast.AnnAssign)) or node.value is None:
                continue
            targets = node.targets if isinstance(node, ast.Assign) else [node.target]
            candidate = node.value
            if isinstance(candidate, ast.Name):
                candidate = dictionaries.get(candidate.id, candidate)
            for target in targets:
                if (
                    isinstance(target, ast.Name)
                    and isinstance(candidate, ast.Dict)
                    and dictionaries.get(target.id) is not candidate
                ):
                    dictionaries[target.id] = candidate
                    changed = True
        if not changed:
            break

    # Registrations may occur at module scope or behind aliased decorators.
    functions = {
        function.name: function
        for function in ast.walk(tree)
        if isinstance(function, (ast.FunctionDef, ast.AsyncFunctionDef))
    }
    function_by_decorator = {
        id(decorator): function
        for function in functions.values()
        for decorator in function.decorator_list
        if isinstance(decorator, ast.Call)
    }
    parents = {
        id(child): parent for parent in ast.walk(tree) for child in ast.iter_child_nodes(parent)
    }
    for call in (node for node in ast.walk(tree) if isinstance(node, ast.Call)):
        called = _norm(_call_name(call, aliases))
        if called in {
            "tool",
            "resource",
            "addtool",
            "registertool",
            "addresource",
            "registerresource",
        }:
            violations.append(
                Violation(path, "unreviewed MCP exposure", f"line {call.lineno}: {called}")
            )
        if called in CLI_REGISTRATION_METHODS:
            decorated = function_by_decorator.get(id(call))
            fallback = decorated.name if decorated is not None else None
            registered_name = _cli_registration_name(call, constants, fallback)
            if registered_name is None:
                violations.append(
                    Violation(path, "dynamic Python CLI registration", f"line {call.lineno}")
                )
            registration_source = f"{registered_name or ''} {ast.unparse(call)}"
            if decorated is not None:
                registration_source += " " + ast.unparse(decorated)
            parent = parents.get(id(call))
            if isinstance(parent, ast.Call) and parent.func is call:
                registration_source += " " + ast.unparse(parent)
            referenced_names = {child.id for child in ast.walk(call) if isinstance(child, ast.Name)}
            if isinstance(parent, ast.Call) and parent.func is call:
                referenced_names.update(
                    child.id for child in ast.walk(parent) if isinstance(child, ast.Name)
                )
            for name in sorted(referenced_names):
                registration_source += " " + aliases.get(name, name)
                if name in functions:
                    registration_source += " " + ast.unparse(functions[name])
            if any(
                _disabled_surface_identifier(token)
                for token in re.findall(r"[A-Za-z][A-Za-z0-9_-]*", registration_source)
            ):
                violations.append(
                    Violation(
                        path,
                        "disabled Chat/RAG Python CLI registration",
                        registered_name or "<dynamic>",
                    )
                )
            violations.append(
                Violation(path, "unreviewed Python CLI registration", f"line {call.lineno}")
            )

    # Module-level side effects are productive too. Function scanning below
    # covers nested calls; this pass closes the module-scope inventory bypass.
    for call in (child for child in ast.walk(tree) if isinstance(child, ast.Call)):
        parent = parents.get(id(call))
        inside_function = False
        while parent is not None:
            if isinstance(parent, (ast.FunctionDef, ast.AsyncFunctionDef, ast.Lambda)):
                inside_function = True
                break
            parent = parents.get(id(parent))
        if inside_function:
            continue
        is_http_write, endpoint = _python_http_write(call, constants, aliases)
        if is_http_write and path not in CENTRAL_PAPERLESS_CLIENTS:
            violations.append(
                Violation(path, "unreviewed Paperless mutation callsite", "module scope")
            )
            violations.append(
                Violation(path, "direct Paperless HTTP mutation outside central client", endpoint)
            )

    for node in ast.walk(tree):
        if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef)):
            calls = [child for child in ast.walk(node) if isinstance(child, ast.Call)]
            names = {_norm(_call_name(call, aliases)) for call in calls}
            direct_mutation = bool(
                names & {"patchdocument", "patchrevieweddocument", "patchdocumentfields"}
            )
            http_mutations = [
                _python_http_write(call, constants, aliases)
                for call in calls
                if _python_http_write(call, constants, aliases)[0]
            ]
            mutation = direct_mutation or bool(http_mutations)
            paperless_mutation = mutation or bool(
                names & {"createtag", "createcorrespondent", "createdocumenttype"}
            )
            command_creation = bool(
                names & {"createcommand", "queuecommand", "dispatchcommand"}
            ) or _productive_python_creation(node, aliases)
            identifiers = {
                _norm(child.id) for child in ast.walk(node) if isinstance(child, ast.Name)
            }
            identifiers |= {
                _norm(child.attr) for child in ast.walk(node) if isinstance(child, ast.Attribute)
            }
            identifiers |= {
                _norm(child.arg) for child in ast.walk(node) if isinstance(child, ast.arg)
            }
            # Compound aliases such as model_score and neutral_score remain
            # semantic. Framework bookkeeping such as Pydantic's
            # model_fields_set is not an AI authorization input.
            semantic_identifiers = identifiers - {"modelfields", "modelfieldsset"}
            semantic_terms = {
                term
                for term in MODEL_AUTH_TERMS
                if any(
                    identifier == term or identifier.startswith(term) or identifier.endswith(term)
                    for identifier in semantic_identifiers
                )
            }
            authorization_path = _is_authorization_name(node.name) or any(
                _is_authorization_name(_call_name(call, aliases)) for call in calls
            )
            if (mutation or command_creation or authorization_path) and semantic_terms:
                violations.append(
                    Violation(
                        path, "model-derived authorization reaches command/mutation seam", node.name
                    )
                )

            if paperless_mutation and path not in CENTRAL_PAPERLESS_CLIENTS:
                violations.append(
                    Violation(path, "unreviewed Paperless mutation callsite", node.name)
                )
            if http_mutations and path not in CENTRAL_PAPERLESS_CLIENTS:
                for _, endpoint in http_mutations:
                    violations.append(
                        Violation(
                            path,
                            "direct Paperless HTTP mutation outside central client",
                            endpoint,
                        )
                    )

            for call in calls:
                called = _norm(_call_name(call, aliases))
                if called in {
                    "patchdocument",
                    "patchrevieweddocument",
                    "patchdocumentfields",
                    "patch",
                    "put",
                    "request",
                    "send",
                }:
                    if (
                        called in {"patch", "put", "request", "send"}
                        and not _python_http_write(call, constants, aliases)[0]
                    ):
                        continue
                    args = list(call.args)
                    args.extend(
                        keyword.value
                        for keyword in call.keywords
                        if keyword.arg in {"json", "data", "fields"}
                    )
                    payload_seen = False
                    for arg in args:
                        candidate = dictionaries.get(arg.id) if isinstance(arg, ast.Name) else arg
                        if isinstance(candidate, ast.Dict):
                            payload_seen = True
                            for key in candidate.keys:
                                if key is None:
                                    violations.append(
                                        Violation(
                                            path,
                                            "dynamic Paperless mutation payload",
                                            f"line {call.lineno}",
                                        )
                                    )
                                    continue
                                value = _constant_string(key, constants)
                                if value is None:
                                    violations.append(
                                        Violation(
                                            path,
                                            "dynamic Paperless mutation key",
                                            f"line {call.lineno}",
                                        )
                                    )
                                elif _norm(value) in FORBIDDEN_DOCUMENT_FIELDS:
                                    manual_storage = _norm(value) == "storagepath" and (
                                        (
                                            path == "app/jobs/review_commit.py"
                                            and node.name == "build_paperless_patch"
                                        )
                                        or (
                                            path == "app/clients/paperless.py"
                                            and node.name
                                            in {"patch_reviewed_document", "_patch_document"}
                                        )
                                    )
                                    if not manual_storage:
                                        violations.append(
                                            Violation(
                                                path, "prohibited Paperless document field", value
                                            )
                                        )
                                elif _norm(value) not in SAFE_DOCUMENT_PATCH_FIELDS:
                                    violations.append(
                                        Violation(
                                            path,
                                            "field outside reviewed manual mutation seam",
                                            value,
                                        )
                                    )
                    if not payload_seen and path not in REVIEWED_PYTHON_MUTATION_SEAMS:
                        violations.append(
                            Violation(
                                path,
                                "unreviewed or dynamic Paperless mutation payload",
                                f"line {call.lineno}",
                            )
                        )

    # Exposure checks are lexical in exposure-bearing files so aliases such as
    # `/assistant`, provider settings and future registration APIs fail.
    lower_path = path.lower()
    if any(
        part in lower_path
        for part in (
            "route",
            "nav",
            "menu",
            "sidebar",
            "header",
            "config",
            "setting",
            "provider",
            "mcp",
        )
    ):
        for token in re.findall(r"[A-Za-z][A-Za-z0-9_-]*", source):
            if _disabled_surface_identifier(token):
                violations.append(Violation(path, "disabled Chat/RAG surface token", token))
    return violations


def _strip_php_comments(source: str) -> str:
    return re.sub(r"/\*.*?\*/|//[^\n]*|#[^\n]*", "", source, flags=re.S)


def _php_has_dynamic_generic_http_method(code: str) -> bool:
    """Find receiver-agnostic non-literal object request/send boundaries."""
    static_clients = {"Http"}
    client_classes = {"Client", "GuzzleHttp\\Client"}
    for match in re.finditer(
        r"\buse\s+Illuminate\\Support\\Facades\\Http(?:\s+as\s+(\w+))?\s*;", code, re.I
    ):
        static_clients.add(match.group(1) or "Http")
    for match in re.finditer(
        r"\buse\s+GuzzleHttp\\Client(?:Interface)?(?:\s+as\s+(\w+))?\s*;", code, re.I
    ):
        client_classes.add(match.group(1) or "Client")

    object_clients = {"http", "client", "session", "guzzle", "transport"}
    class_pattern = "|".join(sorted(map(re.escape, client_classes), key=len, reverse=True))
    static_pattern = "|".join(sorted(map(re.escape, static_clients), key=len, reverse=True))
    for match in re.finditer(
        rf"\$(\w+)\s*=\s*new\s+(?:\\?{class_pattern})\b|"
        rf"\$(\w+)\s*=\s*(?:app|resolve|make)\s*\(\s*(?:{class_pattern})::class",
        code,
        re.I,
    ):
        object_clients.add(next(group for group in match.groups() if group).lower())
    for match in re.finditer(rf"\$(\w+)\s*=\s*(?:{static_pattern})\s*::\s*\w+\s*\(", code, re.I):
        object_clients.add(match.group(1).lower())

    # Propagate helper-returned clients and ordinary variable aliases.
    client_helpers: set[str] = set()
    for match in re.finditer(r"function\s+(\w+)\s*\([^)]*\)\s*\{([\s\S]*?)\}", code, re.I):
        body = match.group(2)
        returns_guzzle = re.search(
            rf"return\s+(?:new\s+(?:\\?{class_pattern})\b|"
            rf"(?:app|resolve|make)\s*\(\s*(?:{class_pattern})::class)",
            body,
            re.I,
        )
        returns_laravel_http = re.search(
            rf"return\s+(?:{static_pattern})\s*::\s*\w+\s*\(", body, re.I
        )
        if returns_guzzle or returns_laravel_http:
            client_helpers.add(match.group(1))
    for _ in range(8):
        changed = False
        for match in re.finditer(r"\$(\w+)\s*=\s*(\w+)\s*\([^;]*\)\s*;", code):
            if match.group(2) in client_helpers and match.group(1).lower() not in object_clients:
                object_clients.add(match.group(1).lower())
                changed = True
        for match in re.finditer(r"\$(\w+)\s*=\s*\$(\w+)\s*;", code):
            if (
                match.group(2).lower() in object_clients
                and match.group(1).lower() not in object_clients
            ):
                object_clients.add(match.group(1).lower())
                changed = True
        if not changed:
            break

    object_pattern = "|".join(sorted(map(re.escape, object_clients), key=len, reverse=True))
    # Object receivers are intentionally unrestricted: an arbitrary class,
    # nested property, factory return or chained constructor must not bypass
    # the policy. Laravel's static facade remains covered as well.
    receiver = rf"(?:(?:{static_pattern})\s*::|->\s*)"
    for match in re.finditer(
        receiver + r"(?:request|send)\s*\(\s*([^,\r\n)]+)(?:,\s*([^,\r\n)]+))?",
        code,
        re.I,
    ):
        method_expression = match.group(1).strip()
        endpoint_expression = match.group(2).strip() if match.group(2) else None
        literal = r"(['\"])(?:\\.|(?!\1).)*\1"
        if not re.fullmatch(literal, method_expression) or not (
            endpoint_expression and re.fullmatch(literal, endpoint_expression)
        ):
            return True

    # Callable aliases preserve the same rule: the HTTP method argument itself
    # must still be a literal at invocation.
    callable_names = {
        match.group(1)
        for match in re.finditer(
            rf"\$(\w+)\s*=\s*\[\s*(?:\$(?:{object_pattern})|(?:{static_pattern})::class)\s*,"
            r"\s*['\"](?:request|send)['\"]\s*\]",
            code,
            re.I,
        )
    }
    for callable_name in callable_names:
        for match in re.finditer(rf"\${re.escape(callable_name)}\s*\(\s*([^,\r\n]+)", code):
            if not re.fullmatch(r"(['\"])(?:\\.|(?!\1).)*\1", match.group(1).strip()):
                return True
    return False


def scan_text(path: str, source: str) -> list[Violation]:
    """Conservative token/dataflow checks for PHP, TS, JS, Svelte and helpers."""
    violations: list[Violation] = []
    code = _strip_php_comments(source) if Path(path).suffix.lower() == ".php" else source
    lower_path = path.lower()
    exposure = any(
        part in lower_path
        for part in (
            "route",
            "mcp",
            "nav",
            "menu",
            "sidebar",
            "header",
            "config",
            "setting",
            "provider",
        )
    )
    if exposure:
        tokens = {_norm(token) for token in re.findall(r"[A-Za-z][A-Za-z0-9_-]*", code)}
        for token in sorted(tokens):
            if _disabled_surface_identifier(token):
                violations.append(Violation(path, "disabled Chat/RAG surface token", token))
        if "route" in lower_path:
            for match in re.finditer(
                r"Route::(?:get|post|put|patch|delete|any|match|view|inertia)\s*\(([^,\n]+)",
                code,
                re.I,
            ):
                first = match.group(1).strip()
                if not re.fullmatch(r"['\"][^'\"]*['\"]", first):
                    violations.append(Violation(path, "dynamic route path", first[:80]))

    # Fold adjacent quoted-string concatenations before inspecting keys.
    folded = code
    concat = re.compile(r"(['\"])([^'\"]*)\1\s*\.\s*(['\"])([^'\"]*)\3")
    while concat.search(folded):
        folded = concat.sub(lambda m: repr(m.group(2) + m.group(4)), folded)

    central_call = re.search(
        r"(?:->|::|\.)\s*(?:patchDocument|patchReviewedDocument|patchDocumentFields)\s*\(",
        folded,
        re.I,
    ) or (
        re.search(r"->\s*\$[A-Za-z_]\w*\s*\(", folded)
        and re.search(r"patch(?:Reviewed)?Document(?:Fields)?|paperless", folded, re.I)
    )
    document_endpoint = re.search(
        r"(?:api/)?documents?\s*/|documents?\s*\{?\$|"
        r"\bdocument[A-Za-z_]*(?:\s*\(|\s*\.\s*|\s*\$)",
        folded,
        re.I,
    )
    generic_http_write = re.search(
        r"(?:Http\s*::|->)\s*(?:patch|put)\s*\(|"
        r"(?:Http\s*::|->)\s*(?:send|request)\s*\(\s*['\"](?:PATCH|PUT)['\"]|"
        r"->\s*\$[A-Za-z_]\w*\s*\(|"
        r"\$([A-Za-z_]\w*)\s*=\s*\[\s*\$[A-Za-z_]\w*\s*,\s*"
        r"['\"](?:patch|put)['\"]\s*\][\s\S]{0,240}?\$\1\s*\(|"
        r"\$([A-Za-z_]\w*)\s*=\s*\[\s*\$[A-Za-z_]\w*\s*,\s*"
        r"['\"](?:send|request)['\"]\s*\][\s\S]{0,240}?"
        r"\$\2\s*\(\s*['\"](?:PATCH|PUT)['\"]|"
        r"\$([A-Za-z_]\w*)\s*=\s*['\"](?:patch|put)['\"]\s*;"
        r"[\s\S]{0,160}?(?:Http\s*::|\$[A-Za-z_]\w*->)\s*\$\3\s*\(|"
        r"\$([A-Za-z_]\w*)\s*=\s*['\"](?:PATCH|PUT)['\"]\s*;"
        r"[\s\S]{0,160}?(?:Http\s*::|\$[A-Za-z_]\w*->)\s*"
        r"(?:send|request)\s*\(\s*\$\4\b|"
        r"call_user_func\s*\(\s*\[\s*\$[A-Za-z_]\w*\s*,\s*"
        r"['\"](?:patch|put)['\"]\s*\]\s*,|"
        r"call_user_func\s*\(\s*\[\s*\$[A-Za-z_]\w*\s*,\s*"
        r"['\"](?:send|request)['\"]\s*\]\s*,\s*['\"](?:PATCH|PUT)['\"]",
        folded,
        re.I,
    )
    dynamic_http_method = _php_has_dynamic_generic_http_method(code)
    dynamic_endpoint_write = bool(generic_http_write) and bool(
        document_endpoint
        or re.search(r"\$(?:url|uri|endpoint|path|documentUrl|documentEndpoint)\b", folded, re.I)
    )
    unsafe_generic_http = dynamic_http_method or dynamic_endpoint_write
    mutation_call = central_call or unsafe_generic_http
    paperless_mutation = mutation_call or re.search(
        r"(?:->|::|\.)\s*(?:createTag|createCorrespondent|createDocumentType|createEntity)\s*\(|(?:->|\.)\s*post\s*\([^)]{0,240}/api/(?:tags|correspondents|document_types|documents?)/",
        folded,
        re.I | re.S,
    )
    if paperless_mutation and path not in CENTRAL_PAPERLESS_CLIENTS:
        violations.append(
            Violation(path, "unreviewed Paperless mutation callsite", "security review required")
        )
    if unsafe_generic_http and path not in CENTRAL_PAPERLESS_CLIENTS:
        detail = (
            "non-literal request/send method on recognized generic HTTP client"
            if dynamic_http_method
            else "PATCH/PUT/send/request to dynamic or document endpoint"
        )
        violations.append(
            Violation(path, "direct Paperless HTTP mutation outside central client", detail)
        )
    productive_model = _php_model_pattern(folded)
    command_call = re.search(
        rf"\b{productive_model}\s*::"
        r"(?:\s*query\s*\(\)\s*->\s*)?"
        r"(?:create|firstOrCreate|updateOrCreate)\s*\(|"
        rf"\bnew\s+{productive_model}\b"
        r"[\s\S]{0,800}?->\s*save\s*\(|"
        r"\bDB\s*::\s*table\s*\(\s*['\"]"
        r"(?:commands|review_suggestions|pipeline_runs)['\"]\s*\)"
        r"[\s\S]{0,160}?->\s*(?:insert|upsert|updateOrInsert)\s*\(",
        folded,
        re.I,
    )
    authorization_path = re.search(
        r"function\s+[A-Za-z_]*(?:authoriz|permission|eligible|approv|accept)[A-Za-z_]*\s*\(|"
        r"\bGate\s*::\s*(?:authorize|allows|denies)\s*\(|"
        r"->\s*(?:can|allows|denies|check)\s*\(",
        folded,
        re.I,
    )
    if mutation_call or command_call or authorization_path:
        identifiers = {_norm(item) for item in re.findall(r"[A-Za-z_][A-Za-z0-9_]*", folded)}
        identifiers -= {"modelfields", "modelfieldsset"}
        bad = {
            term
            for term in MODEL_AUTH_TERMS
            if any(
                identifier == term or identifier.startswith(term) or identifier.endswith(term)
                for identifier in identifiers
            )
        }
        if bad:
            violations.append(
                Violation(
                    path,
                    "model-derived authorization reaches command/mutation seam",
                    ",".join(sorted(bad)),
                )
            )

    if mutation_call:
        payload_segments: list[str] = []
        payload_variables = {
            match.group(1)
            for match in re.finditer(
                r"patch(?:Reviewed)?Document(?:Fields)?\s*\([^;]*?,\s*\$([A-Za-z_]\w*)\s*\)",
                folded,
                re.I | re.S,
            )
        }
        payload_variables.update({"fields", "payload", "patch", "body", "data"})
        for variable in payload_variables:
            payload_segments.extend(
                match.group(1)
                for match in re.finditer(
                    rf"\${re.escape(variable)}\s*=\s*\[(.*?)\]\s*;",
                    folded,
                    re.I | re.S,
                )
            )
        payload_segments.extend(
            match.group(1)
            for match in re.finditer(
                r"patch(?:Reviewed)?Document(?:Fields)?\s*\([^;]*?,\s*\[(.*?)\]\s*\)",
                folded,
                re.I | re.S,
            )
        )
        for segment in payload_segments:
            for quoted in re.findall(r"['\"]([^'\"]+)['\"]\s*=>", segment):
                key = _norm(quoted)
                if key in FORBIDDEN_DOCUMENT_FIELDS:
                    manual_storage = (
                        key == "storagepath"
                        and path == "laravel/app/Services/Paperless/PaperlessClient.php"
                        and bool(re.search(r"patchReviewedDocument\s*\(", folded, re.I))
                        and not bool(
                            re.search(
                                r"patchDocument\s*\([^;]*" + re.escape(segment), folded, re.I | re.S
                            )
                        )
                    )
                    if not manual_storage:
                        violations.append(
                            Violation(path, "prohibited Paperless document field", quoted)
                        )
                elif key not in SAFE_DOCUMENT_PATCH_FIELDS:
                    violations.append(
                        Violation(path, "field outside reviewed manual mutation seam", quoted)
                    )
        if re.search(r"\$(?:fields|payload|patch|body|data)\s*\[\s*\$[^\]]+\]\s*=", folded, re.I):
            violations.append(
                Violation(path, "dynamic Paperless mutation key", "computed mutation key")
            )

    # No browser/helper source may introduce a direct document mutation.
    if Path(path).suffix.lower() in {".js", ".ts", ".svelte", ".sh"}:
        has_document_endpoint = bool(re.search(r"(?:api/)?documents?/", folded, re.I))
        has_http_write = bool(
            re.search(r"\b(?:PATCH|PUT)\b|(?:->|\.)\s*(?:patch|put)\s*\(", folded, re.I)
        )
        if has_document_endpoint and has_http_write:
            violations.append(
                Violation(
                    path,
                    "direct Paperless document mutation outside reviewed seam",
                    "browser/helper HTTP write",
                )
            )
    return violations


def scan_repository(root: Path = ROOT, baseline_path: Path | None = None) -> list[Violation]:
    violations: list[Violation] = []
    baseline = load_baseline(
        baseline_path or (root / "scripts/containment_boundary_inventory.json")
    )
    actual = inventory(root)
    for section in (
        "authorization_command_files",
        "cli_registration_files",
        "exposure_files",
        "paperless_mutation_files",
    ):
        expected_section = baseline.get(section, {})
        actual_section = actual.get(section, {})
        if actual_section != expected_section:
            for path in sorted(set(expected_section) | set(actual_section)):
                if expected_section.get(path) != actual_section.get(path):
                    violations.append(
                        Violation(
                            path,
                            f"{section} exact fingerprint changed",
                            "security review and explicit re-baseline required",
                        )
                    )

    for file_path in _iter_sources(root):
        relative = file_path.relative_to(root).as_posix()
        source = file_path.read_text(encoding="utf-8")
        if file_path.suffix.lower() == ".py":
            found = scan_python(relative, source)
        else:
            found = scan_text(relative, source)

        # Legacy classification/OCR provider vocabulary may remain only in an
        # unchanged, explicitly fingerprinted exposure file. Any edit requires
        # review, and every new/renamed exposure file is scanned deny-by-default.
        file_hash = _sha(file_path)
        expected_hash = baseline.get("exposure_files", {}).get(relative)
        if expected_hash == file_hash:
            found = [item for item in found if item.rule != "disabled Chat/RAG surface token"]
        command_hash = baseline.get("authorization_command_files", {}).get(relative)
        if command_hash == file_hash:
            found = [
                item
                for item in found
                if item.rule != "model-derived authorization reaches command/mutation seam"
            ]
        cli_hash = baseline.get("cli_registration_files", {}).get(relative)
        if cli_hash == file_hash:
            found = [item for item in found if item.rule != "unreviewed Python CLI registration"]
        mutation_hash = baseline.get("paperless_mutation_files", {}).get(relative)
        if mutation_hash == file_hash:
            found = [
                item for item in found if item.rule != "unreviewed Paperless mutation callsite"
            ]
        violations.extend(found)
    return violations


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--write-baseline", action="store_true")
    args = parser.parse_args()
    if args.write_baseline:
        BASELINE.write_text(
            json.dumps(inventory(ROOT), indent=2, sort_keys=True) + "\n", encoding="utf-8"
        )
        return 0
    violations = scan_repository()
    for item in violations:
        print(f"{item.path}: {item.rule}: {item.detail}")
    return 1 if violations else 0


if __name__ == "__main__":
    raise SystemExit(main())
