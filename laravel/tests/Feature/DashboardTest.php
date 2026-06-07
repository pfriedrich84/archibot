<?php

namespace Tests\Feature;

use App\Models\ActorExecution;
use App\Models\AppSetting;
use App\Models\Command;
use App\Models\DocumentEmbedding;
use App\Models\EmbeddingIndexState;
use App\Models\PipelineItem;
use App\Models\PipelineRun;
use App\Models\ReviewSuggestion;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WorkerJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_summarizes_laravel_app_status(): void
    {
        AppSetting::put('paperless.url', 'https://paperless.example');
        AppSetting::put('paperless.inbox_tag_id', '7');
        AppSetting::put('worker_jobs.recovery.last_successful_at', now()->toISOString());
        AppSetting::put('worker_jobs.recovery.last_error', 'previous recovery failure');
        AppSetting::put('worker_jobs.recovery.last_error_at', now()->subMinute()->toISOString());
        Http::fake(['paperless.example/api/ui_settings/' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create(['paperless_token' => 'user-token']);
        ReviewSuggestion::factory()->count(2)->create();
        ReviewSuggestion::factory()->create(['status' => ReviewSuggestion::STATUS_REJECTED]);
        $stalePendingSuggestion = ReviewSuggestion::factory()->create([
            'paperless_document_id' => 999,
            'status' => ReviewSuggestion::STATUS_PENDING,
        ]);
        ReviewSuggestion::factory()->create([
            'paperless_document_id' => $stalePendingSuggestion->paperless_document_id,
            'status' => ReviewSuggestion::STATUS_ACCEPTED,
        ]);
        WorkerJob::factory()->create(['status' => WorkerJob::STATUS_RUNNING]);
        WorkerJob::factory()->create([
            'status' => WorkerJob::STATUS_QUEUED,
            'dispatched_at' => now()->subMinutes(20),
        ]);
        WorkerJob::factory()->create([
            'type' => WorkerJob::TYPE_REINDEX,
            'status' => WorkerJob::STATUS_CANCELLING,
        ]);
        WorkerJob::factory()->create([
            'status' => WorkerJob::STATUS_SUCCEEDED,
            'finished_at' => now()->subMinutes(2),
        ]);
        WorkerJob::factory()->create([
            'status' => WorkerJob::STATUS_FAILED,
            'finished_at' => now()->subMinute(),
            'error' => 'classification failed',
        ]);
        WebhookDelivery::query()->create([
            'source' => 'paperless',
            'event_type' => 'document.created',
            'paperless_document_id' => 123,
            'dedupe_key' => 'dedupe',
            'payload_hash' => str_repeat('a', 64),
            'raw_payload' => ['document_id' => 123],
            'status' => WebhookDelivery::STATUS_QUEUED,
            'received_at' => now(),
        ]);
        WebhookDelivery::query()->create([
            'source' => 'paperless',
            'event_type' => 'document.updated',
            'paperless_document_id' => 456,
            'dedupe_key' => 'dedupe-failed',
            'payload_hash' => str_repeat('b', 64),
            'raw_payload' => ['document_id' => 456],
            'status' => WebhookDelivery::STATUS_FAILED,
            'received_at' => now()->addMinute(),
            'processed_at' => now()->addMinutes(2),
            'error' => 'Absurd unavailable',
        ]);
        $pipelineRun = PipelineRun::query()->create([
            'type' => 'document',
            'status' => PipelineRun::STATUS_PENDING,
            'trigger_source' => 'webhook',
            'paperless_document_id' => 123,
            'progress_total' => 3,
            'progress_done' => 1,
            'progress_current_phase' => 'classification',
            'progress_message' => 'Classifying document.',
        ]);
        PipelineItem::query()->create([
            'pipeline_run_id' => $pipelineRun->id,
            'paperless_document_id' => 123,
            'item_type' => 'classification',
            'status' => 'failed',
        ]);
        PipelineRun::query()->create([
            'type' => 'document',
            'status' => PipelineRun::STATUS_BLOCKED,
            'trigger_source' => 'manual',
            'paperless_document_id' => 456,
            'reprocess_requested' => true,
        ]);
        ActorExecution::query()->create([
            'actor_name' => 'handle_document_pipeline',
            'queue_name' => 'archibot.io',
            'status' => 'running',
            'progress_total' => 3,
            'progress_done' => 1,
            'progress_current_item' => 'paperless_document:123',
        ]);
        ActorExecution::query()->create([
            'actor_name' => 'commit_review_suggestion',
            'queue_name' => 'archibot.io',
            'status' => 'failed',
            'error_type' => 'PaperlessError',
        ]);
        EmbeddingIndexState::query()->create([
            'status' => 'building',
            'embedding_model' => 'nomic-embed-text',
            'document_count' => 10,
            'embedded_count' => 4,
            'failed_count' => 1,
        ]);
        foreach (range(1, 4) as $documentId) {
            DocumentEmbedding::query()->create([
                'paperless_document_id' => $documentId,
                'content_hash' => "hash-{$documentId}",
                'embedding_model' => 'nomic-embed-text',
                'dimensions' => 1024,
                'embedding' => [0.1, 0.2],
            ]);
        }
        Command::query()->create([
            'type' => 'embedding_index_build',
            'status' => 'pending',
            'payload' => [],
        ]);
        Command::query()->create([
            'type' => 'poll_reconciliation',
            'status' => 'pending',
            'payload' => [],
        ]);
        Command::query()->create([
            'type' => 'reindex',
            'status' => 'pending',
            'payload' => [],
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('status.setup_complete', true)
                ->where('status.paperless_url_configured', true)
                ->where('status.paperless_available', true)
                ->where('status.inbox_tag_id', 7)
                ->where('status.user_paperless_token_present', true)
                ->where('status.ollama_or_provider_configured', true)
                ->where('status.ocr_mode', 'off')
                ->where('counts.pending_reviews', 2)
                ->missing('counts.queued_or_running_workers')
                ->missing('counts.queued_worker_jobs')
                ->missing('counts.running_worker_jobs')
                ->missing('counts.cancelling_worker_jobs')
                ->missing('counts.failed_workers')
                ->missing('counts.failed_worker_jobs')
                ->missing('counts.stale_queued_worker_jobs')
                ->missing('counts.stale_running_worker_jobs')
                ->where('counts.queued_webhook_deliveries', 1)
                ->where('counts.active_pipeline_runs', 1)
                ->where('counts.pending_pipeline_runs', 1)
                ->where('counts.queued_pipeline_runs', 0)
                ->where('counts.running_pipeline_runs', 0)
                ->where('counts.retrying_pipeline_runs', 0)
                ->where('counts.blocked_pipeline_runs', 1)
                ->where('counts.failed_pipeline_runs', 0)
                ->where('counts.running_actor_executions', 1)
                ->where('counts.failed_actor_executions', 1)
                ->where('embeddingIndex.status', 'building')
                ->where('embeddingIndex.embedding_model', 'nomic-embed-text')
                ->where('embeddingIndex.document_count', 10)
                ->where('embeddingIndex.embedded_count', 4)
                ->where('embeddingIndex.failed_count', 1)
                ->where('embeddingIndex.ready', false)
                ->where('embeddingIndex.pending_build_commands', 1)
                ->where('maintenance.pending_poll_commands', 1)
                ->where('maintenance.pending_reindex_commands', 1)
                ->where('maintenance.poll_interval_seconds', 600)
                ->where('maintenance.document_processing_active', true)
                ->where('maintenance.reindex_active', true)
                ->where('maintenance.last_worker_recovery_error', 'previous recovery failure')
                ->where('maintenance.worker_queue_warning', '1 queued worker job(s) are stale. Check that Laravel queue workers are consuming jobs.')
                ->has('recentWebhookDeliveries', 2)
                ->where('recentWebhookDeliveries.0.status', WebhookDelivery::STATUS_FAILED)
                ->where('recentWebhookDeliveries.0.event_type', 'document.updated')
                ->where('recentWebhookDeliveries.0.paperless_document_id', 456)
                ->where('recentWebhookDeliveries.0.error', 'Absurd unavailable')
                ->where('recentWebhookDeliveries.0.can_retry', true)
                ->where('recentWebhookDeliveries.0.can_dismiss', true)
                ->has('recentActorExecutions', 2)
                ->has('recentPipelineRuns', 2)
                ->where('lastSuccessfulWorkerJob.status', WorkerJob::STATUS_SUCCEEDED)
                ->where('lastFailedWorkerJob.error', 'classification failed')
                ->has('recentErrors', 2)
                ->where('recentPipelineRuns.0.progress_total', 3)
                ->where('recentPipelineRuns.0.progress_done', 1)
                ->where('recentPipelineRuns.0.failed_items_count', 1)
                ->where('recentPipelineRuns.0.can_retry_failed_items', false)
                ->has('recentWorkerJobs', 5)
            );
    }

    public function test_dashboard_handles_paperless_unavailable(): void
    {
        AppSetting::put('paperless.url', 'https://paperless.example');
        Http::fake(['paperless.example/api/ui_settings/' => Http::response([], 500)]);

        $user = User::factory()->create(['paperless_token' => 'user-token']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('status.paperless_available', false)
            );
    }
}
