<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\EmbeddingIndexState;
use App\Models\PipelineEvent;
use App\Models\PipelineItem;
use App\Models\PipelineRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PipelineRunController extends Controller
{
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

        $gateOpen = $this->documentProcessingGateOpen($pipelineRun);
        $pipelineRun->forceFill([
            'status' => $gateOpen ? PipelineRun::STATUS_PENDING : PipelineRun::STATUS_BLOCKED,
            'progress_current_phase' => $gateOpen ? 'queued' : 'blocked',
            'progress_message' => $gateOpen ? 'Manual admin retry queued.' : 'Waiting for embedding index to complete.',
            'progress_updated_at' => now(),
            'retry_count' => $pipelineRun->retry_count + 1,
            'last_retry_at' => now(),
            'retry_reason' => 'manual_admin_retry',
            'retry_mode' => 'manual',
            'next_retry_at' => null,
            'error_type' => $gateOpen ? null : 'embedding_index_not_ready',
            'error' => $gateOpen ? null : 'Waiting for embedding index to complete.',
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

        $gateOpen = $this->documentProcessingGateOpen($pipelineRun);
        $pipelineRun->forceFill([
            'status' => $gateOpen ? PipelineRun::STATUS_PENDING : PipelineRun::STATUS_BLOCKED,
            'progress_current_phase' => $gateOpen ? 'retry_failed_items' : 'blocked',
            'progress_message' => $gateOpen ? "Manual admin retry queued for {$failedItemCount} failed pipeline item(s)." : 'Waiting for embedding index to complete.',
            'progress_failed' => max(0, $pipelineRun->progress_failed - $failedItemCount),
            'progress_updated_at' => now(),
            'retry_count' => $pipelineRun->retry_count + 1,
            'last_retry_at' => now(),
            'retry_reason' => 'manual_admin_retry_failed_items',
            'retry_mode' => 'manual',
            'next_retry_at' => null,
            'error_type' => $gateOpen ? null : 'embedding_index_not_ready',
            'error' => $gateOpen ? null : 'Waiting for embedding index to complete.',
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
