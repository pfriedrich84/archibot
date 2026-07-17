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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PipelineRecoveryDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_recovery_scan_skips_when_another_scan_holds_the_lock(): void
    {
        Queue::fake();
        $lock = Cache::lock('archibot:pipeline-recovery-scan', 60);
        $this->assertTrue($lock->get());

        try {
            $this->assertSame(
                ['scan_skipped_locked' => 1],
                app(PipelineRecoveryDispatcher::class)->runRecoveryScan(limit: 10),
            );
            Queue::assertNothingPushed();
        } finally {
            $lock->release();
        }
    }

    public function test_redispatch_claim_rejects_sources_changed_after_recovery_selection(): void
    {
        Queue::fake();
        $dispatcher = app(PipelineRecoveryDispatcher::class);

        $command = $this->command([
            'type' => Command::TYPE_REINDEX,
            'status' => Command::STATUS_PENDING,
        ]);
        $selectedCommand = $command->replicate()->setRawAttributes($command->getAttributes(), true);
        $selectedCommand->setAttribute($command->getKeyName(), $command->getKey());
        $command->forceFill(['status' => Command::STATUS_QUEUED])->save();
        $commandMethod = new \ReflectionMethod($dispatcher, 'redispatchCommand');
        $this->assertFalse($commandMethod->invoke(
            $dispatcher,
            $selectedCommand,
            'test.command',
            'test command',
        ));

        $delivery = $this->webhookDelivery(['status' => WebhookDelivery::STATUS_RECEIVED]);
        $selectedDelivery = $delivery->replicate()->setRawAttributes($delivery->getAttributes(), true);
        $selectedDelivery->setAttribute($delivery->getKeyName(), $delivery->getKey());
        $delivery->forceFill(['status' => WebhookDelivery::STATUS_QUEUED])->save();
        $webhookMethod = new \ReflectionMethod($dispatcher, 'redispatchWebhookDelivery');
        $this->assertFalse($webhookMethod->invoke(
            $dispatcher,
            $selectedDelivery,
            'test.webhook',
            'test webhook',
        ));

        $run = $this->pipelineRun(['status' => PipelineRun::STATUS_PENDING]);
        $selectedRun = $run->replicate()->setRawAttributes($run->getAttributes(), true);
        $selectedRun->setAttribute($run->getKeyName(), $run->getKey());
        $run->forceFill(['status' => PipelineRun::STATUS_QUEUED])->save();
        $runMethod = new \ReflectionMethod($dispatcher, 'redispatchDocumentRun');
        $this->assertFalse($runMethod->invoke(
            $dispatcher,
            $selectedRun,
            'test.run',
            'test run',
            'test progress',
        ));

        Queue::assertNothingPushed();
    }

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

    public function test_recovery_starts_stranded_process_webhook_pipeline(): void
    {
        Queue::fake();
        EmbeddingIndexState::query()->create(['status' => EmbeddingIndexState::STATUS_COMPLETE]);
        $delivery = $this->webhookDelivery([
            'status' => WebhookDelivery::STATUS_RECEIVED,
            'event_type' => 'document_created',
            'normalized_payload' => [
                'webhook_action' => 'process_document',
                'paperless_modified' => '2026-05-08T12:00:00Z',
            ],
        ]);
        $delivery->timestamps = false;
        $delivery->forceFill(['updated_at' => now()->subMinutes(6)])->save();

        $result = app(PipelineRecoveryDispatcher::class)->runRecoveryScan(limit: 10);

        $run = PipelineRun::query()->firstOrFail();
        $this->assertSame(1, $result['webhook_deliveries_redispatched']);
        $this->assertSame(WebhookDelivery::STATUS_PROCESSED, $delivery->fresh()->status);
        $this->assertSame(PipelineRun::STATUS_QUEUED, $run->status);
        $this->assertSame($delivery->id, $run->webhook_delivery_id);
        Queue::assertPushed(RunPythonActorJob::class, fn (RunPythonActorJob $job): bool => $job->actorName === PythonActorRunner::ACTOR_HANDLE_DOCUMENT_PIPELINE
            && $job->commandId === $run->id);
    }

    public function test_recovery_reconciles_blocked_process_webhook_after_gate_release(): void
    {
        Queue::fake();
        EmbeddingIndexState::query()->create(['status' => EmbeddingIndexState::STATUS_COMPLETE]);
        $delivery = $this->webhookDelivery([
            'status' => WebhookDelivery::STATUS_BLOCKED,
            'event_type' => 'document_created',
            'error' => DocumentPipelineStarter::BLOCKED_REASON_EMBEDDING_INDEX_NOT_READY,
            'normalized_payload' => ['webhook_action' => 'process_document'],
        ]);
        $delivery->timestamps = false;
        $delivery->forceFill(['updated_at' => now()->subMinutes(6)])->save();
        $run = $this->pipelineRun([
            'webhook_delivery_id' => $delivery->id,
            'status' => PipelineRun::STATUS_BLOCKED,
            'error_type' => DocumentPipelineStarter::BLOCKED_REASON_EMBEDDING_INDEX_NOT_READY,
            'error' => 'Embedding index is not ready.',
        ]);

        app(PipelineRecoveryDispatcher::class)->runRecoveryScan(limit: 10);

        $this->assertSame(PipelineRun::STATUS_QUEUED, $run->fresh()->status);
        $this->assertSame(WebhookDelivery::STATUS_PROCESSED, $delivery->fresh()->status);
        $this->assertDatabaseHas('pipeline_events', [
            'pipeline_run_id' => $run->id,
            'webhook_delivery_id' => $delivery->id,
            'event_type' => 'recovery.process_webhook_reconciled',
        ]);
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
            ->expectsOutput('Recovery scan complete. actor_executions_stale=0 actor_executions_redispatched=0 actor_executions_failed_permanent=0 pipeline_runs_cancelled=0 webhook_deliveries_redispatched=1 document_pipeline_runs_redispatched=0 commands_redispatched=0')
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
            'command_id' => $active->id,
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

    public function test_recovery_scan_redispatches_sync_entity_approval_commands(): void
    {
        Queue::fake();
        $command = $this->command([
            'type' => Command::TYPE_SYNC_ENTITY_APPROVAL,
            'payload' => [
                'action' => 'approve',
                'type' => 'tag',
                'name' => 'Invoices',
                'paperless_id' => 12,
            ],
        ]);

        $count = app(PipelineRecoveryDispatcher::class)->recoverPendingCommands(limit: 10);

        $this->assertSame(1, $count);
        Queue::assertPushed(RunPythonActorJob::class, fn (RunPythonActorJob $job): bool => $job->actorName === PythonActorRunner::ACTOR_SYNC_ENTITY_APPROVAL
            && $job->commandId === $command->id);
        $this->assertSame(Command::STATUS_QUEUED, $command->fresh()->status);
    }

    public function test_recovery_scan_marks_invalid_sync_entity_command_permanently_failed(): void
    {
        Queue::fake();
        $command = $this->command([
            'type' => Command::TYPE_SYNC_ENTITY_APPROVAL,
            'payload' => ['action' => 'approve', 'type' => 'tag'],
        ]);

        $count = app(PipelineRecoveryDispatcher::class)->recoverPendingCommands(limit: 10);

        $this->assertSame(0, $count);
        Queue::assertNothingPushed();
        $this->assertSame(Command::STATUS_FAILED_PERMANENT, $command->fresh()->status);
        $this->assertSame('missing_entity_sync_name', $command->fresh()->error);
    }

    public function test_recovery_redispatches_stale_running_actor_through_linked_command(): void
    {
        Queue::fake();
        config(['archibot_workers.stale_running_minutes' => 10]);
        $command = $this->command([
            'type' => Command::TYPE_REINDEX,
            'status' => Command::STATUS_RUNNING,
            'updated_at' => now()->subMinutes(11),
        ]);
        $execution = ActorExecution::query()->create([
            'command_id' => $command->id,
            'actor_name' => PythonActorRunner::ACTOR_REINDEX,
            'status' => ActorExecution::STATUS_RUNNING,
            'attempt' => 1,
            'max_attempts' => 5,
            'started_at' => now()->subMinutes(11),
            'progress_updated_at' => now()->subMinutes(11),
        ]);

        $result = app(PipelineRecoveryDispatcher::class)->recoverActorExecutions(limit: 10);

        $this->assertSame(['stale' => 1, 'redispatched' => 1, 'failed_permanent' => 0], $result);
        Queue::assertPushed(RunPythonActorJob::class, fn (RunPythonActorJob $job): bool => $job->actorName === PythonActorRunner::ACTOR_REINDEX
            && $job->commandId === $command->id);
        $this->assertSame(ActorExecution::STATUS_FAILED, $execution->fresh()->status);
        $this->assertSame(Command::STATUS_QUEUED, $command->fresh()->status);
        $this->assertDatabaseHas('pipeline_events', [
            'command_id' => $command->id,
            'event_type' => 'recovery.actor_execution_redispatched',
        ]);
    }

    public function test_recovery_marks_exhausted_stale_actor_and_source_permanently_failed(): void
    {
        Queue::fake();
        $command = $this->command([
            'type' => Command::TYPE_REINDEX,
            'status' => Command::STATUS_RUNNING,
        ]);
        $execution = ActorExecution::query()->create([
            'command_id' => $command->id,
            'actor_name' => PythonActorRunner::ACTOR_REINDEX,
            'status' => ActorExecution::STATUS_RUNNING,
            'attempt' => 5,
            'max_attempts' => 5,
            'started_at' => now()->subMinutes(11),
            'progress_updated_at' => now()->subMinutes(11),
        ]);

        $result = app(PipelineRecoveryDispatcher::class)->recoverActorExecutions(limit: 10);

        $this->assertSame(['stale' => 0, 'redispatched' => 0, 'failed_permanent' => 1], $result);
        Queue::assertNothingPushed();
        $this->assertSame(ActorExecution::STATUS_FAILED_PERMANENT, $execution->fresh()->status);
        $this->assertSame(Command::STATUS_FAILED_PERMANENT, $command->fresh()->status);
    }

    public function test_recovery_finalizes_cancel_requested_run_without_live_actor(): void
    {
        $run = $this->pipelineRun([
            'status' => PipelineRun::STATUS_CANCEL_REQUESTED,
            'paperless_document_id' => 80,
            'progress_updated_at' => now()->subMinutes(11),
        ]);
        ActorExecution::query()->create([
            'pipeline_run_id' => $run->id,
            'paperless_document_id' => 80,
            'actor_name' => PythonActorRunner::ACTOR_HANDLE_DOCUMENT_PIPELINE,
            'status' => ActorExecution::STATUS_RETRYING,
            'next_retry_at' => now()->addMinute(),
        ]);

        $count = app(PipelineRecoveryDispatcher::class)->finalizeCancelRequestedRuns(limit: 10);

        $this->assertSame(1, $count);
        $this->assertSame(PipelineRun::STATUS_CANCELLED, $run->fresh()->status);
        $this->assertDatabaseHas('actor_executions', [
            'pipeline_run_id' => $run->id,
            'status' => ActorExecution::STATUS_CANCELLED,
        ]);
        $this->assertDatabaseHas('pipeline_events', [
            'pipeline_run_id' => $run->id,
            'event_type' => 'pipeline.cancelled',
        ]);
    }

    public function test_webhook_recovery_waits_for_recent_enqueue_attempt_to_become_stale(): void
    {
        Queue::fake();
        $delivery = $this->webhookDelivery();
        PipelineEvent::query()->create([
            'webhook_delivery_id' => $delivery->id,
            'event_type' => 'webhook.enqueue_requested',
            'level' => 'info',
            'created_at' => now(),
        ]);

        $recovery = app(PipelineRecoveryDispatcher::class);
        $this->assertSame(0, $recovery->recoverQueuedWebhookDeliveries(limit: 10));
        Queue::assertNothingPushed();

        $this->travel(6)->minutes();
        $this->assertSame(1, $recovery->recoverQueuedWebhookDeliveries(limit: 10));
        Queue::assertPushed(RunPythonActorJob::class, 1);
    }

    public function test_recovery_does_not_redispatch_stale_timestamp_while_actor_process_is_alive(): void
    {
        Queue::fake();
        $command = $this->command([
            'type' => Command::TYPE_REINDEX,
            'status' => Command::STATUS_RUNNING,
        ]);
        $execution = ActorExecution::query()->create([
            'command_id' => $command->id,
            'actor_name' => PythonActorRunner::ACTOR_REINDEX,
            'status' => ActorExecution::STATUS_RUNNING,
            'attempt' => 1,
            'max_attempts' => 5,
            'worker_id' => $this->liveWorkerId(),
            'started_at' => now()->subMinutes(11),
            'progress_updated_at' => now()->subMinutes(11),
        ]);

        $result = app(PipelineRecoveryDispatcher::class)->recoverActorExecutions(limit: 10);

        $this->assertSame(['stale' => 0, 'redispatched' => 0, 'failed_permanent' => 0], $result);
        Queue::assertNothingPushed();
        $this->assertSame(ActorExecution::STATUS_RUNNING, $execution->fresh()->status);
        $this->assertSame(Command::STATUS_RUNNING, $command->fresh()->status);
    }

    public function test_recovery_reconciles_stale_actor_to_terminal_source_without_replay(): void
    {
        Queue::fake();
        $command = $this->command([
            'type' => Command::TYPE_REINDEX,
            'status' => Command::STATUS_SUCCEEDED,
        ]);
        $execution = ActorExecution::query()->create([
            'command_id' => $command->id,
            'actor_name' => PythonActorRunner::ACTOR_REINDEX,
            'status' => ActorExecution::STATUS_RUNNING,
            'attempt' => 1,
            'max_attempts' => 5,
            'started_at' => now()->subMinutes(11),
            'progress_updated_at' => now()->subMinutes(11),
        ]);

        $result = app(PipelineRecoveryDispatcher::class)->recoverActorExecutions(limit: 10);

        $this->assertSame(['stale' => 0, 'redispatched' => 0, 'failed_permanent' => 0], $result);
        Queue::assertNothingPushed();
        $this->assertSame(ActorExecution::STATUS_SUCCEEDED, $execution->fresh()->status);
        $this->assertSame(Command::STATUS_SUCCEEDED, $command->fresh()->status);
    }

    public function test_retry_recovery_skips_old_execution_when_newer_attempt_is_active(): void
    {
        Queue::fake();
        $command = $this->command([
            'type' => Command::TYPE_REINDEX,
            'status' => Command::STATUS_RUNNING,
        ]);
        $old = ActorExecution::query()->create([
            'command_id' => $command->id,
            'actor_name' => PythonActorRunner::ACTOR_REINDEX,
            'status' => ActorExecution::STATUS_RETRYING,
            'attempt' => 5,
            'max_attempts' => 5,
            'next_retry_at' => now()->subMinute(),
            'last_retry_at' => now()->subMinutes(2),
        ]);
        ActorExecution::query()->create([
            'command_id' => $command->id,
            'actor_name' => PythonActorRunner::ACTOR_REINDEX,
            'status' => ActorExecution::STATUS_RUNNING,
            'attempt' => 6,
            'max_attempts' => 5,
            'started_at' => now(),
            'progress_updated_at' => now(),
        ]);

        $result = app(PipelineRecoveryDispatcher::class)->recoverActorExecutions(limit: 10);

        $this->assertSame(['stale' => 0, 'redispatched' => 0, 'failed_permanent' => 0], $result);
        $this->assertSame(ActorExecution::STATUS_SKIPPED, $old->fresh()->status);
        $this->assertSame(Command::STATUS_RUNNING, $command->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_retry_recovery_retires_old_execution_after_atomic_source_dispatch(): void
    {
        Queue::fake();
        $command = $this->command([
            'type' => Command::TYPE_REINDEX,
            'status' => Command::STATUS_QUEUED,
        ]);
        $old = ActorExecution::query()->create([
            'command_id' => $command->id,
            'actor_name' => PythonActorRunner::ACTOR_REINDEX,
            'status' => ActorExecution::STATUS_RETRYING,
            'attempt' => 1,
            'max_attempts' => 5,
            'next_retry_at' => now()->subMinute(),
            'last_retry_at' => now()->subMinutes(2),
        ]);
        PipelineEvent::query()->create([
            'command_id' => $command->id,
            'event_type' => 'recovery.actor_source_command_redispatched',
            'level' => 'info',
            'message' => 'Prior atomic dispatch committed before recovery process exit.',
            'created_at' => now()->subMinute(),
        ]);

        $result = app(PipelineRecoveryDispatcher::class)->recoverActorExecutions(limit: 10);

        $this->assertSame(['stale' => 0, 'redispatched' => 0, 'failed_permanent' => 0], $result);
        $this->assertSame(ActorExecution::STATUS_SKIPPED, $old->fresh()->status);
        $this->assertSame(Command::STATUS_QUEUED, $command->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_recovery_redispatches_retryable_failed_webhook_without_actor_execution(): void
    {
        Queue::fake();
        $delivery = $this->webhookDelivery([
            'status' => WebhookDelivery::STATUS_FAILED,
            'error' => 'transient_network',
        ]);

        $count = app(PipelineRecoveryDispatcher::class)->recoverQueuedWebhookDeliveries(limit: 10);

        $this->assertSame(1, $count);
        $this->assertSame(WebhookDelivery::STATUS_QUEUED, $delivery->fresh()->status);
        Queue::assertPushed(RunPythonActorJob::class, fn (RunPythonActorJob $job): bool => $job->actorName === PythonActorRunner::ACTOR_HANDLE_PAPERLESS_WEBHOOK
            && $job->commandId === $delivery->id);
    }

    public function test_recovery_redispatches_retryable_failed_webhook_from_actor_source_link(): void
    {
        Queue::fake();
        $delivery = $this->webhookDelivery(['status' => WebhookDelivery::STATUS_FAILED]);
        $execution = ActorExecution::query()->create([
            'webhook_delivery_id' => $delivery->id,
            'paperless_document_id' => $delivery->paperless_document_id,
            'actor_name' => PythonActorRunner::ACTOR_HANDLE_PAPERLESS_WEBHOOK,
            'status' => ActorExecution::STATUS_RETRYING,
            'attempt' => 1,
            'max_attempts' => 5,
            'next_retry_at' => now()->subMinute(),
        ]);

        $result = app(PipelineRecoveryDispatcher::class)->recoverActorExecutions(limit: 10);

        $this->assertSame(['stale' => 0, 'redispatched' => 1, 'failed_permanent' => 0], $result);
        Queue::assertPushed(RunPythonActorJob::class, fn (RunPythonActorJob $job): bool => $job->actorName === PythonActorRunner::ACTOR_HANDLE_PAPERLESS_WEBHOOK
            && $job->commandId === $delivery->id);
        $this->assertSame(WebhookDelivery::STATUS_QUEUED, $delivery->fresh()->status);
        $this->assertSame(ActorExecution::STATUS_FAILED, $execution->fresh()->status);
    }

    public function test_recovery_gives_a_new_actor_claim_time_to_register_execution_before_cancelling(): void
    {
        $run = $this->pipelineRun([
            'status' => PipelineRun::STATUS_CANCEL_REQUESTED,
            'paperless_document_id' => 82,
            'progress_updated_at' => now(),
        ]);

        $this->assertSame(0, app(PipelineRecoveryDispatcher::class)->finalizeCancelRequestedRuns(limit: 10));
        $this->assertSame(PipelineRun::STATUS_CANCEL_REQUESTED, $run->fresh()->status);

        $run->timestamps = false;
        $run->forceFill(['progress_updated_at' => now()->subMinutes(11)])->save();
        $this->assertSame(1, app(PipelineRecoveryDispatcher::class)->finalizeCancelRequestedRuns(limit: 10));
        $this->assertSame(PipelineRun::STATUS_CANCELLED, $run->fresh()->status);
    }

    public function test_recovery_does_not_finalize_cancel_request_while_actor_is_alive(): void
    {
        $run = $this->pipelineRun([
            'status' => PipelineRun::STATUS_CANCEL_REQUESTED,
            'paperless_document_id' => 81,
        ]);
        ActorExecution::query()->create([
            'pipeline_run_id' => $run->id,
            'paperless_document_id' => 81,
            'actor_name' => PythonActorRunner::ACTOR_HANDLE_DOCUMENT_PIPELINE,
            'status' => ActorExecution::STATUS_RUNNING,
            'worker_id' => $this->liveWorkerId(),
            'started_at' => now()->subMinutes(11),
            'progress_updated_at' => now()->subMinutes(11),
        ]);

        app(PipelineRecoveryDispatcher::class)->recoverActorExecutions(limit: 10);
        $count = app(PipelineRecoveryDispatcher::class)->finalizeCancelRequestedRuns(limit: 10);

        $this->assertSame(0, $count);
        $this->assertSame(PipelineRun::STATUS_CANCEL_REQUESTED, $run->fresh()->status);
    }

    public function test_recovery_gives_malformed_worker_identity_a_conservative_liveness_window(): void
    {
        Queue::fake();
        $command = $this->command([
            'type' => Command::TYPE_REINDEX,
            'status' => Command::STATUS_RUNNING,
        ]);
        ActorExecution::query()->create([
            'command_id' => $command->id,
            'actor_name' => PythonActorRunner::ACTOR_REINDEX,
            'status' => ActorExecution::STATUS_RUNNING,
            'attempt' => 1,
            'max_attempts' => 5,
            'worker_id' => 'malformed',
            'started_at' => now()->subMinutes(30),
            'progress_updated_at' => now()->subMinutes(30),
        ]);

        $result = app(PipelineRecoveryDispatcher::class)->recoverActorExecutions(limit: 10);

        $this->assertSame(['stale' => 0, 'redispatched' => 0, 'failed_permanent' => 0], $result);
        Queue::assertNothingPushed();
    }

    public function test_recovery_treats_malformed_or_reused_worker_identity_as_stale(): void
    {
        Queue::fake();
        foreach ([
            ['malformed', 61],
            [gethostname().':'.getmypid().':0', 11],
        ] as [$workerId, $staleMinutes]) {
            $command = $this->command([
                'type' => Command::TYPE_REINDEX,
                'status' => Command::STATUS_RUNNING,
            ]);
            ActorExecution::query()->create([
                'command_id' => $command->id,
                'actor_name' => PythonActorRunner::ACTOR_REINDEX,
                'status' => ActorExecution::STATUS_RUNNING,
                'attempt' => 1,
                'max_attempts' => 5,
                'worker_id' => $workerId,
                'started_at' => now()->subMinutes($staleMinutes),
                'progress_updated_at' => now()->subMinutes($staleMinutes),
            ]);
        }

        $result = app(PipelineRecoveryDispatcher::class)->recoverActorExecutions(limit: 10);

        $this->assertSame(2, $result['stale']);
    }

    private function liveWorkerId(): string
    {
        $pid = getmypid();
        $stat = file_get_contents("/proc/{$pid}/stat");
        $this->assertNotFalse($stat);
        $separator = strrpos($stat, ') ');
        $this->assertNotFalse($separator);
        $fields = preg_split('/\s+/', trim(substr($stat, $separator + 2)));
        $this->assertArrayHasKey(19, $fields);

        return gethostname().":{$pid}:{$fields[19]}";
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
