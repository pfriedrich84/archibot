"""Optional live smoke test for the PostgreSQL-backed Absurd queue.

Run with a migrated PostgreSQL database:

    ARCHIBOT_RUN_ABSURD_SMOKE=1 ABSURD_DATABASE_URL=postgresql://... pytest tests/test_absurd_queue_smoke.py -q
"""

from __future__ import annotations

import os
import uuid

import pytest


@pytest.mark.skipif(
    os.environ.get("ARCHIBOT_RUN_ABSURD_SMOKE") != "1",
    reason="set ARCHIBOT_RUN_ABSURD_SMOKE=1 and ABSURD_DATABASE_URL/DATABASE_URL to run live Absurd smoke test",
)
def test_live_absurd_spawn_and_work_batch() -> None:
    from absurd_sdk import Absurd

    database_url = os.environ.get("ABSURD_DATABASE_URL") or os.environ.get("DATABASE_URL")
    if not database_url:
        pytest.skip("ABSURD_DATABASE_URL or DATABASE_URL is required for live Absurd smoke test")
    if database_url.startswith("postgresql+psycopg://"):
        database_url = "postgresql://" + database_url[len("postgresql+psycopg://") :]

    queue_name = f"archibot_smoke_{uuid.uuid4().hex[:16]}"
    app = Absurd(database_url, queue_name=queue_name)
    seen: list[dict[str, object]] = []

    app.create_queue(queue_name)

    @app.register_task(name="archibot-smoke", queue=queue_name)
    def archibot_smoke(params, _ctx):
        seen.append(dict(params))
        return {"ok": True, "value": params["value"]}

    try:
        result = app.spawn("archibot-smoke", {"value": 42}, queue=queue_name)
        app.work_batch(worker_id="archibot-smoke-test", batch_size=1)
        snapshot = app.fetch_task_result(result["task_id"], queue_name=queue_name)

        assert seen == [{"value": 42}]
        assert snapshot is not None
        assert snapshot.state == "completed"
        assert snapshot.result == {"ok": True, "value": 42}
    finally:
        app.drop_queue(queue_name)
        app.close()
