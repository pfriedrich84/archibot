<?php

namespace App\Http\Controllers\Workers;

use App\Http\Controllers\Controller;
use App\Jobs\RunPythonWorkerJob;
use App\Models\AuditLog;
use App\Models\WorkerJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WorkerJobController extends Controller
{
    public function index(Request $request): Response
    {
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
            ]);

        return Inertia::render('worker/Index', [
            'jobs' => $jobs,
            'allowedTypes' => WorkerJob::userQueueableTypes(),
            'isAdmin' => (bool) $request->user()?->is_admin,
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

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->is_admin, 403);

        $validated = $request->validate([
            'type' => ['required', Rule::in(WorkerJob::userQueueableTypes())],
            'paperless_document_id' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validated['type'] === WorkerJob::TYPE_PROCESS_DOCUMENT && empty($validated['paperless_document_id'])) {
            return back()->withErrors(['paperless_document_id' => 'Document id is required for process-document jobs.']);
        }

        $payload = match ($validated['type']) {
            WorkerJob::TYPE_PROCESS_DOCUMENT => ['paperless_document_id' => (int) $validated['paperless_document_id']],
            WorkerJob::TYPE_POLL => ['mode' => 'inbox'],
            WorkerJob::TYPE_REINDEX => ['mode' => 'full'],
            WorkerJob::TYPE_REINDEX_OCR => ['mode' => 'ocr', 'force' => (bool) $request->boolean('force')],
            WorkerJob::TYPE_REINDEX_EMBED => ['mode' => 'embed'],
            default => [],
        };

        $workerJob = WorkerJob::query()->create([
            'type' => $validated['type'],
            'status' => WorkerJob::STATUS_QUEUED,
            'payload' => $payload,
            'created_by_user_id' => $request->user()->id,
        ]);

        AuditLog::query()->create([
            'actor_user_id' => $request->user()->id,
            'event' => 'worker_job.queued',
            'target_type' => 'worker_job',
            'target_id' => (string) $workerJob->id,
            'metadata' => [
                'type' => $workerJob->type,
                'payload' => $payload,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        RunPythonWorkerJob::dispatch($workerJob->id);

        return redirect()->route('worker-jobs.index');
    }

    public function stop(Request $request, WorkerJob $workerJob): RedirectResponse
    {
        abort_unless($request->user()?->is_admin, 403);

        if ($workerJob->status === WorkerJob::STATUS_QUEUED) {
            $workerJob->forceFill([
                'status' => WorkerJob::STATUS_CANCELLED,
                'cancellation_requested_at' => now(),
                'finished_at' => now(),
                'error' => 'Cancelled before execution.',
            ])->save();
        } elseif ($workerJob->status === WorkerJob::STATUS_RUNNING) {
            $workerJob->forceFill([
                'status' => WorkerJob::STATUS_CANCELLING,
                'cancellation_requested_at' => now(),
            ])->save();
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

    public function retry(Request $request, WorkerJob $workerJob): RedirectResponse
    {
        abort_unless($request->user()?->is_admin, 403);

        $payload = $workerJob->payload ?? [];
        $failedDocuments = collect($workerJob->logs ?? [])
            ->filter(fn ($log) => in_array($log->event, ['document_failed', 'document_cancelled'], true))
            ->pluck('paperless_document_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($failedDocuments !== []) {
            $payload['retry_document_ids'] = $failedDocuments;
            if ($workerJob->type === WorkerJob::TYPE_PROCESS_DOCUMENT) {
                $payload['paperless_document_id'] = (int) $failedDocuments[0];
            }
        }

        $retry = WorkerJob::query()->create([
            'type' => $workerJob->type,
            'status' => WorkerJob::STATUS_QUEUED,
            'payload' => $payload,
            'retry_of_worker_job_id' => $workerJob->id,
            'created_by_user_id' => $request->user()->id,
        ]);

        AuditLog::query()->create([
            'actor_user_id' => $request->user()->id,
            'event' => 'worker_job.retried',
            'target_type' => 'worker_job',
            'target_id' => (string) $retry->id,
            'metadata' => ['retry_of_worker_job_id' => $workerJob->id, 'payload' => $payload],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        RunPythonWorkerJob::dispatch($retry->id);

        return redirect()->route('worker-jobs.index');
    }
}
