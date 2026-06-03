import sqlite3

import pytest

from app import cli


def _worker_db(path):
    with sqlite3.connect(path) as conn:
        conn.execute(
            """
            CREATE TABLE worker_jobs (
                id INTEGER PRIMARY KEY,
                type TEXT,
                status TEXT,
                payload TEXT,
                retry_of_worker_job_id INTEGER,
                created_at TEXT,
                started_at TEXT,
                finished_at TEXT,
                error TEXT
            )
            """
        )
        conn.execute(
            """
            CREATE TABLE worker_job_logs (
                id INTEGER PRIMARY KEY,
                worker_job_id INTEGER,
                level TEXT,
                phase TEXT,
                event TEXT,
                paperless_document_id INTEGER,
                message TEXT,
                created_at TEXT
            )
            """
        )
        conn.execute(
            """
            INSERT INTO worker_jobs (id, type, status, payload, created_at)
            VALUES (1, 'process_document', 'queued', '{"paperless_document_id": 42}', '2026-06-02T12:00:00Z')
            """
        )


def test_jobs_list_and_status_are_read_only(monkeypatch, tmp_path, capsys):
    db_path = tmp_path / "worker.sqlite"
    _worker_db(db_path)
    monkeypatch.setattr(cli, "_laravel_db_path", lambda: db_path)

    cli.cmd_jobs(["list"])
    assert "#1 process_document queued" in capsys.readouterr().out

    cli.cmd_jobs(["status", "1"])
    assert '"status": "queued"' in capsys.readouterr().out


def test_jobs_stop_and_retry_do_not_mutate_worker_jobs(monkeypatch, tmp_path, capsys):
    db_path = tmp_path / "worker.sqlite"
    _worker_db(db_path)
    monkeypatch.setattr(cli, "_laravel_db_path", lambda: db_path)

    with pytest.raises(SystemExit) as stop_exit:
        cli.cmd_jobs(["stop", "1"])
    assert stop_exit.value.code == 1
    assert "deprecated and read-only" in capsys.readouterr().out

    with pytest.raises(SystemExit) as retry_exit:
        cli.cmd_jobs(["retry", "1"])
    assert retry_exit.value.code == 1
    assert "deprecated and read-only" in capsys.readouterr().out

    with sqlite3.connect(db_path) as conn:
        rows = conn.execute("SELECT id, status, retry_of_worker_job_id FROM worker_jobs").fetchall()

    assert rows == [(1, "queued", None)]
