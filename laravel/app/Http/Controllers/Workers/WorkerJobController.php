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
            ->with(['reviewSuggestions' => fn ($query) => $query->latest()])
            ->withCount('reviewSuggestions')
            ->latest()
            ->paginate(25)
            ->through(fn (WorkerJob $job) => [
                'id' => $job->id,
                'type' => $job->type,
                'status' => $job->status,
                'payload' => $job->payload ?? [],
                'result' => $job->result ?? [],
                'ingest' => data_get($job->result, 'ingest', []),
                'review_suggestions_count' => $job->review_suggestions_count,
                'review_suggestions' => $job->reviewSuggestions->map(fn ($suggestion) => [
                    'id' => $suggestion->id,
                    'paperless_document_id' => $suggestion->paperless_document_id,
                    'proposed_title' => $suggestion->proposed_title,
                    'status' => $suggestion->status,
                ])->values(),
                'exit_code' => $job->exit_code,
                'error' => $job->error,
                'created_at' => $job->created_at?->toISOString(),
                'started_at' => $job->started_at?->toISOString(),
                'finished_at' => $job->finished_at?->toISOString(),
            ]);

        return Inertia::render('worker/Index', [
            'jobs' => $jobs,
            'allowedTypes' => WorkerJob::allowedTypes(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(WorkerJob::allowedTypes())],
            'paperless_document_id' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validated['type'] === WorkerJob::TYPE_PROCESS_DOCUMENT && empty($validated['paperless_document_id'])) {
            return back()->withErrors(['paperless_document_id' => 'Document id is required for process-document jobs.']);
        }

        $payload = match ($validated['type']) {
            WorkerJob::TYPE_PROCESS_DOCUMENT => ['paperless_document_id' => (int) $validated['paperless_document_id']],
            WorkerJob::TYPE_POLL => ['mode' => 'inbox'],
            WorkerJob::TYPE_REINDEX => ['mode' => 'full'],
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
}
