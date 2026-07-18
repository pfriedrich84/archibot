"""Regression guards for productive PostgreSQL-only document/OCR actors."""

from __future__ import annotations

import ast
import importlib.util
import inspect
from pathlib import Path
from unittest.mock import AsyncMock

import pytest

from app import actor_runner
from app.actors import document, maintenance, webhook
from app.jobs import entity_approvals, ocr_corrections
from app.models import ClassificationResult, PaperlessDocument
from app.pipeline import classifier, judge, ocr_correction
from app.pipeline.processing_models import JudgeOutcome


@pytest.mark.parametrize(
    "module",
    [actor_runner, document, webhook, maintenance, classifier, judge, ocr_correction],
)
def test_productive_document_and_ocr_modules_do_not_reference_sqlite(module) -> None:
    source = inspect.getsource(module)
    forbidden = ("app" + ".db", "get_" + "conn", "classifier" + ".db")
    assert not any(value in source for value in forbidden), module.__name__


def _module_source_paths(root: Path, module: str) -> list[Path]:
    """Return the module and every package initializer Python would execute."""
    parts = module.split(".")
    candidates: list[Path] = []
    for index in range(1, len(parts) + 1):
        package = root.joinpath(*parts[:index]) / "__init__.py"
        if package.is_file():
            candidates.append(package)
    leaf = root.joinpath(*parts).with_suffix(".py")
    if leaf.is_file():
        candidates.append(leaf)
    return candidates


def _module_identity(root: Path, path: Path) -> tuple[str, str]:
    relative = path.relative_to(root).with_suffix("")
    parts = list(relative.parts)
    is_package = parts[-1] == "__init__"
    if is_package:
        parts.pop()
    module = ".".join(parts)
    package = module if is_package else module.rpartition(".")[0]
    return module, package


def _static_imports(root: Path, path: Path) -> set[str]:
    """Resolve Python imports exactly enough to traverse productive source.

    ImportFrom.level is interpreted against the importing module's package,
    including package ``__init__`` files and namespace-package directories.
    Both the imported base and possible imported submodules are returned.
    """
    _module, package = _module_identity(root, path)
    tree = ast.parse(path.read_text(encoding="utf-8"), filename=str(path))
    modules: set[str] = set()
    import_module_aliases = {"importlib.import_module"}
    importlib_aliases = {"importlib"}

    for node in ast.walk(tree):
        if isinstance(node, ast.Import):
            for alias in node.names:
                modules.add(alias.name)
                if alias.name == "importlib":
                    importlib_aliases.add(alias.asname or "importlib")
        elif isinstance(node, ast.ImportFrom):
            if node.level:
                relative_name = "." * node.level + (node.module or "")
                try:
                    base = importlib.util.resolve_name(relative_name, package)
                except (ImportError, ValueError):
                    continue
            else:
                base = node.module or ""
            if base:
                modules.add(base)
                modules.update(f"{base}.{alias.name}" for alias in node.names if alias.name != "*")
            if node.level == 0 and node.module == "importlib":
                import_module_aliases.update(
                    alias.asname or alias.name
                    for alias in node.names
                    if alias.name == "import_module"
                )

    for node in ast.walk(tree):
        if not isinstance(node, ast.Call) or not node.args:
            continue
        dotted = ""
        if isinstance(node.func, ast.Name):
            dotted = node.func.id
        elif (
            isinstance(node.func, ast.Attribute)
            and isinstance(node.func.value, ast.Name)
            and node.func.value.id in importlib_aliases
        ):
            dotted = f"importlib.{node.func.attr}"
        if dotted not in import_module_aliases and dotted != "__import__":
            continue
        requested = node.args[0].value if isinstance(node.args[0], ast.Constant) else None
        if not isinstance(requested, str):
            continue
        if requested.startswith(".") and dotted != "__import__":
            dynamic_package = package
            package_arg = node.args[1] if len(node.args) > 1 else None
            for keyword in node.keywords:
                if keyword.arg == "package":
                    package_arg = keyword.value
            if isinstance(package_arg, ast.Constant) and isinstance(package_arg.value, str):
                dynamic_package = package_arg.value
            try:
                requested = importlib.util.resolve_name(requested, dynamic_package)
            except (ImportError, ValueError):
                continue
        modules.add(requested)
    return {module for module in modules if module == "app" or module.startswith("app.")}


