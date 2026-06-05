from app import dramatiq_broker


def test_absurd_backend_supports_queue_name_signatures(monkeypatch):
    registered = []
    spawned = []

    class FakeClient:
        def __init__(self, connection_url, queue_name):
            self.connection_url = connection_url
            self.queue_name = queue_name

        def create_queue(self, queue_name):
            registered.append(("create_queue", queue_name))

        def register_task(self, name, queue_name):
            def decorator(func):
                registered.append(("register_task", name, queue_name, func))
                return func

            return decorator

        def spawn(self, task_name, payload, queue_name):
            spawned.append((task_name, payload, queue_name))

        def start_worker(self):
            registered.append(("start_worker",))

    monkeypatch.setattr(dramatiq_broker, "Absurd", FakeClient)

    backend = dramatiq_broker._AbsurdBackend(
        queue_name="archibot",
        connection_url="postgresql://example",
    )
    actor = backend.actor(queue_name="archibot.io")(lambda value: value)

    actor.send(7)

    assert registered[0] == ("create_queue", "archibot")
    assert registered[1][0:3] == ("register_task", "<lambda>", "archibot.io")
    assert spawned == [
        (
            "<lambda>",
            {
                "__archibot_queue_payload__": True,
                "args": [7],
                "kwargs": {},
            },
            "archibot.io",
        )
    ]


def test_absurd_backend_start_worker_omits_unsupported_kwargs(monkeypatch):
    calls = []

    class FakeClient:
        def __init__(self, connection_url, queue_name):
            self.connection_url = connection_url
            self.queue_name = queue_name

        def start_worker(self):
            calls.append("started")

    monkeypatch.setattr(dramatiq_broker, "Absurd", FakeClient)

    backend = dramatiq_broker._AbsurdBackend(
        queue_name="archibot",
        connection_url="postgresql://example",
    )
    backend.start_worker(concurrency=5, claim_timeout=77)

    assert calls == ["started"]


def test_resolved_absurd_database_url_requires_explicit_queue_url(monkeypatch):
    monkeypatch.setattr(dramatiq_broker.settings, "absurd_database_url", "")

    assert dramatiq_broker._resolved_absurd_database_url() == ""
