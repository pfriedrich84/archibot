"""Dramatiq broker configuration for the event-driven pipeline."""

from __future__ import annotations

from app.config import settings

try:  # pragma: no cover - import availability depends on optional runtime deps in local envs
    import dramatiq
    from dramatiq.brokers.rabbitmq import RabbitmqBroker
except Exception:  # pragma: no cover
    dramatiq = None  # type: ignore[assignment]
    RabbitmqBroker = None  # type: ignore[assignment]


def queue_name(name: str) -> str:
    """Return a queue name with the configured Archibot prefix."""
    return f"{settings.archibot_queue_prefix}.{name}"


def configure_broker() -> object | None:
    """Configure and return the Dramatiq RabbitMQ broker when Dramatiq is installed."""
    if dramatiq is None or RabbitmqBroker is None:
        return None

    broker = RabbitmqBroker(url=settings.dramatiq_broker_url)
    dramatiq.set_broker(broker)
    return broker


broker = configure_broker()
