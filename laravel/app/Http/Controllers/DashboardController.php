<?php

namespace App\Http\Controllers;

use App\Models\ActorExecution;
use App\Models\AppSetting;
use App\Models\Command;
use App\Models\EmbeddingIndexState;
use App\Models\PipelineRun;
use App\Models\ReviewSuggestion;
use App\Models\SetupState;
use App\Models\WebhookDelivery;
use App\Models\WorkerJob;
use App\Services\Paperless\PaperlessClient;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $paperlessUrl = AppSetting::getValue('paperless.url');
        $inboxTagId = (int) (AppSetting::getValue('paperless.inbox_tag_id', '0') ?? 0);
        $paperlessAvailable = null;
        $paperlessError = null;

        if ($paperlessUrl && $request->user()->paperless_token) {
            try {
                $paperlessAvailable = app(PaperlessClient::class, ['baseUrl' => $paperlessUrl])
                    ->ping($request->user()->paperless_token);
            } catch (\Throwable $exception) {
                $paperlessAvailable = false;
                $paperlessError = $exception->getMessage();
            }
        }

        $embeddingIndexState = EmbeddingIndexState::query()->latest()->first();
        $pendingEmbeddingBuildCommands = Command::query()
            ->where('type', 'embedding_index_build')
            ->whereIn('status', ['pending', 'queued', 'running'])
            ->count();
        $pendingPollCommands = Command::query()
            ->where('type', 'poll_reconciliation')
            ->whereIn('status', ['pending', 'queued', 'running'])
            ->count();
        $pendingReindexCommands = Command::query()
            ->where('type', 'reindex')
            ->whereIn('status', ['pending', 'queued', 'running'])
            ->count();

        return Inertia::render('Dashboard', [
            'status' => [
                'setup_complete' => SetupState::current()->complete,
                'paperless_url_configured' => filled($paperlessUrl),
                'paperless_available' => $paperlessAvailable,
                'paperless_error' => $paperlessError,
                'inbox_tag_id' => $inboxTagId,
            ],
            'embeddingIndex' => [
                'id' => $embeddingIndexState?->id,
                'status' => $embeddingIndexState?->status ?? 'missing',
                'embedding_model' => $embeddingIndexState?->embedding_model,
                'document_count' => $embeddingIndexState?->document_count ?? 0,
                'embedded_count' => $embeddingIndexState?->embedded_count ?? 0,
                'failed_count' => $embeddingIndexState?->failed_count ?? 0,
                'started_at' => $embeddingIndexState?->started_at?->toISOString(),
                'completed_at' => $embeddingIndexState?->completed_at?->toISOString(),
                'error' => $embeddingIndexState?->error,
                'pending_build_commands' => $pendingEmbeddingBuildCommands,
                'build_url' => route('embedding-index.build'),
                'mark_stale_url' => route('embedding-index.mark-stale'),
            ],
            'maintenance' => [
                'poll_url' => route('maintenance.poll'),
                'reindex_url' => route('maintenance.reindex'),
                'pending_poll_commands' => $pendingPollCommands,
                'pending_reindex_commands' => $pendingReindexCommands,
                'poll_interval_seconds' => (int) config('archibot.poll_interval_seconds', 600),
            ],
            'counts' => [
                'pending_reviews' => ReviewSuggestion::query()
                    ->where('status', ReviewSuggestion::STATUS_PENDING)
                    ->count(),
                'queued_or_running_workers' => WorkerJob::query()
                    ->whereIn('status', [WorkerJob::STATUS_QUEUED, WorkerJob::STATUS_RUNNING])
                    ->count(),
                'failed_workers' => WorkerJob::query()
                    ->where('status', WorkerJob::STATUS_FAILED)
                    ->count(),
                'queued_webhook_deliveries' => WebhookDelivery::query()
                    ->where('status', WebhookDelivery::STATUS_QUEUED)
                    ->count(),
                'active_pipeline_runs' => PipelineRun::query()
                    ->whereIn('status', [PipelineRun::STATUS_PENDING, PipelineRun::STATUS_QUEUED, PipelineRun::STATUS_RUNNING, PipelineRun::STATUS_RETRYING])
                    ->count(),
                'blocked_pipeline_runs' => PipelineRun::query()
                    ->where('status', PipelineRun::STATUS_BLOCKED)
                    ->count(),
                'failed_pipeline_runs' => PipelineRun::query()
                    ->whereIn('status', [PipelineRun::STATUS_FAILED, PipelineRun::STATUS_FAILED_PERMANENT, PipelineRun::STATUS_PARTIALLY_FAILED])
                    ->count(),
                'running_actor_executions' => ActorExecution::query()
                    ->where('status', 'running')
                    ->count(),
                'failed_actor_executions' => ActorExecution::query()
                    ->where('status', 'failed')
                    ->count(),
            ],
            'recentWebhookDeliveries' => WebhookDelivery::query()
                ->latest('received_at')
                ->limit(5)
                ->get()
                ->map(fn (WebhookDelivery $delivery) => [
                    'id' => $delivery->id,
                    'event_type' => $delivery->event_type,
                    'status' => $delivery->status,
                    'paperless_document_id' => $delivery->paperless_document_id,
                    'error' => $delivery->error,
                    'received_at' => $delivery->received_at?->toISOString(),
                    'processed_at' => $delivery->processed_at?->toISOString(),
                    'retry_url' => route('webhook-deliveries.retry', $delivery),
                    'dismiss_url' => route('webhook-deliveries.dismiss', $delivery),
                    'can_retry' => in_array($delivery->status, [
                        WebhookDelivery::STATUS_FAILED,
                        WebhookDelivery::STATUS_FAILED_PERMANENT,
                        WebhookDelivery::STATUS_BLOCKED,
                    ], true),
                    'can_dismiss' => in_array($delivery->status, [
                        WebhookDelivery::STATUS_FAILED,
                        WebhookDelivery::STATUS_FAILED_PERMANENT,
                        WebhookDelivery::STATUS_BLOCKED,
                    ], true),
                ]),
            'recentActorExecutions' => ActorExecution::query()
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn (ActorExecution $execution) => [
                    'id' => $execution->id,
                    'pipeline_run_id' => $execution->pipeline_run_id,
                    'actor_name' => $execution->actor_name,
                    'queue_name' => $execution->queue_name,
                    'status' => $execution->status,
                    'attempt' => $execution->attempt,
                    'worker_id' => $execution->worker_id,
                    'progress_total' => $execution->progress_total,
                    'progress_done' => $execution->progress_done,
                    'progress_failed' => $execution->progress_failed,
                    'progress_current_item' => $execution->progress_current_item,
                    'progress_message' => $execution->progress_message,
                    'duration_ms' => $execution->duration_ms,
                    'error_type' => $execution->error_type,
                    'started_at' => $execution->started_at?->toISOString(),
                    'finished_at' => $execution->finished_at?->toISOString(),
                ]),
            'recentPipelineRuns' => PipelineRun::query()
                ->withCount(['items as failed_items_count' => fn ($query) => $query->where('status', 'failed')])
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn (PipelineRun $run) => [
                    'id' => $run->id,
                    'type' => $run->type,
                    'status' => $run->status,
                    'trigger_source' => $run->trigger_source,
                    'paperless_document_id' => $run->paperless_document_id,
                    'progress_total' => $run->progress_total,
                    'progress_done' => $run->progress_done,
                    'progress_failed' => $run->progress_failed,
                    'progress_skipped' => $run->progress_skipped,
                    'progress_current_phase' => $run->progress_current_phase,
                    'progress_message' => $run->progress_message,
                    'reprocess_requested' => $run->reprocess_requested,
                    'created_at' => $run->created_at?->toISOString(),
                    'updated_at' => $run->updated_at?->toISOString(),
                    'retry_url' => route('pipeline-runs.retry', $run),
                    'retry_failed_items_url' => route('pipeline-runs.retry-failed-items', $run),
                    'cancel_url' => route('pipeline-runs.cancel', $run),
                    'failed_items_count' => $run->failed_items_count,
                    'can_retry' => in_array($run->status, [
                        PipelineRun::STATUS_BLOCKED,
                        PipelineRun::STATUS_FAILED,
                        PipelineRun::STATUS_FAILED_PERMANENT,
                        PipelineRun::STATUS_PARTIALLY_FAILED,
                        PipelineRun::STATUS_CANCELLED,
                    ], true),
                    'can_retry_failed_items' => $run->failed_items_count > 0 && in_array($run->status, [
                        PipelineRun::STATUS_FAILED,
                        PipelineRun::STATUS_PARTIALLY_FAILED,
                    ], true),
                    'can_cancel' => in_array($run->status, [
                        PipelineRun::STATUS_PENDING,
                        PipelineRun::STATUS_QUEUED,
                        PipelineRun::STATUS_RUNNING,
                        PipelineRun::STATUS_RETRYING,
                    ], true),
                ]),
            'recentWorkerJobs' => WorkerJob::query()
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn (WorkerJob $job) => [
                    'id' => $job->id,
                    'type' => $job->type,
                    'status' => $job->status,
                    'created_at' => $job->created_at?->toISOString(),
                    'finished_at' => $job->finished_at?->toISOString(),
                ]),
        ]);
    }
}