def _walk_import_graph(root: Path, entry: Path) -> tuple[set[Path], list[tuple[Path, str]]]:
    pending = [entry]
    visited: set[Path] = set()
    forbidden: list[tuple[Path, str]] = []
    while pending:
        path = pending.pop()
        if path in visited or not path.is_file():
            continue
        visited.add(path)
        source = path.read_text(encoding="utf-8")
        if "get_" + "conn" in source:
            forbidden.append((path, "get_" + "conn"))
        for module in _static_imports(root, path):
            if module == "app.db" or module.startswith("app.db."):
                forbidden.append((path, module))
            pending.extend(_module_source_paths(root, module))
    return visited, forbidden


def test_actor_runner_import_graph_cannot_reach_legacy_sqlite() -> None:
    root = Path(__file__).resolve().parents[1]
    visited, forbidden = _walk_import_graph(root, root / "app/actor_runner.py")
    assert forbidden == []
    assert root / "app/actors/document.py" in visited
    assert root / "app/actors/maintenance.py" in visited
    assert root / "app/pipeline/classifier.py" in visited
    assert root / "app/pipeline/ocr_correction.py" in visited


def test_import_graph_rejects_indirect_relative_app_db_through_namespace_storage() -> None:
    fixture = Path(__file__).parent / "fixtures/productive_import_graph"
    visited, forbidden = _walk_import_graph(fixture, fixture / "app/entry.py")
    assert fixture / "app/actors/__init__.py" in visited
    assert fixture / "app/legacy/storage.py" in visited
    assert any(module == "app.db" for _path, module in forbidden)
    assert any(path == fixture / "app/db.py" for path, _reason in forbidden)


def test_import_graph_rejects_constant_relative_dynamic_import() -> None:
    fixture = Path(__file__).parent / "fixtures/productive_import_graph"
    visited, forbidden = _walk_import_graph(fixture, fixture / "app/dynamic_entry.py")
    assert fixture / "app/db.py" in visited
    assert any(module == "app.db" for _path, module in forbidden)


def test_import_graph_executes_parent_initializers_for_direct_nested_import() -> None:
    fixture = Path(__file__).parent / "fixtures/productive_import_graph"
    visited, forbidden = _walk_import_graph(fixture, fixture / "app/direct_entry.py")
    assert fixture / "app/nested/storage/__init__.py" in visited
    assert fixture / "app/db.py" in visited
    assert any(module == "app.db" for _path, module in forbidden)


def test_blacklist_and_ocr_repositories_are_shared_postgresql_contracts() -> None:
    blacklist_source = inspect.getsource(entity_approvals)
    ocr_source = inspect.getsource(ocr_corrections)
    assert "FROM entity_approvals" in blacklist_source
    assert "status = 'rejected'" in blacklist_source
    assert "document_ocr_corrections" in ocr_source
    assert "ON CONFLICT (paperless_document_id) DO UPDATE" in ocr_source

    root = Path(__file__).resolve().parents[1]
    entity_migration = (
        root / "laravel/database/migrations/2026_05_05_000007_create_entity_approvals_table.php"
    )
    ocr_migration = (
        root
        / "laravel/database/migrations/2026_07_20_000000_create_document_ocr_corrections_table.php"
    )
    assert "Schema::create('entity_approvals'" in entity_migration.read_text()
    assert "Schema::create('document_ocr_corrections'" in ocr_migration.read_text()


