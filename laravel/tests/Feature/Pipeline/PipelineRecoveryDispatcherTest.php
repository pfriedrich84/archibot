<?php

namespace Tests\Feature\Pipeline;

use App\Jobs\RunPythonActorJob;
use App\Models\ActorExecution;
use App\Models\Command;
use App\Models\EmbeddingIndexState;
use App\Models\PipelineEvent;
use App\Models\PipelineRun;
use App\Models\WebhookDelivery;
use App\Services\Actors\PythonActorRunner;
use App\Services\Pipeline\DocumentPipelineStarter;
use App\Services\Pipeline\PipelineRecoveryDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PipelineRecoveryDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_recovery_scan_redispatches_queued_non_process_webhook_deliveries(): void
    {
        Queue::fake();

        $refresh = $this->webhookDelivery([
            'event_type' => 'document_updated',
            'paperless_document_id' => 42,
            'normalized_payload' => ['webhook_action' => 'refresh_embedding'],
        ]);
        $delete = $this->webhookDelivery([
            'event_type' => 'document_deleted',
            'paperless_document_id' => 43,
            'normalized_payload' => ['webhook_action' => 'delete_embedding'],
        ]);
        $process = $this->webhookDelivery([
            'event_type' => 'document_created',
            'paperless_document_id' => 44,
            'normalized_payload' => ['webhook_action' => 'process_document'],
        ]);
        $failed = $this->webhookDelivery([
            'event_type' => 'document_updated',
            'paperless_document_id' => 45,
            'normalized_payload' => ['webhook_action' => 'refresh_embedding'],
            'status' => WebhookDelivery::STATUS_FAILED,
        ]);

        $count = app(PipelineRecoveryDispatcher::class)->recoverQueuedWebhookDeliveries(limit: 10);

        $this->assertSame(2, $count);
        Queue::assertPushed(RunPythonActorJob::class, 2);
        Queue::assertPushed(RunPythonActorJob::class, fn (RunPythonActorJob $job): bool => $job->actorName === PythonActorRunner::ACTOR_HANDLE_PAPERLESS_WEBHOOK
            && $job->commandId === $refresh->id);
        Queue::assertPushed(RunPythonActorJob::class, fn (RunPythonActorJob $job): bool => $job->actorName === PythonActorRunner::ACTOR_HANDLE_PAPERLESS_WEBHOOK
            && $job->commandId === $delete->id);
        Queue::assertNotPushed(RunPythonActorJob::class, fn (RunPythonActorJob $job): bool => $job->commandId === $process->id
            || $job->commandId === $failed->id);

        $this->assertDatabaseHas('pipeline_events', [
            'webhook_delivery_id' => $refresh->id,
            'event_type' => 'recovery.webhook_actor_redispatched',
            'paperless_document_id' => 42,
        ]);
        $this->assertDatabaseHas('pipeline_events', [
            'webhook_delivery_id' => $delete->id,
            'event_type' => 'recovery.webhook_actor_redispatched',
            'paperless_document_id' => 43,
        ]);
        $this->assertDatabaseCount('pipeline_events', 2);
    }

    public function test_recovery_artisan_command_runs_laravel_native_webhook_redispatch(): void
    {
        Queue::fake();

        $delivery = $this->webhookDelivery([
            'event_type' => 'document_updated',
            'paperless_document_id' => 46,
            'normalized_payload' => ['webhook_action' => 'refresh_embedding'],
        ]);

        $this->artisan('archibot:recovery-scan', ['--limit' => 5])
            ->expectsOutput('Recovery scan complete. webhook_deliveries_redispatched=1 document_pipeline_runs_redispatched=0 commands_redispatched=0')
            ->assertSuccessful();

        Queue::assertPushed(RunPythonActorJob::class, fn (RunPythonActorJob $job): bool => $job->commandId === $delivery->id);
        $this->assertSame('recovery.webhook_actor_redispatched', PipelineEvent::query()->firstOrFail()->event_type);
    }

    public function test_recovery_scan_releases_embedding_blocked_webhooks_when_index_is_ready(): void
    {
        Queue::fake();
        $this->markEmbeddingIndexComplete();

        $delivery = $this->webhookDelivery([
            'event_type' => 'document_updated',
            'paperless_document_id' => 47,
            'normalized_payload' => ['webhook_action' => 'refresh_embedding'],
            'status' => WebhookDelivery::STATUS_BLOCKED,
            'error' => DocumentPipelineStarter::BLOCKED_REASON_EMBEDDING_INDEX_NOT_READY,
            'processed_at' => now()->subMinute(),
        ]);

        $count = app(PipelineRecoveryDispatcher::class)->recoverQueuedWebhookDeliveries(limit: 10);

        $this->assertSame(1, $count);
        Queue::assertPushed(RunPythonActorJob::class, fn (RunPythonActorJob $job): bool => $job->commandId === $delivery->id);
        $this->assertSame(WebhookDelivery::STATUS_QUEUED, $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->error);
        $this->assertNull($delivery->fresh()->processed_at);
        $this->assertDatabaseHas('pipeline_events', [
            'webhook_delivery_id' => $delivery->id,
            'event_type' => 'recovery.webhook_embedding_gate_released',
            'paperless_document_id' => 47,
        ]);
        $this->assertDatabaseHas('pipeline_events', [
            'webhook_delivery_id' => $delivery->id,
            'event_type' => 'recovery.webhook_actor_redispatched',
            'paperless_document_id' => 47,
        ]);
    }

    public function test_recovery_scan_keeps_embedding_blocked_webhooks_blocked_until_index_is_ready(): void
    {
        Queue::fake();
        EmbeddingIndexState::query()->create(['status' => EmbeddingIndexState::STATUS_STALE]);

        $delivery = $this->webhookDelivery([
            'event_type' => 'document_updated',
            'paperless_document_id' => 48,
            'normalized_payload' => ['webhook_action' => 'refresh_embedding'],
            'status' => WebhookDelivery::STATUS_BLOCKED,
            'error' => DocumentPipelineStarter::BLOCKED_REASON_EMBEDDING_INDEX_NOT_READY,
        ]);

        $count = app(PipelineRecoveryDispatcher::class)->recoverQueuedWebhookDeliveries(limit: 10);

        $this->assertSame(0, $count);
        Queue::assertNothingPushed();
        $this->assertSame(WebhookDelivery::STATUS_BLOCKED, $delivery->fresh()->status);
        $this->assertDatabaseCount('pipeline_events', 0);
    }

    public function test_recovery_scan_redispatches_pending_and_due_retrying_document_runs(): void
    {
        Queue::fake();
        $this->markEmbeddingIndexComplete();

        $pending = $this->pipelineRun([
            'status' => PipelineRun::STATUS_PENDING,
            'paperless_document_id' => 50,
        ]);
        $retrying = $this->pipelineRun([
            'status' => PipelineRun::STATUS_RETRYING,
            'paperless_document_id' => 51,
            'next_retry_at' => now()->subMinute(),
        ]);
        $futureRetry = $this->pipelineRun([
            'status' => PipelineRun::STATUS_RETRYING,
            'paperless_document_id' => 52,
            'next_retry_at' => now()->addMinute(),
        ]);

        $count = app(PipelineRecoveryDispatcher::class)->recoverDocumentPipelineRuns(limit: 10);

        $this->assertSame(2, $count);
        Queue::assertPushed(RunPythonActorJob::class, 2);
        Queue::assertPushed(RunPythonActorJob::class, fn (RunPythonActorJob $job): bool => $job->actorName === PythonActorRunner::ACTOR_HANDLE_DOCUMENT_PIPELINE
            && $job->commandId === $pending->id);
        Queue::assertPushed(RunPythonActorJob::class, fn (RunPythonActorJob $job): bool => $job->actorName === PythonActorRunner::ACTOR_HANDLE_DOCUMENT_PIPELINE
            && $job->commandId === $retrying->id);
        Queue::assertNotPushed(RunPythonActorJob::class, fn (RunPythonActorJob $job): bool => $job->commandId === $futureRetry->id);

        $this->assertSame(PipelineRun::STATUS_QUEUED, $pending->fresh()->status);
        $this->assertSame(PipelineRun::STATUS_QUEUED, $retrying->fresh()->status);
        $this->assertSame(PipelineRun::STATUS_RETRYING, $futureRetry->fresh()->status);
        $this->assertDatabaseHas('pipeline_events', [
            'pipeline_run_id' => $pending->id,
            'event_type' => 'recovery.document_actor_redispatched',
            'paperless_document_id' => 50,
        ]);
        $this->assertDatabaseHas('pipeline_events', [
            'pipeline_run_id' => $retrying->id,
            'event_type' => 'recovery.document_actor_redispatched',
            'paperless_document_id' => 51,
        ]);
    }

    public function test_recovery_scan_redispatches_stale_queued_document_runs_without_active_actor(): void
    {
        Queue::fake();
        config(['archibot_workers.stale_queued_minutes' => 5]);
        $this->markEmbeddingIndexComplete();

        $staleQueued = $this->pipelineRun([
            'status' => PipelineRun::STATUS_QUEUED,
            'paperless_document_id' => 53,
            'progress_updated_at' => now()->subMinutes(6),
            'updated_at' => now()->subMinutes(6),
        ]);
        $freshQueued = $this->pipelineRun([
            'status' => PipelineRun::STATUS_QUEUED,
            'paperless_document_id' => 54,
            'progress_updated_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ]);
        $activeQueued = $this->pipelineRun([
            'status' => PipelineRun::STATUS_QUEUED,
            'paperless_document_id' => 55,
            'progress_updated_at' => now()->subMinutes(6),
            'updated_at' => now()->subMinutes(6),
        ]);
        ActorExecution::query()->create([
            'pipeline_run_id' => $activeQueued->id,
            'paperless_document_id' => 55,
            'actor_name' => PythonActorRunner::ACTOR_HANDLE_DOCUMENT_PIPELINE,
            'status' => ActorExecution::STATUS_RUNNING,
        ]);

        $count = app(PipelineRecoveryDispatcher::class)->recoverDocumentPipelineRuns(limit: 10);

        $this->assertSame(1, $count);
        Queue::assertPushed(RunPythonActorJob::class, 1);
        Queue::assertPushed(RunPythonActorJob::class, fn (RunPythonActorJob $job): bool => $job->actorName === PythonActorRunner::ACTOR_HANDLE_DOCUMENT_PIPELINE
            && $job->commandId === $staleQueued->id);
        Queue::assertNotPushed(RunPythonActorJob::class, fn (RunPythonActorJob $job): bool => $job->commandId === $freshQueued->id
            || $job->commandId === $activeQueued->id);

        $this->assertSame(PipelineRun::STATUS_QUEUED, $staleQueued->fresh()->status);
        $this->assertSame('Document actor redispatched from stale queued state by Laravel recovery.', $staleQueued->fresh()->progress_message);
        $this->assertDatabaseHas('pipeline_events', [
            'pipeline_run_id' => $staleQueued->id,
            'event_type' => 'recovery.stale_queued_document_actor_redispatched',
            'paperless_document_id' => 53,
        ]);
        $this->assertDatabaseMissing('pipeline_events', [
            'pipeline_run_id' => $freshQueued->id,
            'event_type' => 'recovery.stale_queued_document_actor_redispatched',
        ]);
        $this->assertDatabaseMissing('pipeline_events', [
            'pipeline_run_id' => $activeQueued->id,
            'event_type' => 'recovery.stale_queued_document_actor_redispatched',
        ]);
    }

    public function test_recovery_scan_releases_embedding_blocked_document_runs_when_index_is_ready(): void
    {
        Queue::fake();
        $this->markEmbeddingIndexComplete();

        $blocked = $this->pipelineRun([
            'status' => PipelineRun::STATUS_BLOCKED,
            'paperless_document_id' => 60,
            'progress_current_phase' => 'blocked',
            'error_type' => DocumentPipelineStarter::BLOCKED_REASON_EMBEDDING_INDEX_NOT_READY,
            'error' => 'Waiting for embedding index to complete.',
        ]);

        $count = app(PipelineRecoveryDispatcher::class)->recoverDocumentPipelineRuns(limit: 10);

        $this->assertSame(1, $count);
        Queue::assertPushed(RunPythonActorJob::class, fn (RunPythonActorJob $job): bool => $job->commandId === $blocked->id);
        $this->assertSame(PipelineRun::STATUS_QUEUED, $blocked->fresh()->status);
        $this->assertDatabaseHas('pipeline_events', [
            'pipeline_run_id' => $blocked->id,
            'event_type' => 'recovery.embedding_gate_released',
            'paperless_document_id' => 60,
        ]);
        $this->assertDatabaseHas('pipeline_events', [
            'pipeline_run_id' => $blocked->id,
            'event_type' => 'recovery.document_actor_redispatched',
            'paperless_document_id' => 60,
        ]);
    }

    public function test_recovery_scan_keeps_embedding_blocked_document_runs_blocked_until_index_is_ready(): void
    {
        Queue::fake();
        EmbeddingIndexState::query()->create(['status' => EmbeddingIndexState::STATUS_STALE]);

        $blocked = $this->pipelineRun([
            'status' => PipelineRun::STATUS_BLOCKED,
            'paperless_document_id' => 61,
            'error_type' => DocumentPipelineStarter::BLOCKED_REASON_EMBEDDING_INDEX_NOT_READY,
        ]);

        $count = app(PipelineRecoveryDispatcher::class)->recoverDocumentPipelineRuns(limit: 10);

        $this->assertSame(0, $count);
        Queue::assertNothingPushed();
        $this->assertSame(PipelineRun::STATUS_BLOCKED, $blocked->fresh()->status);
        $this->assertDatabaseCount('pipeline_events', 0);
    }

    public function test_recovery_scan_redispatches_pending_commands(): void
    {
        Queue::fake();

        $embedding = $this->command(['type' => Command::TYPE_EMBEDDING_INDEX_BUILD]);
        $poll = $this->command(['type' => Command::TYPE_POLL_RECONCILIATION]);
        $reindex = $this->command(['type' => Command::TYPE_REINDEX]);
        $review = $this->command([
            'type' => Command::TYPE_REVIEW_COMMIT,
            'payload' => ['review_suggestion_id' => 44, 'paperless_document_id' => 70],
        ]);
        $running = $this->command([
            'type' => Command::TYPE_REINDEX,
            'status' => Command::STATUS_RUNNING,
        ]);

        $count = app(PipelineRecoveryDispatcher::class)->recoverPendingCommands(limit: 10);

        $this->assertSame(4, $count);
        Queue::assertPushed(RunPythonActorJob::class, 4);
        Queue::assertPushed(RunPythonActorJob::class, fn (RunPythonActorJob $job): bool => $job->commandId === $embedding->id
            && $job->actorName === PythonActorRunner::ACTOR_BUILD_EMBEDDING_INDEX);
        Queue::assertPushed(RunPythonActorJob::class, fn (RunPythonActorJob $job): bool => $job->commandId === $poll->id
            && $job->actorName === PythonActorRunner::ACTOR_POLL_RECONCILIATION);
        Queue::assertPushed(RunPythonActorJob::class, fn (RunPythonActorJob $job): bool => $job->commandId === $reindex->id
            && $job->actorName === PythonActorRunner::ACTOR_REINDEX);
        Queue::assertPushed(RunPythonActorJob::class, fn (RunPythonActorJob $job): bool => $job->commandId === $review->id
            && $job->actorName === PythonActorRunner::ACTOR_COMMIT_REVIEW_SUGGESTION);
        Queue::assertNotPushed(RunPythonActorJob::class, fn (RunPythonActorJob $job): bool => $job->commandId === $running->id);

        $this->assertSame(Command::STATUS_QUEUED, $embedding->fresh()->status);
        $this->assertSame(Command::STATUS_QUEUED, $poll->fresh()->status);
        $this->assertSame(Command::STATUS_QUEUED, $reindex->fresh()->status);
        $this->assertSame(Command::STATUS_QUEUED, $review->fresh()->status);
        $this->assertSame(Command::STATUS_RUNNING, $running->fresh()->status);
        $this->assertDatabaseCount('pipeline_events', 4);
    }

    public function test_recovery_scan_redispatches_stale_queued_commands_without_active_actor(): void
    {
        Queue::fake();
        config(['archibot_workers.stale_queued_minutes' => 5]);

        $stale = $this->command([
            'type' => Command::TYPE_REINDEX,
            'status' => Command::STATUS_QUEUED,
            'updated_at' => now()->subMinutes(6),
        ]);
        $fresh = $this->command([
            'type' => Command::TYPE_REINDEX_OCR,
            'status' => Command::STATUS_QUEUED,
            'updated_at' => now()->subMinutes(2),
        ]);
        $active = $this->command([
            'type' => Command::TYPE_POLL_RECONCILIATION,
            'status' => Command::STATUS_QUEUED,
            'updated_at' => now()->subMinutes(6),
        ]);
        ActorExecution::query()->create([
            'actor_name' => PythonActorRunner::ACTOR_POLL_RECONCILIATION,
            'status' => ActorExecution::STATUS_RUNNING,
        ]);

        $count = app(PipelineRecoveryDispatcher::class)->recoverPendingCommands(limit: 10);

        $this->assertSame(1, $count);
        Queue::assertPushed(RunPythonActorJob::class, 1);
        Queue::assertPushed(RunPythonActorJob::class, fn (RunPythonActorJob $job): bool => $job->actorName === PythonActorRunner::ACTOR_REINDEX
            && $job->commandId === $stale->id);
        Queue::assertNotPushed(RunPythonActorJob::class, fn (RunPythonActorJob $job): bool => $job->commandId === $fresh->id
            || $job->commandId === $active->id);
        $this->assertDatabaseHas('pipeline_events', [
            'command_id' => $stale->id,
            'event_type' => 'recovery.stale_queued_command_actor_redispatched',
        ]);
        $this->assertDatabaseMissing('pipeline_events', [
            'command_id' => $fresh->id,
            'event_type' => 'recovery.stale_queued_command_actor_redispatched',
        ]);
        $this->assertDatabaseMissing('pipeline_events', [
            'command_id' => $active->id,
            'event_type' => 'recovery.stale_queued_command_actor_redispatched',
        ]);
    }

    public function test_recovery_scan_marks_invalid_pending_review_commit_command_permanently_failed(): void
    {
        Queue::fake();

        $command = $this->command([
            'type' => Command::TYPE_REVIEW_COMMIT,
            'payload' => ['paperless_document_id' => 71],
        ]);

        $count = app(PipelineRecoveryDispatcher::class)->recoverPendingCommands(limit: 10);

        $this->assertSame(0, $count);
        Queue::assertNothingPushed();
        $this->assertSame(Command::STATUS_FAILED_PERMANENT, $command->fresh()->status);
        $this->assertSame('missing_review_suggestion_id', $command->fresh()->error);
        $this->assertDatabaseHas('pipeline_events', [
            'command_id' => $command->id,
            'event_type' => 'recovery.command_failed_permanent',
            'paperless_document_id' => 71,
        ]);
    }

    private function command(array $overrides = []): Command
    {
        return Command::query()->create(array_merge([
            'type' => Command::TYPE_REINDEX,
            'status' => Command::STATUS_PENDING,
            'payload' => ['limit' => 10],
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function pipelineRun(array $overrides = []): PipelineRun
    {
        $documentId = (int) ($overrides['paperless_document_id'] ?? 50);

        return PipelineRun::query()->create(array_merge([
            'type' => 'document',
            'status' => PipelineRun::STATUS_PENDING,
            'scope' => 'single_document',
            'trigger_source' => 'recovery-test',
            'paperless_document_id' => $documentId,
            'pipeline_dedupe_key' => hash('sha256', 'recovery-test-'.$documentId.'-'.uniqid('', true)),
            'coalesced_sources' => ['recovery-test'],
            'progress_current_phase' => 'queued',
            'progress_message' => 'Waiting for recovery test.',
            'progress_updated_at' => now(),
        ], $overrides));
    }

    private function markEmbeddingIndexComplete(): void
    {
        EmbeddingIndexState::query()->create([
            'status' => EmbeddingIndexState::STATUS_COMPLETE,
            'embedding_model' => 'test-embed',
            'dimensions' => 1024,
            'content_scope' => 'trusted_documents',
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }

    private function webhookDelivery(array $overrides = []): WebhookDelivery
    {
        $documentId = (int) ($overrides['paperless_document_id'] ?? 42);
        $eventType = (string) ($overrides['event_type'] ?? 'document_updated');
        $payload = $overrides['raw_payload'] ?? ['document_id' => $documentId];

        return WebhookDelivery::query()->create(array_merge([
            'source' => 'paperless',
            'event_type' => $eventType,
            'paperless_document_id' => $documentId,
            'dedupe_key' => "paperless:{$eventType}:{$documentId}:".uniqid('', true),
            'payload_hash' => hash('sha256', json_encode($payload) ?: ''),
            'raw_payload' => $payload,
            'normalized_payload' => ['webhook_action' => 'refresh_embedding'],
            'headers' => [],
            'status' => WebhookDelivery::STATUS_QUEUED,
            'request_id' => uniqid('request-', true),
            'received_at' => now(),
        ], $overrides));
    }
}
