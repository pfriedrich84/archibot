"""Runtime context helpers for actors."""

from __future__ import annotations

import os
import socket


def worker_id() -> str:
    """Return a stable-enough runtime worker id for logs and actor execution rows."""
    return os.getenv("ARCHIBOT_WORKER_ID") or f"{socket.gethostname()}:{os.getpid()}"
