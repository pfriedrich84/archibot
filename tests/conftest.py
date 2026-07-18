"""Shared fixtures for the test suite."""

from __future__ import annotations

import os
import tempfile
from unittest.mock import AsyncMock

import pytest

# Set required env vars BEFORE importing app modules
os.environ.setdefault("PAPERLESS_URL", "http://test:8000")
os.environ.setdefault("PAPERLESS_TOKEN", "test-token")
os.environ.setdefault("PAPERLESS_INBOX_TAG_ID", "99")
os.environ.setdefault("DATA_DIR", tempfile.mkdtemp())

from app.config import settings
from app.models import PaperlessDocument, PaperlessEntity


@pytest.fixture()
def sample_entities() -> list[PaperlessEntity]:
    """A small set of entities for resolution tests."""
    return [
        PaperlessEntity(id=1, name="Max Mustermann"),
        PaperlessEntity(id=2, name="Stadtwerke München"),
        PaperlessEntity(id=3, name="Deutsche Post"),
        PaperlessEntity(id=10, name="Rechnung"),
        PaperlessEntity(id=11, name="Vertrag"),
        PaperlessEntity(id=20, name="Finanzen"),
        PaperlessEntity(id=21, name="Wohnung"),
    ]


@pytest.fixture()
def sample_correspondents() -> list[PaperlessEntity]:
    return [
        PaperlessEntity(id=1, name="Max Mustermann"),
        PaperlessEntity(id=2, name="Stadtwerke München"),
        PaperlessEntity(id=3, name="Deutsche Post"),
    ]


@pytest.fixture()
def sample_doctypes() -> list[PaperlessEntity]:
    return [
        PaperlessEntity(id=10, name="Rechnung"),
        PaperlessEntity(id=11, name="Vertrag"),
    ]


@pytest.fixture()
def sample_storage_paths() -> list[PaperlessEntity]:
    return [
        PaperlessEntity(id=30, name="Finanzen/Rechnungen"),
        PaperlessEntity(id=31, name="Vertraege"),
    ]


@pytest.fixture()
def sample_tags() -> list[PaperlessEntity]:
    return [
        PaperlessEntity(id=20, name="Finanzen"),
        PaperlessEntity(id=21, name="Wohnung"),
        PaperlessEntity(id=22, name="Strom"),
    ]


@pytest.fixture()
def sample_context_doc() -> PaperlessDocument:
    """A classified document suitable as context (not in inbox)."""
    return PaperlessDocument(
        id=5,
        title="Stromrechnung Q1 2024",
        content="Rechnung Nr. 2024-1234\nStadtwerke München GmbH\nStrom\n127,43 EUR\n15.03.2024",
        created_date="2024-03-15",
        correspondent=2,  # Stadtwerke München
        document_type=10,  # Rechnung
        storage_path=30,  # Finanzen/Rechnungen
        tags=[20, 22],  # Finanzen, Strom
    )


@pytest.fixture()
def sample_doc() -> PaperlessDocument:
    """A minimal test document."""
    return PaperlessDocument(
        id=42,
        title="Scan_2024-03-15.pdf",
        content="Rechnung Nr. 12345\nStadtwerke München GmbH\nBetrag: 87,50 EUR",
        tags=[99],  # inbox tag
    )


@pytest.fixture()
def mock_paperless() -> AsyncMock:
    """A mocked PaperlessClient."""
    client = AsyncMock()
    client.get_document = AsyncMock(
        return_value=PaperlessDocument(id=42, title="test", tags=[99, 5])
    )
    client.patch_document = AsyncMock(return_value=None)
    client.list_correspondents = AsyncMock(return_value=[])
    client.list_document_types = AsyncMock(return_value=[])
    client.list_storage_paths = AsyncMock(return_value=[])
    client.list_tags = AsyncMock(return_value=[])
    return client


@pytest.fixture()
def mock_ollama() -> AsyncMock:
    """A mocked OllamaClient."""
    client = AsyncMock()
    client.chat_json = AsyncMock(
        return_value={
            "title": "Stromrechnung März 2024",
            "date": "2024-03-15",
            "correspondent": "Stadtwerke München",
            "document_type": "Rechnung",
            "storage_path": None,
            "tags": [{"name": "Finanzen", "confidence": 90}],
            "confidence": 85,
            "reasoning": "Erkannt als Stromrechnung",
        }
    )
    client.embed = AsyncMock(return_value=[0.1] * settings.ollama_embed_dim_resolved)
    return client
