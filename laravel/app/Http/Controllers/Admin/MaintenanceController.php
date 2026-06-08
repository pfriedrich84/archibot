<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\WorkerJob;
use App\Services\Pipeline\MaintenanceCommandDispatcher;
use App\Services\Workers\WorkerJobRecovery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MaintenanceController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless((bool) $request->user()?->is_admin, 403);

        return Inertia::render('admin/Maintenance', [
            'workerJobCounts' => [
                'queued' => WorkerJob::query()->where('status', WorkerJob::STATUS_QUEUED)->count(),
                'running' => WorkerJob::query()->where('status', WorkerJob::STATUS_RUNNING)->count(),
                'cancelling' => WorkerJob::query()->where('status', WorkerJob::STATUS_CANCELLING)->count(),
                'failed' => WorkerJob::query()->whereIn('status', [WorkerJob::STATUS_FAILED, WorkerJob::STATUS_PARTIALLY_FAILED])->count(),
            ],
            'recentAuditLogs' => AuditLog::query()
                ->where('event', 'like', 'maintenance.%')
                ->latest()
                ->limit(10)
                ->get()
                ->map(fn (AuditLog $auditLog) => [
                    'id' => $auditLog->id,
                    'event' => $auditLog->event,
                    'target_type' => $auditLog->target_type,
                    'target_id' => $auditLog->target_id,
                    'metadata' => $auditLog->metadata ?? [],
                    'created_at' => $auditLog->created_at?->toISOString(),
                ])
                ->values(),
        ]);
    }

    public function recoverWorkerJobs(Request $request, WorkerJobRecovery $recovery): RedirectResponse
    {
        abort_unless((bool) $request->user()?->is_admin, 403);

        $summary = $recovery->recoverAll();

        $this->audit($request, 'maintenance.worker_jobs_recovery_requested', 'worker_jobs', 'recovery', $summary);

        return back()->with('status', 'Worker job recovery completed.');
    }

    public function startWorkerJob(Request $request, MaintenanceCommandDispatcher $maintenanceCommands): RedirectResponse
    {
        abort_unless((bool) $request->user()?->is_admin, 403);

        $validated = $request->validate([
            'type' => ['required', Rule::in([
                WorkerJob::TYPE_POLL,
                WorkerJob::TYPE_REINDEX,
                WorkerJob::TYPE_REINDEX_OCR,
                WorkerJob::TYPE_REINDEX_EMBED,
            ])],
            'force' => ['nullable', 'boolean'],
        ]);

        $force = $request->boolean('force');
        $type = $validated['type'];
        if ($type === WorkerJob::TYPE_POLL) {
            $maintenanceCommands->queuePollReconciliation($request, null, [
                'legacy_worker_job_action' => WorkerJob::TYPE_POLL,
                'force' => $force,
            ]);

            return back()->with('status', 'Polling reconciliation command queued.');
        }

        if ($type === WorkerJob::TYPE_REINDEX) {
            $maintenanceCommands->queueReindex($request, null, [
                'legacy_worker_job_action' => WorkerJob::TYPE_REINDEX,
            ]);

            return back()->with('status', 'Reindex command queued.');
        }

        if ($type === WorkerJob::TYPE_REINDEX_EMBED) {
            $maintenanceCommands->queueEmbeddingIndexBuild($request, null, [
                'legacy_worker_job_action' => WorkerJob::TYPE_REINDEX_EMBED,
            ]);

            return back()->with('status', 'Embedding index build command queued.');
        }

        if ($type === WorkerJob::TYPE_REINDEX_OCR) {
            $maintenanceCommands->queueOcrReindex($request, null, $force, [
                'legacy_worker_job_action' => WorkerJob::TYPE_REINDEX_OCR,
            ]);

            return back()->with('status', 'OCR reindex command queued.');
        }

        abort(422, 'Unsupported maintenance action.');
    }

    /** @param array<string, mixed> $metadata */
    private function audit(Request $request, string $event, string $targetType, string $targetId, array $metadata = []): void
    {
        AuditLog::query()->create([
            'actor_user_id' => $request->user()->id,
            'event' => $event,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'metadata' => $metadata,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
