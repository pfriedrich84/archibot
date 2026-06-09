<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Command;
use App\Services\Pipeline\DocumentPipelineStarter;
use App\Services\Pipeline\MaintenanceCommandDispatcher;
use App\Services\Pipeline\PipelineRecoveryDispatcher;
use App\Support\ActiveOperationsSnapshot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MaintenanceController extends Controller
{
    public function index(Request $request, ActiveOperationsSnapshot $activeOperations): Response
    {
        abort_unless((bool) $request->user()?->is_admin, 403);

        return Inertia::render('admin/Maintenance', [
            'commandCounts' => [
                'pending' => Command::query()->where('status', Command::STATUS_PENDING)->count(),
                'queued' => Command::query()->where('status', Command::STATUS_QUEUED)->count(),
                'running' => Command::query()->where('status', Command::STATUS_RUNNING)->count(),
                'failed' => Command::query()->whereIn('status', [Command::STATUS_FAILED, Command::STATUS_FAILED_PERMANENT])->count(),
            ],
            'activeOperations' => $activeOperations->make(),
            'actionUrls' => [
                'commands' => route('admin.maintenance.commands'),
                'recover_pipeline_actors' => route('admin.maintenance.recover-pipeline-actors'),
                'mark_embedding_stale' => route('embedding-index.mark-stale'),
                'document_pipeline' => route('admin.maintenance.document-pipeline'),
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

    public function recoverPipelineActors(Request $request, PipelineRecoveryDispatcher $recovery): RedirectResponse
    {
        abort_unless((bool) $request->user()?->is_admin, 403);

        $limit = 100;
        $summary = [
            'webhook_deliveries_redispatched' => $recovery->recoverQueuedWebhookDeliveries($limit),
            'document_pipeline_runs_redispatched' => $recovery->recoverDocumentPipelineRuns($limit),
            'commands_redispatched' => $recovery->recoverPendingCommands($limit),
        ];

        $this->audit($request, 'maintenance.pipeline_recovery_requested', 'pipeline_recovery', 'scan', $summary);

        return back()->with('status', 'Durable pipeline recovery completed.');
    }

    public function startDocumentPipeline(Request $request, DocumentPipelineStarter $pipelineStarter): RedirectResponse
    {
        abort_unless((bool) $request->user()?->is_admin, 403);

        $validated = $request->validate([
            'paperless_document_id' => ['required', 'integer', 'min:1'],
            'force' => ['nullable', 'boolean'],
        ]);

        $force = $request->boolean('force');
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

    public function startCommand(Request $request, MaintenanceCommandDispatcher $maintenanceCommands): RedirectResponse
    {
        abort_unless((bool) $request->user()?->is_admin, 403);

        $validated = $request->validate([
            'type' => ['required', Rule::in([
                'poll',
                'reindex',
                'reindex_ocr',
                'reindex_embed',
            ])],
            'force' => ['nullable', 'boolean'],
        ]);

        $force = $request->boolean('force');
        $type = $validated['type'];
        if ($type === 'poll') {
            $maintenanceCommands->queuePollReconciliation($request, null, [
                'force' => $force,
            ]);

            return back()->with('status', 'Polling reconciliation command queued.');
        }

        if ($type === 'reindex') {
            $maintenanceCommands->queueReindex($request);

            return back()->with('status', 'Reindex command queued.');
        }

        if ($type === 'reindex_embed') {
            $maintenanceCommands->queueEmbeddingIndexBuild($request);

            return back()->with('status', 'Embedding index build command queued.');
        }

        if ($type === 'reindex_ocr') {
            $maintenanceCommands->queueOcrReindex($request, null, $force);

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
