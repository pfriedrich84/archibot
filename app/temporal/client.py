"""Shared Temporal client construction."""

from __future__ import annotations

from temporalio.client import Client

from app.config import Settings, settings


async def connect_temporal(config: Settings = settings) -> Client:
    """Connect to the private Temporal namespace configured for ArchiBot."""
    return await Client.connect(
        config.temporal_address,
        namespace=config.temporal_namespace,
    )
