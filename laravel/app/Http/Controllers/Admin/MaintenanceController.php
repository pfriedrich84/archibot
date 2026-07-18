<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Command;
use App\Services\ArchibotResetService;
use App\Services\Pipeline\DocumentPipelineStarter;
use App\Services\Pipeline\MaintenanceCommandDispatcher;
use App\Services\Pipeline\PipelineRecoveryDispatcher;
use App\Support\ActiveOperationsSnapshot;
use App\Support\DiagnosticPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MaintenanceController extends Controller
{
    public function __construct(private readonly DiagnosticPresenter $diagnostics) {}

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
                'reset' => route('admin.maintenance.reset'),
            ],
            'recentAuditLogs' => AuditLog::query()
                ->where('event', 'like', 'maintenance.%')
                ->latest()
                ->limit(10)
                ->get()
                ->map(fn (AuditLog $auditLog) => [
                    'id' => $auditLog->id,
                    'event' => $this->diagnostics->diagnosticEventType($auditLog->event),
                    'target_type' => in_array($auditLog->target_type, ['pipeline_recovery', 'command'], true)
                        ? $auditLog->target_type
                        : 'unknown',
                    'target_id' => ctype_digit((string) $auditLog->target_id)
                        ? $auditLog->target_id
                        : $this->diagnostics->opaqueReference($auditLog->target_id),
                    'metadata' => $this->diagnostics->metadata($auditLog->metadata),
                    'created_at' => $auditLog->created_at?->toISOString(),
                ])
                ->values(),
        ]);
    }

    public function recoverPipelineActors(Request $request, PipelineRecoveryDispatcher $recovery): RedirectResponse
    {
        abort_unless((bool) $request->user()?->is_admin, 403);

        $summary = $recovery->runRecoveryScan(100);

        $this->audit($request, 'maintenance.pipeline_recovery_requested', 'pipeline_recovery', 'scan', $summary);

        $message = isset($summary['scan_skipped_locked'])
            ? 'Durable pipeline recovery skipped because another scan is active.'
            : 'Durable pipeline recovery completed.';

        return back()->with('status', $message);
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

        $this->audit($request, 'maintenance.document_pipeline_requested', 'pipeline_run', (string) $result->pipelineRun->id, [
            'actor_principal' => 'authenticated_user',
            'paperless_document_id' => (int) $validated['paperless_document_id'],
            'force' => $force,
        ]);

        return redirect()->route('pipeline-runs.show', $result->pipelineRun)
            ->with('status', 'Document Pipeline Run queued.');
    }

    public function reset(Request $request, ArchibotResetService $reset): RedirectResponse
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
        $request->validate(['confirmation' => ['required', 'in:RESET']]);

        $cleared = $reset->reset(false);
        $this->audit($request, 'maintenance.reset_completed', 'system', 'archibot', [
            'actor_principal' => 'authenticated_user',
            'include_config' => false,
            'cleared_tables' => $cleared,
        ]);

        return redirect()->route('admin.maintenance.index')->with('status', 'ArchiBot operational state reset complete.');
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
