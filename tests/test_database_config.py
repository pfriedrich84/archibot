"""PostgreSQL-only product startup and engine regression tests."""

from __future__ import annotations

import os
import subprocess
import sys

import pytest

from app.jobs import database


def _run_module_with_database_url(
    module: str, database_url: str
) -> subprocess.CompletedProcess[str]:
    env = os.environ.copy()
    env["DATABASE_URL"] = database_url
    return subprocess.run(
        [sys.executable, "-m", module, "--help"],
        check=False,
        capture_output=True,
        text=True,
        env=env,
    )


def test_product_engine_rejects_non_postgresql_url_before_create_engine(monkeypatch):
    import sqlalchemy

    called = False

    def forbidden_create_engine(*_args, **_kwargs):
        nonlocal called
        called = True
        raise AssertionError("non-PostgreSQL product engine creation was attempted")

    monkeypatch.setattr(database.settings, "database_url", "sqlite:///:memory:")
    monkeypatch.setattr(database, "_engine", None)
    monkeypatch.setattr(sqlalchemy, "create_engine", forbidden_create_engine)

    with pytest.raises(ValueError, match="must use PostgreSQL"):
        database.engine()
    assert called is False


@pytest.mark.parametrize("module", ["app.actor_runner", "app.event_worker", "app.cli"])
def test_product_entry_points_fail_closed_for_sqlite_database_url(module):
    result = _run_module_with_database_url(module, "sqlite:///:memory:")

    assert result.returncode != 0
    assert "DATABASE_URL must use PostgreSQL" in result.stderr


def test_product_config_file_cannot_override_postgresql_with_sqlite(tmp_path):
    (tmp_path / "config.env").write_text("DATABASE_URL=sqlite:///:memory:\n", encoding="utf-8")
    result = subprocess.run(
        [sys.executable, "-c", "import app.config"],
        check=False,
        capture_output=True,
        text=True,
        env={
            **os.environ,
            "DATA_DIR": str(tmp_path),
            "DATABASE_URL": "postgresql+psycopg://archibot:archibot@postgres/archibot",
        },
    )

    assert result.returncode != 0
    assert "DATABASE_URL in config.env must use PostgreSQL" in result.stderr


def test_postgresql_database_url_is_accepted_by_product_config():
    result = subprocess.run(
        [
            sys.executable,
            "-c",
            "from app.config import assert_product_database_config; assert_product_database_config()",
        ],
        check=False,
        capture_output=True,
        text=True,
        env={
            **os.environ,
            "DATABASE_URL": "postgresql+psycopg://archibot:archibot@postgres/archibot",
        },
    )

    assert result.returncode == 0, result.stderr
