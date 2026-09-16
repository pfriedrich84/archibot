"""Process-wide capacity control for AI-provider activities."""

from __future__ import annotations

import asyncio
from collections.abc import Awaitable, Callable
from functools import wraps

_loop: asyncio.AbstractEventLoop | None = None
_lock: asyncio.Lock | None = None


def _model_lock() -> asyncio.Lock:
    global _lock, _loop
    running_loop = asyncio.get_running_loop()
    if _lock is None or _loop is not running_loop:
        _loop = running_loop
        _lock = asyncio.Lock()
    return _lock


def serialized_model_activity[**P, R](
    function: Callable[P, Awaitable[R]],
) -> Callable[P, Awaitable[R]]:
    """Allow at most one model activity in this worker process."""

    @wraps(function)
    async def wrapped(*args: P.args, **kwargs: P.kwargs) -> R:
        async with _model_lock():
            return await function(*args, **kwargs)

    return wrapped
