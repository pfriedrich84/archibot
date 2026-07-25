"""Globally staged document-batch actor for poll target sets."""

from __future__ import annotations

import asyncio
import time
from contextlib import suppress

import structlog

from app.actors import LARAVEL_DATABASE_QUEUE
from app.actors.document import (
    StagedDocumentState,
    _fetch_paperless_document,
    _run_staged_document_phases,
)
from app.events.publish import publish_pipeline_event
from app.execution_lifecycle import (
    ExecutionLifecycle,
    finish_actor_execution,
    start_actor_execution,
    update_actor_execution_progress,
    update_item_derived_progress,
)
from app.jobs.pipeline_items import (
    PipelineItemRecord,
    finish_staged_pipeline_item,
    start_or_resume_staged_pipeline_item,
)
from app.jobs.pipeline_runs import (
    DocumentPipelineRunRecord,
    StagedBatchFenceLost,
    ensure_staged_batch_active,
    list_document_pipeline_runs_for_command,
    mark_pipeline_run_status,
)
from app.jobs.progress import ProgressSnapshot
from app.jobs.retry import classify_exception, should_retry
from app.jobs.review_suggestions import store_review_suggestion

log = structlog.get_logger(__name__)


def _batch_phase_item(
    *,
    pipeline_run_id: int,
    batch_command_id: int,
    paperless_document_id: int,
    item_type: str,
) -> PipelineItemRecord:
    return start_or_resume_staged_pipeline_item(
        pipeline_run_id=pipeline_run_id,
        batch_command_id=batch_command_id,
        item_type=item_type,
        item_key=f"{item_type}:{paperless_document_id}",
        paperless_document_id=paperless_document_id,
    )


def _finish_batch_phase_item(
    item: PipelineItemRecord,
    *,
    pipeline_run_id: int,
    batch_command_id: int,
    status: str,
    error: str | None = None,
) -> None:
    finish_staged_pipeline_item(
        item,
        pipeline_run_id=pipeline_run_id,
        batch_command_id=batch_command_id,
        status=status,
        error=error,
    )


