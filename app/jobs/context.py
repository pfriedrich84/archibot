"""Runtime context helpers for actors."""

from __future__ import annotations

import os
import socket
from pathlib import Path


def worker_id() -> str:
    """Return host, PID and Linux process-start identity for actor liveness checks."""
    configured = os.getenv("ARCHIBOT_WORKER_ID")
    if configured:
        return configured

    pid = os.getpid()
    try:
        stat = Path(f"/proc/{pid}/stat").read_text(encoding="utf-8")
        process_started_at = stat.rsplit(") ", 1)[1].split()[19]
    except (OSError, IndexError):
        process_started_at = "unknown"

    return f"{socket.gethostname()}:{pid}:{process_started_at}"