@pytest.mark.asyncio
async def test_document_classification_and_ocr_reindex_leave_isolated_data_dir_empty(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    """Exercise both storage seams with no legacy database available."""
    monkeypatch.chdir(tmp_path)
    data_dir = tmp_path / "isolated-data"
    data_dir.mkdir()
    # Point the legacy DB-derived path at the isolated directory. If a productive
    # import or call accidentally reintroduces init_db/get_conn, this test sees
    # the file instead of silently writing to the host's default /data path.
    monkeypatch.setattr(ocr_correction.settings, "data_dir", str(data_dir))
    monkeypatch.setattr(ocr_correction, "cached_ocr_document_ids", lambda: set())
    stored: list[int] = []
    monkeypatch.setattr(
        ocr_correction,
        "store_ocr_correction",
        lambda document_id, *_args: stored.append(document_id),
    )
    monkeypatch.setattr(ocr_correction, "effective_ocr_mode", lambda: "text")
    monkeypatch.setattr(ocr_correction, "_text_looks_broken", lambda _text: True)
    monkeypatch.setattr(ocr_correction, "load_prompt", lambda _name: "prompt")
    monkeypatch.setattr(ocr_correction.settings, "max_doc_chars", 8000)
    monkeypatch.setattr(ocr_correction.settings, "ollama_ocr_num_ctx", 8192)

    paperless = AsyncMock()
    paperless.list_all_documents.return_value = [
        PaperlessDocument(id=7, title="scan", content="broken text", tags=[])
    ]
    paperless.list_tags.return_value = []
    provider = AsyncMock()
    provider.ocr_model = "ocr"
    provider.chat_json.return_value = {"corrected_text": "fixed text", "num_corrections": 1}

    assert await ocr_correction.batch_correct_documents(paperless, provider) == 1
    assert stored == [7]

    monkeypatch.setattr(classifier, "rejected_entity_names", lambda kind: [kind])
    classifier.build_user_prompt(
        PaperlessDocument(id=8, title="doc", content="content"), [], [], [], [], []
    )

    # Execute the productive process-document classification seam as well as
    # OCR reindex. All durable storage calls are PostgreSQL seams patched here;
    # any accidental app.db/classifier.db access would escape the patch and
    # either fail or leave a file in this isolated directory.
    paperless.list_correspondents.return_value = []
    paperless.list_document_types.return_value = []
    paperless.list_storage_paths.return_value = []
    paperless.list_tags.return_value = []
    monkeypatch.setattr(document, "effective_ocr_mode", lambda: "text")
    monkeypatch.setattr(
        document, "should_run_ocr_for_document", lambda *_args, **_kwargs: (True, "test")
    )
    monkeypatch.setattr(document, "maybe_correct_ocr", AsyncMock(return_value=("fixed", 1)))
    monkeypatch.setattr(
        document, "cache_ocr_correction", lambda document_id, *_args: stored.append(document_id)
    )
    monkeypatch.setattr(
        document, "find_similar_with_precomputed_embedding", AsyncMock(return_value=[])
    )
    result = ClassificationResult(
        title="classified",
        date=None,
        correspondent=None,
        document_type=None,
        storage_path=None,
        tags=[],
        confidence=50,
        reasoning="test",
    )
    monkeypatch.setattr(document, "classify", AsyncMock(return_value=(result, "{}")))
    monkeypatch.setattr(
        document, "maybe_run_judge", AsyncMock(return_value=JudgeOutcome(result=result))
    )
    provider.embed.return_value = [0.1, 0.2]

    outcome = await document._classify_document(
        PaperlessDocument(id=8, title="doc", content="broken", tags=[]),
        paperless=paperless,
        ai_provider=provider,
    )
    assert outcome.result.title == "classified"
    assert stored == [7, 8]

    legacy_name = "classifier" + ".db"
    assert not (data_dir / legacy_name).exists()
    assert not (tmp_path / legacy_name).exists()
    assert list(data_dir.iterdir()) == []
