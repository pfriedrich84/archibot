<?php

namespace App\Http\Controllers;

use App\Models\ActorExecution;
use App\Models\AppSetting;
use App\Models\Command;
use App\Models\PipelineItem;
use App\Models\PipelineRun;
use App\Models\ReviewSuggestion;
use App\Models\SetupState;
use App\Models\WebhookDelivery;
use App\Services\Paperless\CanonicalPaperlessOrigin;
use App\Services\Paperless\PaperlessClient;
use App\Support\ActiveOperationsSnapshot;
use App\Support\DiagnosticPresenter;
use App\Support\EmbeddingIndexSnapshot;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(private readonly DiagnosticPresenter $diagnostics) {}

    public function __invoke(Request $request, EmbeddingIndexSnapshot $embeddingSnapshots, ActiveOperationsSnapshot $activeOperations): Response
    {
        $paperlessUrl = app(CanonicalPaperlessOrigin::class)->url();
        $inboxTagId = (int) (AppSetting::getValue('paperless.inbox_tag_id', '0') ?? 0);
        $paperlessAvailable = null;
        $paperlessError = null;
        $inboxTagLabel = null;
        $llmProvider = $this->settingValue('llm.provider');
        $ollamaUrl = $this->settingValue('ollama.url');
        $ocrMode = $this->settingValue('ocr.mode');

        if ($paperlessUrl && $request->user()->paperless_token) {
            try {
                $client = app(PaperlessClient::class);
                $paperlessAvailable = $client->ping($request->user()->paperless_token);

                if ($paperlessAvailable && $inboxTagId > 0) {
                    try {
                        $inboxTag = collect($client->tags($request->user()->paperless_token))
                            ->firstWhere('id', $inboxTagId);
                        $inboxTagLabel = is_array($inboxTag)
                            ? sprintf('%s (#%s)', $inboxTag['name'] ?? 'Unnamed tag', $inboxTag['id'])
                            : null;
                    } catch (\Throwable) {
                        $inboxTagLabel = null;
                    }
                }
            } catch (\Throwable) {
                $paperlessAvailable = false;
                $paperlessError = 'Paperless server is not reachable.';
            }
        }

        $isAdmin = (bool) $request->user()?->is_admin;

        if ($isAdmin) {
            $embeddingIndexSnapshot = $this->diagnostics->embeddingSnapshot($embeddingSnapshots->forRequest($request));
            $pendingEmbeddingBuildCommands = Command::query()
                ->where('type', Command::TYPE_EMBEDDING_INDEX_BUILD)
                ->whereIn('status', Command::activeStatuses())
                ->count();
            $pendingPollCommands = Command::query()
                ->where('type', Command::TYPE_POLL_RECONCILIATION)
                ->whereIn('status', Command::activeStatuses())
                ->count();
            $pendingReindexCommands = Command::query()
                ->where('type', Command::TYPE_REINDEX)
                ->whereIn('status', Command::activeStatuses())
                ->count();
        }

        return Inertia::render('Dashboard', [
            'status' => [
                'setup_complete' => SetupState::current()->is_complete,
                'paperless_url_configured' => filled($paperlessUrl),
                'user_paperless_token_present' => filled($request->user()->paperless_token),
                'paperless_available' => $paperlessAvailable,
                'paperless_error' => $paperlessError,
                'inbox_tag_id' => $inboxTagId,
                'inbox_tag_label' => $inboxTagLabel,
                'llm_provider' => $this->diagnostics->typedScalar('llm_provider', $llmProvider),
                'ollama_or_provider_configured' => filled($ollamaUrl) || filled($llmProvider),
                'ocr_mode' => $this->diagnostics->typedScalar('ocr_mode', $ocrMode),
            ],
            ...($isAdmin ? [
                'embeddingIndex' => [
                    ...$embeddingIndexSnapshot,
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
                    'document_processing_active' => PipelineRun::query()
                        ->where('type', 'document')
                        ->whereIn('status', [PipelineRun::STATUS_PENDING, PipelineRun::STATUS_QUEUED, PipelineRun::STATUS_RUNNING, PipelineRun::STATUS_RETRYING])
                        ->exists(),
                    'reindex_active' => Command::query()
                        ->whereIn('type', [Command::TYPE_REINDEX, Command::TYPE_REINDEX_OCR, Command::TYPE_EMBEDDING_INDEX_BUILD])
                        ->whereIn('status', Command::activeStatuses())
                        ->exists(),
                ],
                'counts' => [
                    'pending_reviews' => ReviewSuggestion::pendingReviewQueueCount(),
                    'queued_webhook_deliveries' => WebhookDelivery::query()
                        ->where('status', WebhookDelivery::STATUS_QUEUED)
                        ->count(),
                    'active_pipeline_runs' => PipelineRun::query()
                        ->whereIn('status', [PipelineRun::STATUS_PENDING, PipelineRun::STATUS_QUEUED, PipelineRun::STATUS_RUNNING, PipelineRun::STATUS_RETRYING])
                        ->count(),
                    'pending_pipeline_runs' => PipelineRun::query()
                        ->where('status', PipelineRun::STATUS_PENDING)
                        ->count(),
                    'queued_pipeline_runs' => PipelineRun::query()
                        ->where('status', PipelineRun::STATUS_QUEUED)
                        ->count(),
                    'running_pipeline_runs' => PipelineRun::query()
                        ->where('status', PipelineRun::STATUS_RUNNING)
                        ->count(),
                    'retrying_pipeline_runs' => PipelineRun::query()
                        ->where('status', PipelineRun::STATUS_RETRYING)
                        ->count(),
                    'blocked_pipeline_runs' => PipelineRun::query()
                        ->where('status', PipelineRun::STATUS_BLOCKED)
                        ->count(),
                    'failed_pipeline_runs' => PipelineRun::query()
                        ->whereIn('status', [PipelineRun::STATUS_FAILED, PipelineRun::STATUS_FAILED_PERMANENT, PipelineRun::STATUS_PARTIALLY_FAILED])
                        ->count(),
                    'running_actor_executions' => ActorExecution::query()
                        ->where('status', ActorExecution::STATUS_RUNNING)
                        ->count(),
                    'failed_actor_executions' => ActorExecution::query()
                        ->where('status', ActorExecution::STATUS_FAILED)
                        ->count(),
                ],
                'activeOperations' => $activeOperations->make(
                    commandStatuses: [Command::STATUS_RUNNING],
                    pipelineStatuses: [PipelineRun::STATUS_RUNNING],
                ),
                'recentWebhookDeliveries' => WebhookDelivery::query()
                    ->latest('received_at')
                    ->limit(5)
                    ->get()
                    ->map(fn (WebhookDelivery $delivery) => [
                        'id' => $delivery->id,
                        'event_type' => $this->diagnostics->webhookEventType($delivery->event_type),
                        'status' => $this->diagnostics->typedScalar('status', $delivery->status),
                        'paperless_document_id' => $delivery->paperless_document_id,
                        'error' => $this->diagnostics->redactedMessage($delivery->error),
                        'received_at' => $delivery->received_at?->toISOString(),
                        'processed_at' => $delivery->processed_at?->toISOString(),
                        'show_url' => route('webhook-deliveries.show', $delivery),
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
                        'actor_name' => $this->diagnostics->actorName($execution->actor_name),
                        'queue_name' => $execution->queue_name === null
                            ? null
                            : $this->diagnostics->typedScalar('queue_name', $execution->queue_name),
                        'status' => $this->diagnostics->typedScalar('status', $execution->status),
                        'attempt' => $execution->attempt,
                        'worker_id' => $this->diagnostics->opaqueReference($execution->worker_id),
                        'progress_total' => $execution->progress_total,
                        'progress_done' => $execution->progress_done,
                        'progress_failed' => $execution->progress_failed,
                        'progress_current_item' => $this->diagnostics->redactedMessage($execution->progress_current_item),
                        'progress_message' => $this->diagnostics->redactedMessage($execution->progress_message),
                        'duration_ms' => $execution->duration_ms,
                        'error_type' => $this->diagnostics->errorType($execution->error_type),
                        'started_at' => $execution->started_at?->toISOString(),
                        'finished_at' => $execution->finished_at?->toISOString(),
                    ]),
                'recentPipelineRuns' => PipelineRun::query()
                    ->withCount(['items as failed_items_count' => fn ($query) => $query->where('status', PipelineItem::STATUS_FAILED)])
                    ->latest()
                    ->limit(5)
                    ->get()
                    ->map(fn (PipelineRun $run) => [
                        'id' => $run->id,
                        'type' => $this->diagnostics->typedScalar('pipeline_type', $run->type),
                        'status' => $this->diagnostics->typedScalar('status', $run->status),
                        'trigger_source' => $this->diagnostics->typedScalar('trigger_source', $run->trigger_source),
                        'paperless_document_id' => $run->paperless_document_id,
                        'progress_total' => $run->progress_total,
                        'progress_done' => $run->progress_done,
                        'progress_failed' => $run->progress_failed,
                        'progress_skipped' => $run->progress_skipped,
                        'progress_current_phase' => $run->progress_current_phase === null
                            ? null
                            : $this->diagnostics->typedScalar('phase', $run->progress_current_phase),
                        'progress_message' => $this->diagnostics->redactedMessage($run->progress_message),
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
                'recentErrors' => $this->recentErrors(),
            ] : [
                'counts' => [
                    'pending_reviews' => ReviewSuggestion::pendingReviewQueueCount(),
                ],
            ]),
        ]);
    }

    private function settingValue(string $key): ?string
    {
        $value = AppSetting::getValue($key);

        if (filled($value)) {
            return $value;
        }

        return config('archibot_settings.definitions')[$key]['default'] ?? null;
    }

    /** @return array<int, array{source: string, id: int, status: string, message: ?string, occurred_at: ?string}> */
    private function recentErrors(): array
    {
        $webhookErrors = WebhookDelivery::query()
            ->whereIn('status', [
                WebhookDelivery::STATUS_FAILED,
                WebhookDelivery::STATUS_FAILED_PERMANENT,
                WebhookDelivery::STATUS_BLOCKED,
            ])
            ->latest('processed_at')
            ->latest('received_at')
            ->limit(5)
            ->get()
            ->map(fn (WebhookDelivery $delivery): array => [
                'source' => 'webhook_delivery',
                'id' => $delivery->id,
                'status' => $this->diagnostics->typedScalar('status', $delivery->status),
                'message' => $this->diagnostics->redactedMessage($delivery->error),
                'occurred_at' => $delivery->processed_at?->toISOString() ?? $delivery->received_at?->toISOString(),
            ]);

        $pipelineErrors = PipelineRun::query()
            ->whereIn('status', [
                PipelineRun::STATUS_FAILED,
                PipelineRun::STATUS_FAILED_PERMANENT,
                PipelineRun::STATUS_PARTIALLY_FAILED,
            ])
            ->latest('finished_at')
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (PipelineRun $run): array => [
                'source' => 'pipeline_run',
                'id' => $run->id,
                'status' => $this->diagnostics->typedScalar('status', $run->status),
                'message' => $this->diagnostics->redactedMessage($run->error),
                'occurred_at' => $run->finished_at?->toISOString() ?? $run->updated_at?->toISOString(),
            ]);

        return $webhookErrors
            ->concat($pipelineErrors)
            ->sortByDesc('occurred_at')
            ->take(5)
            ->values()
            ->all();
    }
}
