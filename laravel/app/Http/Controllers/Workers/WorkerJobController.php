<?php

namespace App\Http\Controllers\Workers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PipelineEvent;
use App\Models\WorkerJob;
use App\Services\Pipeline\DocumentPipelineStarter;
use App\Services\Pipeline\MaintenanceCommandDispatcher;
use App\Services\Workers\StaleWorkerJobCanceller;
use App\Services\Workers\WorkerJobDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WorkerJobController extends Controller
{
    public function index(Request $request, StaleWorkerJobCanceller $staleCanceller): Response
    {
        $staleCanceller->cancel();

        $jobs = WorkerJob::query()
            ->with([
                'reviewSuggestions' => fn ($query) => $query->latest(),
                'logs' => fn ($query) => $query->latest()->limit(100),
            ])
            ->withCount('reviewSuggestions')
            ->latest()
            ->paginate(25)
            ->through(fn (WorkerJob $job) => [
                'id' => $job->id,
                'type' => $job->type,
                'status' => $job->status,
                'payload' => $job->payload ?? [],
                'result' => $job->result ?? [],
                'progress' => $job->progress ?? [],
                'ingest' => data_get($job->result, 'ingest', []),
                'review_suggestions_count' => $job->review_suggestions_count,
                'review_suggestions' => $job->reviewSuggestions->map(fn ($suggestion) => [
                    'id' => $suggestion->id,
                    'paperless_document_id' => $suggestion->paperless_document_id,
                    'proposed_title' => $suggestion->proposed_title,
                    'status' => $suggestion->status,
                ])->values(),
                'logs' => $job->logs->sortBy('id')->map(fn ($log) => [
                    'id' => $log->id,
                    'stream' => $log->stream,
                    'level' => $log->level,
                    'event' => $log->event,
                    'paperless_document_id' => $log->paperless_document_id,
                    'phase' => $log->phase,
                    'message' => $log->message,
                    'context' => $log->context ?? [],
                    'created_at' => $log->created_at?->toISOString(),
                ])->values(),
                'exit_code' => $job->exit_code,
                'error' => $job->error,
                'created_at' => $job->created_at?->toISOString(),
                'started_at' => $job->started_at?->toISOString(),
                'finished_at' => $job->finished_at?->toISOString(),
                'failed_document_ids' => $this->failedDocumentIds($job),
            ]);

        return Inertia::render('worker/Index', [
            'jobs' => $jobs,
            'allowedTypes' => $this->storeRequestTypes(),
            'workerJobTypes' => $this->workerJobRequestTypes(),
            'isAdmin' => (bool) $request->user()?->is_admin,
            'quickControls' => [
                'poll_url' => route('maintenance.poll'),
                'reindex_url' => route('maintenance.reindex'),
                'embedding_build_url' => route('embedding-index.build'),
                'worker_job_store_url' => route('worker-jobs.store'),
            ],
            'readiness' => [
                'queued' => WorkerJob::query()->where('status', WorkerJob::STATUS_QUEUED)->count(),
                'running' => WorkerJob::query()->whereIn('status', WorkerJob::runningStatuses())->count(),
                'failed' => WorkerJob::query()->whereIn('status', [WorkerJob::STATUS_FAILED, WorkerJob::STATUS_PARTIALLY_FAILED])->count(),
                'last_finished_at' => WorkerJob::query()->whereNotNull('finished_at')->latest('finished_at')->value('finished_at')?->toISOString(),
                'document_processing_active' => WorkerJob::query()
                    ->whereIn('type', WorkerJob::documentProcessingTypes())
                    ->whereIn('status', WorkerJob::activeStatuses())
                    ->exists(),
                'reindex_active' => WorkerJob::query()
                    ->whereIn('type', WorkerJob::blockingTypes())
                    ->whereIn('status', WorkerJob::activeStatuses())
                    ->exists(),
            ],
        ]);
    }

    public function show(Request $request, WorkerJob $workerJob): Response
    {
        $workerJob->load([
            'createdBy:id,name,email',
            'retryOf:id,type,status,created_at,finished_at',
            'retryChildren' => fn ($query) => $query->latest()->select(['id', 'type', 'status', 'retry_of_worker_job_id', 'created_at', 'finished_at']),
            'reviewSuggestions' => fn ($query) => $query->latest()->limit(50),
        ]);

        $logs = $workerJob->logs()
            ->latest()
            ->paginate(250)
            ->through(fn ($log) => [
                'id' => $log->id,
                'stream' => $log->stream,
                'level' => $log->level,
                'event' => $log->event,
                'paperless_document_id' => $log->paperless_document_id,
                'phase' => $log->phase,
                'message' => $log->message,
                'context' => $log->context ?? [],
                'created_at' => $log->created_at?->toISOString(),
            ]);

        $auditLogs = AuditLog::query()
            ->where('target_type', 'worker_job')
            ->where('target_id', (string) $workerJob->id)
            ->latest()
            ->limit(25)
            ->get()
            ->map(fn (AuditLog $auditLog) => [
                'id' => $auditLog->id,
                'event' => $auditLog->event,
                'actor_user_id' => $auditLog->actor_user_id,
                'metadata' => $auditLog->metadata ?? [],
                'created_at' => $auditLog->created_at?->toISOString(),
            ])
            ->values();

        $isAdmin = (bool) $request->user()?->is_admin;

        return Inertia::render('worker/Show', [
            'job' => [
                'id' => $workerJob->id,
                'type' => $workerJob->type,
                'status' => $workerJob->status,
                'payload' => $workerJob->payload ?? [],
                'result' => $workerJob->result ?? [],
                'progress' => $workerJob->progress ?? [],
                'ingest' => data_get($workerJob->result, 'ingest', []),
                'dispatch_key' => $workerJob->dispatch_key,
                'dispatch_attempts' => $workerJob->dispatch_attempts,
                'dispatched_at' => $workerJob->dispatched_at?->toISOString(),
                'worker_id' => $workerJob->worker_id,
                'lease_expires_at' => $workerJob->lease_expires_at?->toISOString(),
                'heartbeat_at' => $workerJob->heartbeat_at?->toISOString(),
                'retry_of_worker_job_id' => $workerJob->retry_of_worker_job_id,
                'cancellation_requested_at' => $workerJob->cancellation_requested_at?->toISOString(),
                'exit_code' => $workerJob->exit_code,
                'error' => $workerJob->error,
                'input_path' => $workerJob->input_path,
                'output_path' => $workerJob->output_path,
                'input_exists' => $workerJob->input_path ? is_file($workerJob->input_path) : false,
                'output_exists' => $workerJob->output_path ? is_file($workerJob->output_path) : false,
                'created_by' => $workerJob->createdBy ? [
                    'id' => $workerJob->createdBy->id,
                    'name' => $workerJob->createdBy->name,
                    'email' => $workerJob->createdBy->email,
                ] : null,
                'created_at' => $workerJob->created_at?->toISOString(),
                'started_at' => $workerJob->started_at?->toISOString(),
                'finished_at' => $workerJob->finished_at?->toISOString(),
            ],
            'logs' => $logs,
            'reviewSuggestions' => $workerJob->reviewSuggestions->map(fn ($suggestion) => [
                'id' => $suggestion->id,
                'paperless_document_id' => $suggestion->paperless_document_id,
                'source_suggestion_id' => $suggestion->source_suggestion_id,
                'dedupe_key' => $suggestion->dedupe_key,
                'proposed_title' => $suggestion->proposed_title,
                'status' => $suggestion->status,
                'confidence' => $suggestion->confidence,
                'created_at' => $suggestion->created_at?->toISOString(),
            ])->values(),
            'retryParent' => $workerJob->retryOf ? $this->jobLink($workerJob->retryOf) : null,
            'retryChildren' => $workerJob->retryChildren->map(fn (WorkerJob $job) => $this->jobLink($job))->values(),
            'auditLogs' => $auditLogs,
            'isAdmin' => $isAdmin,
            'actions' => $isAdmin ? [
                'can_stop' => in_array($workerJob->status, [WorkerJob::STATUS_QUEUED, WorkerJob::STATUS_RUNNING, WorkerJob::STATUS_CANCELLING], true),
                'can_retry' => in_array($workerJob->status, WorkerJob::terminalStatuses(), true),
                'can_retry_failed_only' => in_array($workerJob->status, WorkerJob::terminalStatuses(), true) && $this->failedDocumentIds($workerJob) !== [],
                'can_force_kill' => in_array($workerJob->status, WorkerJob::runningStatuses(), true),
                'stop_url' => route('worker-jobs.stop', $workerJob),
                'retry_url' => route('worker-jobs.retry', $workerJob),
                'force_kill_url' => route('worker-jobs.force-kill', $workerJob),
            ] : null,
        ]);
    }

    public function store(
        Request $request,
        StaleWorkerJobCanceller $staleCanceller,
        WorkerJobDispatcher $dispatcher,
        DocumentPipelineStarter $pipelineStarter,
        MaintenanceCommandDispatcher $maintenanceCommands,
    ): RedirectResponse {
        abort_unless($request->user()?->is_admin, 403);

        $staleCanceller->cancel();

        $validated = $request->validate([
            'type' => ['required', Rule::in($this->storeRequestTypes())],
            'paperless_document_id' => ['nullable', 'integer', 'min:1'],
            'force' => ['nullable', 'boolean'],
        ]);

        if ($validated['type'] === WorkerJob::TYPE_PROCESS_DOCUMENT && empty($validated['paperless_document_id'])) {
            return back()->withErrors(['paperless_document_id' => 'Document id is required for process-document jobs.']);
        }

        $force = $request->boolean('force');

        if ($validated['type'] === WorkerJob::TYPE_PROCESS_DOCUMENT) {
            $result = $pipelineStarter->start(
                triggerSource: 'manual',
                paperlessDocumentId: (int) $validated['paperless_document_id'],
                reprocessRequested: $force,
                reprocessReason: $force ? 'manual_force' : null,
                reprocessMode: $force ? 'manual' : null,
                forceNewRun: $force,
                requestedByUserId: $request->user()->id,
            );

            return redirect()->route('pipeline-runs.show', $result->pipelineRun)
                ->with('status', 'Document Pipeline Run queued.');
        }

        if ($validated['type'] === WorkerJob::TYPE_POLL) {
            $maintenanceCommands->queuePollReconciliation($request, null, [
                'legacy_worker_job_action' => WorkerJob::TYPE_POLL,
                'force' => $force,
            ]);

            return redirect()->route('worker-jobs.index')->with('status', 'Polling reconciliation command queued.');
        }

        if ($validated['type'] === WorkerJob::TYPE_REINDEX) {
            $maintenanceCommands->queueReindex($request, null, [
                'legacy_worker_job_action' => WorkerJob::TYPE_REINDEX,
            ]);

            return redirect()->route('worker-jobs.index')->with('status', 'Reindex command queued.');
        }

        if ($validated['type'] === WorkerJob::TYPE_REINDEX_EMBED) {
            $maintenanceCommands->queueEmbeddingIndexBuild($request, null, [
                'legacy_worker_job_action' => WorkerJob::TYPE_REINDEX_EMBED,
            ]);

            return redirect()->route('worker-jobs.index')->with('status', 'Embedding index build command queued.');
        }

        $dispatcher->dispatch(
            type: $validated['type'],
            payload: ['mode' => 'ocr', 'force' => $force],
            user: $request->user(),
            request: $request,
        );

        return redirect()->route('worker-jobs.index');
    }

    public function stop(Request $request, WorkerJob $workerJob): RedirectResponse
    {
        abort_unless($request->user()?->is_admin, 403);

        $now = now();

        if ($workerJob->status === WorkerJob::STATUS_QUEUED) {
            $workerJob->forceFill([
                'status' => WorkerJob::STATUS_CANCELLED,
                'cancellation_requested_at' => $now,
                'finished_at' => $now,
                'lease_expires_at' => null,
                'error' => 'Cancelled before execution.',
                'progress' => array_merge(is_array($workerJob->progress) ? $workerJob->progress : [], [
                    'cancelled' => true,
                    'message' => 'Cancelled before execution.',
                ]),
            ])->save();
            $workerJob->appendSystemLog('worker_job.cancelled', 'Queued worker job was cancelled before execution.');
        } elseif (in_array($workerJob->status, [WorkerJob::STATUS_RUNNING, WorkerJob::STATUS_CANCELLING], true)) {
            $killAfterAt = $now->copy()->addSeconds(max(1, (int) config('archibot_workers.cancel_grace_seconds', 30)));
            $progress = is_array($workerJob->progress) ? $workerJob->progress : [];
            $progress['cancellation'] = array_merge(is_array($progress['cancellation'] ?? null) ? $progress['cancellation'] : [], [
                'requested_at' => $workerJob->cancellation_requested_at?->toISOString() ?? $now->toISOString(),
                'kill_after_at' => $killAfterAt->toISOString(),
                'message' => 'Cancellation requested by an administrator.',
            ]);

            $workerJob->forceFill([
                'status' => WorkerJob::STATUS_CANCELLING,
                'cancellation_requested_at' => $workerJob->cancellation_requested_at ?: $now,
                'progress' => $progress,
            ])->save();
            $workerJob->appendSystemLog('worker_job.cancel_requested', 'Worker job cancellation was requested by an administrator.', 'warning', [
                'kill_after_at' => $killAfterAt->toISOString(),
            ]);
        }

        AuditLog::query()->create([
            'actor_user_id' => $request->user()->id,
            'event' => 'worker_job.stop_requested',
            'target_type' => 'worker_job',
            'target_id' => (string) $workerJob->id,
            'metadata' => ['status' => $workerJob->status],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return redirect()->route('worker-jobs.index');
    }

    public function forceKill(Request $request, WorkerJob $workerJob): RedirectResponse
    {
        abort_unless($request->user()?->is_admin, 403);

        abort_unless(in_array($workerJob->status, WorkerJob::runningStatuses(), true), 422);

        $validated = $request->validate([
            'status' => ['nullable', Rule::in([WorkerJob::STATUS_CANCELLED, WorkerJob::STATUS_FAILED])],
        ]);

        $terminalStatus = $validated['status'] ?? WorkerJob::STATUS_CANCELLED;
        $now = now();
        $progress = is_array($workerJob->progress) ? $workerJob->progress : [];
        $progress['force_killed_by_admin'] = true;
        $progress['message'] = $terminalStatus === WorkerJob::STATUS_FAILED
            ? 'Worker job was force-failed by an administrator.'
            : 'Worker job was force-cancelled by an administrator.';

        $workerJob->forceFill([
            'status' => $terminalStatus,
            'cancellation_requested_at' => $workerJob->cancellation_requested_at ?: $now,
            'finished_at' => $workerJob->finished_at ?: $now,
            'worker_id' => null,
            'lease_expires_at' => null,
            'heartbeat_at' => null,
            'error' => trim(($workerJob->error ? $workerJob->error."\n" : '').'force_killed_by_admin'),
            'progress' => $progress,
        ])->save();

        $workerJob->appendSystemLog('worker_job.force_killed', 'Worker job was marked terminal by an administrator.', 'error', [
            'status' => $terminalStatus,
            'process_stop_available' => false,
        ]);

        AuditLog::query()->create([
            'actor_user_id' => $request->user()->id,
            'event' => 'worker_job.force_killed',
            'target_type' => 'worker_job',
            'target_id' => (string) $workerJob->id,
            'metadata' => ['status' => $terminalStatus],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return redirect()->route('worker-jobs.index');
    }

    public function retry(
        Request $request,
        WorkerJob $workerJob,
        WorkerJobDispatcher $dispatcher,
        DocumentPipelineStarter $pipelineStarter,
        MaintenanceCommandDispatcher $maintenanceCommands,
    ): RedirectResponse {
        abort_unless($request->user()?->is_admin, 403);

        $validated = $request->validate([
            'failed_only' => ['nullable', 'boolean'],
        ]);

        $payload = $workerJob->payload ?? [];
        $failedDocuments = $this->failedDocumentIds($workerJob);
        $retryFailedOnly = (bool) ($validated['failed_only'] ?? false);

        if ($retryFailedOnly) {
            if ($failedDocuments === []) {
                return back()->withErrors(['failed_only' => 'No failed document references are available for this worker job.']);
            }

            $payload['retry_document_ids'] = $failedDocuments;
            $payload['retry_mode'] = 'failed_only';

            if ($workerJob->type === WorkerJob::TYPE_PROCESS_DOCUMENT) {
                $payload['paperless_document_id'] = (int) $failedDocuments[0];
            }
        }

        if ($workerJob->type === WorkerJob::TYPE_PROCESS_DOCUMENT) {
            $documentIds = $retryFailedOnly
                ? $failedDocuments
                : array_values(array_filter([(int) data_get($payload, 'paperless_document_id')], fn (int $id): bool => $id > 0));

            if ($documentIds === []) {
                return back()->withErrors(['paperless_document_id' => 'No Paperless Document id is available for this legacy worker job retry.']);
            }

            foreach ($documentIds as $documentId) {
                $result = $pipelineStarter->start(
                    triggerSource: 'retry',
                    paperlessDocumentId: $documentId,
                    reprocessRequested: true,
                    reprocessReason: 'worker_job_retry',
                    reprocessMode: $retryFailedOnly ? 'failed_only' : 'whole_job',
                    forceNewRun: true,
                    requestedByUserId: $request->user()->id,
                );
                $this->recordLegacyRetryEvent($request, $workerJob, $result->pipelineRun->id, $retryFailedOnly ? 'failed_only' : 'whole_job');
            }

            return redirect()->route('pipeline-runs.index')->with('status', 'Legacy Worker Job retry queued as Pipeline Run.');
        }

        if ($workerJob->type === WorkerJob::TYPE_POLL) {
            $maintenanceCommands->queuePollReconciliation($request, null, $this->legacyRetryMetadata($workerJob, $retryFailedOnly));

            return redirect()->route('worker-jobs.index')->with('status', 'Legacy Worker Job retry queued as polling command.');
        }

        if ($workerJob->type === WorkerJob::TYPE_REINDEX) {
            $maintenanceCommands->queueReindex($request, null, $this->legacyRetryMetadata($workerJob, $retryFailedOnly));

            return redirect()->route('worker-jobs.index')->with('status', 'Legacy Worker Job retry queued as reindex command.');
        }

        if ($workerJob->type === WorkerJob::TYPE_REINDEX_EMBED) {
            $maintenanceCommands->queueEmbeddingIndexBuild($request, null, $this->legacyRetryMetadata($workerJob, $retryFailedOnly));

            return redirect()->route('worker-jobs.index')->with('status', 'Legacy Worker Job retry queued as embedding build command.');
        }

        $dispatcher->dispatch(
            type: $workerJob->type,
            payload: $payload,
            user: $request->user(),
            request: $request,
            retryOfWorkerJobId: $workerJob->id,
            auditEvent: 'worker_job.retried',
            auditMetadata: [
                'retry_of_worker_job_id' => $workerJob->id,
                'retry_mode' => $retryFailedOnly ? 'failed_only' : 'whole_job',
            ],
        );

        return redirect()->route('worker-jobs.index');
    }

    /**
     * @return array<int, string>
     */
    private function storeRequestTypes(): array
    {
        return [
            WorkerJob::TYPE_POLL,
            WorkerJob::TYPE_PROCESS_DOCUMENT,
            WorkerJob::TYPE_REINDEX,
            WorkerJob::TYPE_REINDEX_OCR,
            WorkerJob::TYPE_REINDEX_EMBED,
        ];
    }

    /**
     * Return request types that still create rows in the temporary worker_jobs table.
     *
     * Polling, full reindex, embedding rebuild, and per-document processing have
     * migrated controls above this form. Keeping them out of the generic
     * "Queue worker job" selector prevents successful command routing from
     * looking like a no-op on the worker-jobs list.
     *
     * @return array<int, string>
     */
    private function workerJobRequestTypes(): array
    {
        return [
            WorkerJob::TYPE_REINDEX_OCR,
        ];
    }

    private function recordLegacyRetryEvent(Request $request, WorkerJob $workerJob, int $pipelineRunId, string $retryMode): void
    {
        PipelineEvent::query()->create([
            'pipeline_run_id' => $pipelineRunId,
            'event_type' => 'worker_job.retry_migrated_to_pipeline_run',
            'level' => 'info',
            'message' => 'Legacy Worker Job retry was routed to a durable Pipeline Run.',
            'payload' => [
                'actor_user_id' => $request->user()->id,
                'actor_is_admin' => true,
                'legacy_worker_job_id' => $workerJob->id,
                'legacy_worker_job_type' => $workerJob->type,
                'retry_mode' => $retryMode,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyRetryMetadata(WorkerJob $workerJob, bool $retryFailedOnly): array
    {
        return [
            'legacy_worker_job_id' => $workerJob->id,
            'legacy_worker_job_type' => $workerJob->type,
            'retry_mode' => $retryFailedOnly ? 'failed_only' : 'whole_job',
        ];
    }

    /**
     * @return array<int, int>
     */
    private function failedDocumentIds(WorkerJob $workerJob): array
    {
        return $workerJob->logs()
            ->whereIn('event', ['document_failed', 'document_cancelled'])
            ->get()
            ->map(fn ($log) => $log->paperless_document_id ?? data_get($log->context, 'document_id'))
            ->filter(fn ($documentId) => is_numeric($documentId))
            ->map(fn ($documentId) => (int) $documentId)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function jobLink(WorkerJob $workerJob): array
    {
        return [
            'id' => $workerJob->id,
            'type' => $workerJob->type,
            'status' => $workerJob->status,
            'created_at' => $workerJob->created_at?->toISOString(),
            'finished_at' => $workerJob->finished_at?->toISOString(),
        ];
    }
}
