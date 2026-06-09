<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\EmbeddingIndexState;
use App\Models\PipelineEvent;
use App\Models\PipelineItem;
use App\Models\PipelineRun;
use App\Services\Pipeline\DocumentPipelineStarter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PipelineRunController extends Controller
{
    public function index(Request $request): Response
    {
        $runs = PipelineRun::query()
            ->with(['command:id,type,status,created_at', 'webhookDelivery:id,source,event_type,status,paperless_document_id,received_at'])
            ->withCount(['events', 'items'])
            ->latest('updated_at')
            ->latest('id')
            ->paginate(25)
            ->through(fn (PipelineRun $run) => $this->runPayload($request, $run, includeDetails: false));

        return Inertia::render('pipeline-runs/Index', [
            'runs' => $runs,
            'isAdmin' => (bool) $request->user()?->is_admin,
        ]);
    }

    public function show(Request $request, PipelineRun $pipelineRun): Response
    {
        $pipelineRun->load([
            'command:id,type,status,payload,created_by_user_id,started_at,finished_at,error,created_at',
            'webhookDelivery:id,source,event_type,status,paperless_document_id,dedupe_key,request_id,received_at,processed_at,error',
            'events' => fn ($query) => $query->latest('created_at')->latest('id')->limit(50),
            'items' => fn ($query) => $query->latest('updated_at')->latest('id')->limit(50),
        ]);

        return Inertia::render('pipeline-runs/Show', [
            'run' => $this->runPayload($request, $pipelineRun, includeDetails: true),
            'isAdmin' => (bool) $request->user()?->is_admin,
        ]);
    }

    public function retry(Request $request, PipelineRun $pipelineRun): RedirectResponse
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
        abort_unless(in_array($pipelineRun->status, [
            PipelineRun::STATUS_BLOCKED,
            PipelineRun::STATUS_FAILED,
            PipelineRun::STATUS_FAILED_PERMANENT,
            PipelineRun::STATUS_PARTIALLY_FAILED,
            PipelineRun::STATUS_CANCELLED,
        ], true), 409);

        $gate = app(DocumentPipelineStarter::class)->gateAttributes(
            $this->documentProcessingGateOpen($pipelineRun),
            'queued',
            'Manual admin retry queued.',
        );
        $pipelineRun->forceFill([
            'status' => $gate['status'],
            'progress_current_phase' => $gate['progress_current_phase'],
            'progress_message' => $gate['progress_message'],
            'progress_updated_at' => now(),
            'retry_count' => $pipelineRun->retry_count + 1,
            'last_retry_at' => now(),
            'retry_reason' => 'manual_admin_retry',
            'retry_mode' => 'manual',
            'next_retry_at' => null,
            'error_type' => $gate['error_type'],
            'error' => $gate['error'],
            'finished_at' => null,
        ])->save();

        $this->audit($request, 'pipeline_run.retry_queued', $pipelineRun);

        return back()->with('status', 'Pipeline retry queued.');
    }

    public function retryFailedItems(Request $request, PipelineRun $pipelineRun): RedirectResponse
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
        abort_unless(in_array($pipelineRun->status, [
            PipelineRun::STATUS_FAILED,
            PipelineRun::STATUS_PARTIALLY_FAILED,
        ], true), 409);

        $failedItems = $pipelineRun->items()->where('status', PipelineItem::STATUS_FAILED);
        $failedItemCount = (clone $failedItems)->count();
        abort_if($failedItemCount === 0, 409);

        $failedItems->update([
            'status' => PipelineItem::STATUS_PENDING,
            'attempt' => DB::raw('attempt + 1'),
            'retry_reason' => 'manual_admin_retry_failed_items',
            'retry_mode' => 'manual',
            'last_retry_at' => now(),
            'next_retry_at' => null,
            'error' => null,
            'started_at' => null,
            'finished_at' => null,
            'updated_at' => now(),
        ]);

        $gate = app(DocumentPipelineStarter::class)->gateAttributes(
            $this->documentProcessingGateOpen($pipelineRun),
            'retry_failed_items',
            "Manual admin retry queued for {$failedItemCount} failed pipeline item(s).",
        );
        $pipelineRun->forceFill([
            'status' => $gate['status'],
            'progress_current_phase' => $gate['progress_current_phase'],
            'progress_message' => $gate['progress_message'],
            'progress_failed' => max(0, $pipelineRun->progress_failed - $failedItemCount),
            'progress_updated_at' => now(),
            'retry_count' => $pipelineRun->retry_count + 1,
            'last_retry_at' => now(),
            'retry_reason' => 'manual_admin_retry_failed_items',
            'retry_mode' => 'manual',
            'next_retry_at' => null,
            'error_type' => $gate['error_type'],
            'error' => $gate['error'],
            'finished_at' => null,
        ])->save();

        PipelineEvent::query()->create([
            'pipeline_run_id' => $pipelineRun->id,
            'event_type' => 'job_control.retry_failed_items_requested',
            'paperless_document_id' => $pipelineRun->paperless_document_id,
            'level' => 'info',
            'message' => 'Failed pipeline items queued for manual retry.',
            'payload' => [
                'actor_user_id' => $request->user()->id,
                'actor_is_admin' => true,
                'failed_item_count' => $failedItemCount,
                'retry_mode' => 'manual',
            ],
        ]);

        $this->audit($request, 'pipeline_run.retry_failed_items_queued', $pipelineRun, [
            'failed_item_count' => $failedItemCount,
        ]);

        return back()->with('status', 'Failed pipeline items retry queued.');
    }

    public function cancel(Request $request, PipelineRun $pipelineRun): RedirectResponse
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
        abort_unless(in_array($pipelineRun->status, [
            PipelineRun::STATUS_PENDING,
            PipelineRun::STATUS_QUEUED,
            PipelineRun::STATUS_RUNNING,
            PipelineRun::STATUS_RETRYING,
        ], true), 409);

        $pipelineRun->forceFill([
            'status' => PipelineRun::STATUS_CANCEL_REQUESTED,
            'progress_message' => 'Manual admin cancellation requested.',
            'progress_updated_at' => now(),
            'error_type' => 'cancel_requested',
            'error' => 'Manual admin cancellation requested.',
        ])->save();

        $this->audit($request, 'pipeline_run.cancel_requested', $pipelineRun);

        return back()->with('status', 'Pipeline cancellation requested.');
    }

    /**
     * @return array<string, mixed>
     */
    private function runPayload(Request $request, PipelineRun $run, bool $includeDetails): array
    {
        $canRetry = (bool) $request->user()?->is_admin
            && in_array($run->status, [
                PipelineRun::STATUS_BLOCKED,
                PipelineRun::STATUS_FAILED,
                PipelineRun::STATUS_FAILED_PERMANENT,
                PipelineRun::STATUS_PARTIALLY_FAILED,
                PipelineRun::STATUS_CANCELLED,
            ], true);
        $canRetryFailedItems = (bool) $request->user()?->is_admin
            && in_array($run->status, [PipelineRun::STATUS_FAILED, PipelineRun::STATUS_PARTIALLY_FAILED], true);
        $canCancel = (bool) $request->user()?->is_admin
            && in_array($run->status, [
                PipelineRun::STATUS_PENDING,
                PipelineRun::STATUS_QUEUED,
                PipelineRun::STATUS_RUNNING,
                PipelineRun::STATUS_RETRYING,
            ], true);

        $payload = [
            'id' => $run->id,
            'type' => $run->type,
            'status' => $run->status,
            'scope' => $run->scope,
            'trigger_source' => $run->trigger_source,
            'paperless_document_id' => $run->paperless_document_id,
            'progress_total' => $run->progress_total,
            'progress_done' => $run->progress_done,
            'progress_failed' => $run->progress_failed,
            'progress_skipped' => $run->progress_skipped,
            'progress_current_phase' => $run->progress_current_phase,
            'progress_phase_total' => $run->progress_phase_total,
            'progress_phase_done' => $run->progress_phase_done,
            'progress_message' => $run->progress_message,
            'progress_updated_at' => $run->progress_updated_at?->toISOString(),
            'retry_count' => $run->retry_count,
            'max_retries' => $run->max_retries,
            'next_retry_at' => $run->next_retry_at?->toISOString(),
            'last_retry_at' => $run->last_retry_at?->toISOString(),
            'retry_reason' => $run->retry_reason,
            'retry_mode' => $run->retry_mode,
            'reprocess_requested' => $run->reprocess_requested,
            'reprocess_reason' => $run->reprocess_reason,
            'reprocess_mode' => $run->reprocess_mode,
            'started_at' => $run->started_at?->toISOString(),
            'finished_at' => $run->finished_at?->toISOString(),
            'created_at' => $run->created_at?->toISOString(),
            'updated_at' => $run->updated_at?->toISOString(),
            'error_type' => $run->error_type,
            'error' => $run->error,
            'events_count' => $run->events_count ?? $run->events()->count(),
            'items_count' => $run->items_count ?? $run->items()->count(),
            'show_url' => route('pipeline-runs.show', $run),
            'retry_url' => route('pipeline-runs.retry', $run),
            'retry_failed_items_url' => route('pipeline-runs.retry-failed-items', $run),
            'cancel_url' => route('pipeline-runs.cancel', $run),
            'can_retry' => $canRetry,
            'can_retry_failed_items' => $canRetryFailedItems,
            'can_cancel' => $canCancel,
            'command' => $run->command ? [
                'id' => $run->command->id,
                'type' => $run->command->type,
                'status' => $run->command->status,
                'created_at' => $run->command->created_at?->toISOString(),
            ] : null,
            'webhook_delivery' => $run->webhookDelivery ? [
                'id' => $run->webhookDelivery->id,
                'source' => $run->webhookDelivery->source,
                'event_type' => $run->webhookDelivery->event_type,
                'status' => $run->webhookDelivery->status,
                'paperless_document_id' => $run->webhookDelivery->paperless_document_id,
                'show_url' => route('webhook-deliveries.show', $run->webhookDelivery),
            ] : null,
        ];

        if ($includeDetails) {
            $payload['command'] = $run->command ? array_merge($payload['command'], [
                'payload' => $run->command->payload ?? [],
                'created_by_user_id' => $run->command->created_by_user_id,
                'started_at' => $run->command->started_at?->toISOString(),
                'finished_at' => $run->command->finished_at?->toISOString(),
                'error' => $run->command->error,
            ]) : null;
            $payload['webhook_delivery'] = $run->webhookDelivery ? array_merge($payload['webhook_delivery'], [
                'dedupe_key' => $run->webhookDelivery->dedupe_key,
                'request_id' => $run->webhookDelivery->request_id,
                'received_at' => $run->webhookDelivery->received_at?->toISOString(),
                'processed_at' => $run->webhookDelivery->processed_at?->toISOString(),
                'error' => $run->webhookDelivery->error,
            ]) : null;
            $payload['events'] = $run->events->map(fn (PipelineEvent $event) => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'level' => $event->level,
                'message' => $event->message,
                'paperless_document_id' => $event->paperless_document_id,
                'webhook_delivery_id' => $event->webhook_delivery_id,
                'command_id' => $event->command_id,
                'payload' => $event->payload ?? [],
                'created_at' => $event->created_at?->toISOString(),
            ])->values();
            $payload['items'] = $run->items->map(fn (PipelineItem $item) => [
                'id' => $item->id,
                'paperless_document_id' => $item->paperless_document_id,
                'item_type' => $item->item_type,
                'status' => $item->status,
                'attempt' => $item->attempt,
                'max_attempts' => $item->max_attempts,
                'retry_reason' => $item->retry_reason,
                'retry_mode' => $item->retry_mode,
                'next_retry_at' => $item->next_retry_at?->toISOString(),
                'last_retry_at' => $item->last_retry_at?->toISOString(),
                'started_at' => $item->started_at?->toISOString(),
                'finished_at' => $item->finished_at?->toISOString(),
                'error' => $item->error,
            ])->values();
            $payload['audit_logs'] = $this->linkedAuditLogs($run);
        }

        return $payload;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function linkedAuditLogs(PipelineRun $run): array
    {
        return AuditLog::query()
            ->where(function ($query) use ($run): void {
                $query
                    ->where(fn ($query) => $query
                        ->where('target_type', 'pipeline_run')
                        ->where('target_id', (string) $run->id))
                    ->orWhere('metadata->pipeline_run_id', $run->id);

                if ($run->command_id !== null) {
                    $query->orWhere('metadata->command_id', $run->command_id);
                }

                if ($run->webhook_delivery_id !== null) {
                    $query->orWhere(fn ($query) => $query
                        ->where('target_type', 'webhook_delivery')
                        ->where('target_id', (string) $run->webhook_delivery_id));
                }
            })
            ->latest('created_at')
            ->limit(10)
            ->get(['id', 'event', 'target_type', 'target_id', 'metadata', 'created_at'])
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'event' => $log->event,
                'target_type' => $log->target_type,
                'target_id' => $log->target_id,
                'metadata' => $log->metadata ?? [],
                'created_at' => $log->created_at?->toISOString(),
            ])
            ->values()
            ->all();
    }

    private function documentProcessingGateOpen(PipelineRun $pipelineRun): bool
    {
        if ($pipelineRun->type !== 'document') {
            return true;
        }

        return EmbeddingIndexState::query()->latest()->value('status') === EmbeddingIndexState::STATUS_COMPLETE;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(Request $request, string $event, PipelineRun $pipelineRun, array $metadata = []): void
    {
        AuditLog::query()->create([
            'actor_user_id' => $request->user()->id,
            'event' => $event,
            'target_type' => 'pipeline_run',
            'target_id' => (string) $pipelineRun->id,
            'metadata' => array_merge([
                'status' => $pipelineRun->status,
                'paperless_document_id' => $pipelineRun->paperless_document_id,
                'trigger_source' => $pipelineRun->trigger_source,
            ], $metadata),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
