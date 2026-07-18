"""PostgreSQL repository for local-only OCR corrections."""

from __future__ import annotations

from dataclasses import dataclass

from app.jobs.database import engine


@dataclass(frozen=True)
class OcrCorrection:
    paperless_document_id: int
    corrected_content: str
    ocr_mode: str
    num_corrections: int


def _text(statement: str):
    try:
        from sqlalchemy import text
    except ModuleNotFoundError as exc:  # pragma: no cover
        raise RuntimeError("sqlalchemy is required for PostgreSQL OCR corrections") from exc
    return text(statement)


def store_ocr_correction(
    paperless_document_id: int,
    corrected_content: str,
    ocr_mode: str,
    num_corrections: int,
) -> None:
    """Idempotently persist corrected OCR text in the shared schema."""
    statement = _text(
        """
        INSERT INTO document_ocr_corrections (
            paperless_document_id, corrected_content, ocr_mode,
            num_corrections, corrected_at, created_at, updated_at
        ) VALUES (
            :document_id, :content, :ocr_mode, :num_corrections,
            CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
        )
        ON CONFLICT (paperless_document_id) DO UPDATE SET
            corrected_content = EXCLUDED.corrected_content,
            ocr_mode = EXCLUDED.ocr_mode,
            num_corrections = EXCLUDED.num_corrections,
            corrected_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
        """
    )
    with engine().begin() as connection:
        connection.execute(
            statement,
            {
                "document_id": int(paperless_document_id),
                "content": corrected_content,
                "ocr_mode": ocr_mode,
                "num_corrections": max(0, int(num_corrections)),
            },
        )


def cached_ocr_correction(paperless_document_id: int) -> str | None:
    statement = _text(
        """
        SELECT corrected_content
        FROM document_ocr_corrections
        WHERE paperless_document_id = :document_id
        """
    )
    with engine().connect() as connection:
        row = (
            connection.execute(statement, {"document_id": int(paperless_document_id)})
            .mappings()
            .first()
        )
    return None if row is None else str(row["corrected_content"])


def cached_ocr_document_ids() -> set[int]:
    with engine().connect() as connection:
        rows = (
            connection.execute(_text("SELECT paperless_document_id FROM document_ocr_corrections"))
            .mappings()
            .all()
        )
    return {int(row["paperless_document_id"]) for row in rows}
