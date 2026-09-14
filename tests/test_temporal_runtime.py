from __future__ import annotations

import pytest

from app.temporal.names import WORKFLOW_PROTOCOL_VERSION, runtime_probe_workflow_id
from app.temporal.workflows import RuntimeProbeRequest, RuntimeProbeWorkflow


@pytest.mark.asyncio
async def test_runtime_probe_workflow_is_deterministic_and_side_effect_free():
    result = await RuntimeProbeWorkflow().run(RuntimeProbeRequest(request_id="probe-123"))

    assert result.request_id == "probe-123"
    assert result.protocol_version == WORKFLOW_PROTOCOL_VERSION


def test_runtime_probe_workflow_id_is_stable_and_bounded():
    assert runtime_probe_workflow_id("probe-123") == "archibot/runtime-probe/probe-123"


@pytest.mark.parametrize("request_id", ["", "has spaces", "slash/not-allowed", "x" * 129])
def test_runtime_probe_workflow_id_rejects_unsafe_identifiers(request_id):
    with pytest.raises(ValueError, match="safe identifier"):
        runtime_probe_workflow_id(request_id)
