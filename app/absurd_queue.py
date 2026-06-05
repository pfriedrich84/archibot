"""Absurd queue backend for the event-driven pipeline."""

from __future__ import annotations

import inspect
import os
import queue
import threading
from collections.abc import Callable
from functools import update_wrapper
from typing import Any

from app.config import settings

try:  # pragma: no cover - optional runtime dependency
    from absurd_sdk import Absurd
except Exception:  # pragma: no cover
    Absurd = None  # type: ignore[assignment]


def queue_backend_name(name: str) -> str:
    """Return a queue name with the configured Archibot prefix."""
    return f"{settings.archibot_queue_prefix}.{name}"


class _QueuedTaskCallable:
    """Callable wrapper exposing an optional ``send`` attribute."""

    def __init__(self, impl: Callable[..., Any], send: Callable[..., Any] | None = None):
        self._impl = impl
        self.send = send
        update_wrapper(self, impl)

    def __call__(self, *args: Any, **kwargs: Any) -> Any:
        return self._impl(*args, **kwargs)


class _AbsurdBackend:
    """Adapter for registering callable tasks with Absurd."""

    def __init__(self, queue_name: str, connection_url: str) -> None:
        self.queue_name = queue_name
        self.connection_url = connection_url
        self.client = self._create_client(self.queue_name)
        self._clients_by_queue: dict[str, Any] = {self.queue_name: self.client}
        self._tasks_registered: set[tuple[str, str]] = set()

    def _create_client(self, queue_name: str) -> Any:
        client = Absurd(self.connection_url, queue_name=queue_name)  # type: ignore[call-arg]
        # Ensure queue exists when the SDK exposes a helper for it.
        create_queue = getattr(client, "create_queue", None)
        if callable(create_queue):
            create_queue(queue_name)
        return client

    def _client_for_queue(self, queue_name: str) -> Any:
        client = self._clients_by_queue.get(queue_name)
        if client is None:
            client = self._create_client(queue_name)
            self._clients_by_queue[queue_name] = client
        return client

    @staticmethod
    def _pack_payload(args: tuple[Any, ...], kwargs: dict[str, Any]) -> dict[str, Any]:
        return {
            "__archibot_queue_payload__": True,
            "args": list(args),
            "kwargs": kwargs,
        }

    @staticmethod
    def _unpack_payload(payload: Any) -> tuple[list[Any], dict[str, Any]]:
        if isinstance(payload, dict) and payload.get("__archibot_queue_payload__") is True:
            args = payload.get("args")
            kwargs = payload.get("kwargs")
            normalized_args = list(args) if isinstance(args, list | tuple) else []
            normalized_kwargs = kwargs if isinstance(kwargs, dict) else {}
            return normalized_args, normalized_kwargs

        return [payload], {}

    def actor(
        self, queue_name: str | None = None
    ) -> Callable[[Callable[..., Any]], _QueuedTaskCallable]:
        # Keep the same small decorator shape the actor call sites already use.
        # ``queue_name`` remains accepted for compatibility and routing.
        assigned_queue = queue_name if queue_name is not None else self.queue_name
        if not assigned_queue.startswith(f"{settings.archibot_queue_prefix}."):
            assigned_queue = queue_backend_name(assigned_queue)

        def _decorate(func: Callable[..., Any]) -> _QueuedTaskCallable:
            task_name = func.__name__

            client = self._client_for_queue(assigned_queue)
            registration_key = (assigned_queue, task_name)
            if registration_key not in self._tasks_registered:

                def _task(params: dict[str, Any], _ctx: Any) -> Any:
                    args, kwargs = self._unpack_payload(params)
                    return func(*args, **kwargs)

                register_kwargs: dict[str, Any] = {"name": task_name}
                register_task_params = inspect.signature(client.register_task).parameters
                if "queue" in register_task_params:
                    register_kwargs["queue"] = assigned_queue
                elif "queue_name" in register_task_params:
                    register_kwargs["queue_name"] = assigned_queue

                client.register_task(**register_kwargs)(_task)
                self._tasks_registered.add(registration_key)

            def _send(*args: Any, **kwargs: Any) -> None:
                payload = self._pack_payload(args, kwargs)
                spawn_kwargs: dict[str, Any] = {}
                spawn_params = inspect.signature(client.spawn).parameters
                if "queue" in spawn_params:
                    spawn_kwargs["queue"] = assigned_queue
                elif "queue_name" in spawn_params:
                    spawn_kwargs["queue_name"] = assigned_queue

                client.spawn(task_name, payload, **spawn_kwargs)

            return _QueuedTaskCallable(func, _send)

        return _decorate

    @staticmethod
    def _worker_kwargs(client: Any, concurrency: int, claim_timeout: int) -> dict[str, Any]:
        worker_kwargs: dict[str, Any] = {}
        start_worker_params = inspect.signature(client.start_worker).parameters
        if "concurrency" in start_worker_params:
            worker_kwargs["concurrency"] = max(1, concurrency)
        if "claim_timeout" in start_worker_params:
            worker_kwargs["claim_timeout"] = max(1, claim_timeout)
        return worker_kwargs

    def _start_client_worker(
        self, client: Any, *, concurrency: int = 1, claim_timeout: int = 120
    ) -> None:
        client.start_worker(**self._worker_kwargs(client, concurrency, claim_timeout))

    def start_worker(self, concurrency: int = 1, claim_timeout: int = 120) -> None:
        clients = list(self._clients_by_queue.values())
        if len(clients) == 1:
            self._start_client_worker(
                clients[0], concurrency=concurrency, claim_timeout=claim_timeout
            )
            return

        failures: queue.Queue[BaseException] = queue.Queue()

        def _run_client(client: Any) -> None:
            try:
                self._start_client_worker(
                    client, concurrency=concurrency, claim_timeout=claim_timeout
                )
            except BaseException as exc:  # pragma: no cover - exercised by live worker failures
                failures.put(exc)

        threads = [
            threading.Thread(
                target=_run_client,
                args=(client,),
                name=f"archibot-absurd-worker-{queue_name}",
                daemon=True,
            )
            for queue_name, client in self._clients_by_queue.items()
        ]
        for thread in threads:
            thread.start()

        while any(thread.is_alive() for thread in threads):
            try:
                raise failures.get_nowait()
            except queue.Empty:
                pass
            for thread in threads:
                thread.join(timeout=1)

        if not failures.empty():
            raise failures.get()


