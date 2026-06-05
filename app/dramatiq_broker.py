"""Compatibility shim for the renamed Absurd queue adapter.

New code should import from :mod:`app.absurd_queue`.
"""

from app.absurd_queue import *  # noqa: F403