def _handle_staged_document_batch_impl(
    command_id: int,
    source_command_id: int,
    *,
    embedding_ready: bool | None = None,
) -> None:
    """Process all document runs from one poll command in global model-role phases."""
    started = time.monotonic()
    actor_name = "process_staged_document_batch"
    actor_execution = start_actor_execution(
        actor_name=actor_name,
        command_id=command_id,
        queue_name=LARAVEL_DATABASE_QUEUE,
    )
    runs: list[DocumentPipelineRunRecord] = []
    active_items: dict[tuple[int, str], PipelineItemRecord] = {}
    completed_steps = 0

    try:
        runs = list_document_pipeline_runs_for_command(source_command_id, command_id)
        if not runs:
            finish_actor_execution(
                actor_execution,
                status="skipped",
                error_type="empty_target_set",
                error_message="Staged document batch has no pending target documents.",
            )
            return

        if embedding_ready is not True:
            message = "Staged document batch blocked because the embedding index is not ready."
            for run in runs:
                mark_pipeline_run_status(
                    run.id,
                    status="blocked",
                    phase="blocked",
                    message=message,
                    error_type="embedding_index_not_ready",
                    error=message,
                )
            finish_actor_execution(
                actor_execution,
                status="blocked",
                error_type="embedding_index_not_ready",
                error_message=message,
            )
            publish_pipeline_event(
                "pipeline.batch.blocked.embedding_index_not_ready",
                command_id=command_id,
                level="warning",
                message=message,
                payload={"source_command_id": source_command_id, "document_count": len(runs)},
            )
            return

        run_ids = [run.id for run in runs]

        def assert_batch_active() -> None:
            ensure_staged_batch_active(command_id, run_ids)

        assert_batch_active()
        total_steps = len(runs) * 6

        def update_batch_progress(phase: str, current_document_id: int) -> None:
            if actor_execution.id is None:
                return
            update_actor_execution_progress(
                actor_execution.id,
                ProgressSnapshot(
                    total=total_steps,
                    done=completed_steps,
                    phase=phase,
                    message=f"Global {phase} phase processing target documents.",
                ),
                current_item=f"paperless_document:{current_document_id}",
            )

        documents = []
        for run in runs:
            assert_batch_active()
            mark_pipeline_run_status(
                run.id,
                status="running",
                phase="paperless_fetch",
                message="Fetching target document for staged processing.",
            )
            item = _batch_phase_item(
                pipeline_run_id=run.id,
                batch_command_id=command_id,
                paperless_document_id=run.paperless_document_id,
                item_type="paperless_fetch",
            )
            active_items[(run.id, "paperless_fetch")] = item
            document = asyncio.run(_fetch_paperless_document(run.paperless_document_id))
            documents.append(document)
            _finish_batch_phase_item(
                item,
                pipeline_run_id=run.id,
                batch_command_id=command_id,
                status="succeeded",
            )
            active_items.pop((run.id, "paperless_fetch"))
            completed_steps += 1
            update_item_derived_progress(
                pipeline_run_id=run.id,
                actor_execution_id=None,
                phase="paperless_fetch",
                message="Document fetched for staged processing.",
                current_item=f"paperless_document:{run.paperless_document_id}",
            )
            update_batch_progress("paperless_fetch", run.paperless_document_id)

        publish_pipeline_event(
            "pipeline.batch.phase.completed",
            command_id=command_id,
            message="Global Paperless fetch phase completed.",
            payload={
                "source_command_id": source_command_id,
                "phase": "paperless_fetch",
                "document_count": len(runs),
            },
        )

        run_by_document_id = {run.paperless_document_id: run for run in runs}

        def observe_phase(
            event: str,
            phase: str,
            index: int,
            total: int,
            state: StagedDocumentState,
        ) -> None:
            nonlocal completed_steps
            assert_batch_active()
            run = run_by_document_id[int(state.original_document.id)]
            key = (run.id, phase)
            if event == "started":
                mark_pipeline_run_status(
                    run.id,
                    status="running",
                    phase=phase,
                    message=f"Global {phase} phase is processing this document.",
                )
                active_items[key] = _batch_phase_item(
                    pipeline_run_id=run.id,
                    batch_command_id=command_id,
                    paperless_document_id=run.paperless_document_id,
                    item_type=phase,
                )
                return

            item = active_items.pop(key)
            status = "succeeded"
            error = None
            if phase == "embedding" and not state.embedding_persisted:
                status = "failed"
                error = "Required target embedding was not persisted."
            elif (phase == "ocr" and not state.ocr_corrected) or (
                phase == "judge" and state.judge_verdict in {None, "skipped"}
            ):
                status = "skipped"
            _finish_batch_phase_item(
                item,
                pipeline_run_id=run.id,
                batch_command_id=command_id,
                status=status,
                error=error,
            )
            completed_steps += 1
            update_item_derived_progress(
                pipeline_run_id=run.id,
                actor_execution_id=None,
                phase=phase,
                message=f"Global {phase} phase completed for this document.",
                current_item=f"paperless_document:{run.paperless_document_id}",
            )
            update_batch_progress(phase, run.paperless_document_id)
            if index == total:
                publish_pipeline_event(
                    "pipeline.batch.phase.completed",
                    command_id=command_id,
                    message=f"Global {phase} phase completed.",
                    payload={
                        "source_command_id": source_command_id,
                        "phase": phase,
                        "document_count": total,
                    },
                )

        outcomes = asyncio.run(
            _run_staged_document_phases(
                documents,
                observer=observe_phase,
                mutation_guard=assert_batch_active,
                persist_target_embeddings=True,
                batch_command_id=command_id,
            )
        )
        if len(outcomes) != len(runs):
            raise RuntimeError("staged document batch returned an incomplete target set")

        for run, outcome in zip(runs, outcomes, strict=True):
            assert_batch_active()
            item = _batch_phase_item(
                pipeline_run_id=run.id,
                batch_command_id=command_id,
                paperless_document_id=run.paperless_document_id,
                item_type="review_suggestion",
            )
            active_items[(run.id, "review_suggestion")] = item
            suggestion = store_review_suggestion(
                paperless_document_id=run.paperless_document_id,
                document=outcome.document,
                result=outcome.result,
                raw_response=outcome.raw_response,
                context_documents=outcome.context_documents,
                pipeline_run_id=run.id,
                correspondents=outcome.catalog.correspondents,
                doctypes=outcome.catalog.doctypes,
                storage_paths=outcome.catalog.storage_paths,
                tags=outcome.catalog.tags,
                judge_verdict=outcome.judge_verdict,
                judge_reasoning=outcome.judge_reasoning,
                original_proposed_json=outcome.original_proposed_json,
                batch_command_id=command_id,
            )
            _finish_batch_phase_item(
                item,
                pipeline_run_id=run.id,
                batch_command_id=command_id,
                status="succeeded",
            )
            active_items.pop((run.id, "review_suggestion"))
            completed_steps += 1
            update_item_derived_progress(
                pipeline_run_id=run.id,
                actor_execution_id=None,
                phase="review_suggestion",
                message="Review suggestion persisted after the global judge phase.",
                current_item=f"review_suggestion:{suggestion.id}",
            )
            mark_pipeline_run_status(
                run.id,
                status="succeeded",
                phase="finished",
                message="Staged document pipeline completed.",
            )
            update_batch_progress("review_suggestion", run.paperless_document_id)

        publish_pipeline_event(
            "pipeline.batch.completed",
            command_id=command_id,
            message="Globally staged document batch completed.",
            payload={
                "source_command_id": source_command_id,
                "document_count": len(runs),
                "phase_order": ["embedding", "ocr", "classification", "judge"],
            },
        )
        finish_actor_execution(actor_execution, status="succeeded")
    except StagedBatchFenceLost:
        log.warning(
            "staged document batch stopped after losing its command fence",
            command_id=command_id,
        )
        raise
    except Exception as exc:
        for (pipeline_run_id, _phase), item in active_items.items():
            with suppress(Exception):
                _finish_batch_phase_item(
                    item,
                    pipeline_run_id=pipeline_run_id,
                    batch_command_id=command_id,
                    status="failed",
                    error=str(exc)[:1000],
                )
        retry_class = classify_exception(exc)
        retrying = should_retry(
            retry_class,
            attempt=actor_execution.attempt,
            max_attempts=5,
        )
        child_status = "retrying" if retrying else "failed_permanent"
        for run in runs:
            mark_pipeline_run_status(
                run.id,
                status=child_status,
                phase="batch_retry" if retrying else "batch_failed",
                message=(
                    "Staged batch scheduled for retry."
                    if retrying
                    else "Staged batch failed permanently."
                ),
                error_type=retry_class.value,
                error=str(exc)[:1000],
            )
        disposition = ExecutionLifecycle(actor_execution).fail(exc)
        if disposition.retrying:
            return
        raise

    log.info(
        "staged document batch completed",
        actor_name=actor_name,
        command_id=command_id,
        source_command_id=source_command_id,
        document_count=len(runs),
        duration_ms=int((time.monotonic() - started) * 1000),
    )