def queue_name(name: str) -> str:
    """Compatibility wrapper for actor call sites."""
    return queue_backend_name(name)


def _normalize_absurd_database_url(url: str) -> str:
    if url.startswith("postgresql+psycopg://"):
        return "postgresql://" + url[len("postgresql+psycopg://") :]
    return url


def _resolved_absurd_database_url() -> str:
    configured = settings.absurd_database_url.strip()
    if configured:
        return configured
    return os.environ.get("DATABASE_URL", "").strip()


def _configure_queue_backend() -> _AbsurdBackend | None:
    if Absurd is None:
        return None
    database_url = _resolved_absurd_database_url()
    if not database_url:
        return None

    return _AbsurdBackend(
        queue_name=settings.archibot_queue_prefix,
        connection_url=_normalize_absurd_database_url(database_url),
    )


queue_backend = _configure_queue_backend()
# Compatibility alias for modules that still import the old generic name.
broker = queue_backend


def get_queue_backend() -> _AbsurdBackend | None:
    """Return the configured Absurd queue backend."""
    return queue_backend


def has_queue_backend() -> bool:
    """Return whether the Absurd queue backend is configured."""
    return queue_backend is not None


def has_absurd_backend() -> bool:
    """Return whether the Absurd backend is configured."""
    return has_queue_backend()


def get_absurd_backend() -> _AbsurdBackend | None:
    """Return the active Absurd backend when configured."""
    return queue_backend


def start_queue_worker(*, concurrency: int = 1, claim_timeout: int = 120) -> None:
    """Start the durable Absurd worker used by ArchiBot actors."""
    backend = get_queue_backend()
    if backend is None:
        raise RuntimeError(
            "Absurd queue worker requires absurd-sdk and DATABASE_URL or ABSURD_DATABASE_URL."
        )
    backend.start_worker(concurrency=concurrency, claim_timeout=claim_timeout)


def start_absurd_worker(*, concurrency: int = 1, claim_timeout: int = 120) -> None:
    """Compatibility alias for the previous helper name."""
    start_queue_worker(concurrency=concurrency, claim_timeout=claim_timeout)
