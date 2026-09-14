<?php

namespace Tests\Feature\Pipeline;

use App\Models\Command;
use App\Models\EmbeddingIndexState;
use App\Models\PipelineRun;
use App\Models\PollCandidate;
use App\Services\Pipeline\PipelineRecoveryDispatcher;
use App\Services\Pipeline\PollCandidateConsumer;
use App\Services\Pipeline\StagedDocumentBatchDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class StagedDocumentBatchDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_temporal_poll_targets_are_never_attached_to_a_global_batch(): void
    {
        Queue::fake();
        EmbeddingIndexState::query()->create(['status' => EmbeddingIndexState::STATUS_COMPLETE]);
        $source = Command::query()->create([
            'type' => Command::TYPE_POLL_RECONCILIATION,
            'status' => Command::STATUS_SUCCEEDED,
            'payload' => ['force' => true, 'orchestration_driver' => 'temporal'],
        ]);
        foreach ([101, 102] as $documentId) {
            PollCandidate::query()->create([
                'candidate_id' => (string) Str::uuid(),
                'idempotency_key' => hash('sha256', "{$source->id}:{$documentId}"),
                'protocol_version' => PollCandidate::PROTOCOL_VERSION,
                'command_id' => $source->id,
                'paperless_document_id' => $documentId,
                'marker_disposition' => PollCandidate::MARKER_UNCLASSIFIED,
                'status' => PollCandidate::STATUS_READY,
                'trigger_metadata' => [
                    'trigger_source' => 'poll',
                    'force' => true,
                    'command_id' => $source->id,
                ],
            ]);
        }

        app(PollCandidateConsumer::class)->consumeCommand($source->id);

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('pipeline_runs', 2);
        $this->assertDatabaseHas('pipeline_runs', ['orchestration_driver' => 'temporal']);

        $batch = app(StagedDocumentBatchDispatcher::class)->dispatchForPollCommand($source->id);

        $this->assertNull($batch);
        Queue::assertNothingPushed();

        app(StagedDocumentBatchDispatcher::class)->dispatchForPollCommand($source->id);
        $this->assertDatabaseCount('commands', 1);
        $this->assertDatabaseCount('temporal_outbox_intents', 2);
        Queue::assertNothingPushed();
    }

    public function test_gate_closed_temporal_target_waits_without_a_global_batch(): void
    {
        Queue::fake();
        $embedding = EmbeddingIndexState::query()->create(['status' => EmbeddingIndexState::STATUS_PENDING]);
        $source = Command::query()->create([
            'type' => Command::TYPE_POLL_RECONCILIATION,
            'status' => Command::STATUS_SUCCEEDED,
            'payload' => ['force' => true, 'orchestration_driver' => 'temporal'],
        ]);
        PollCandidate::query()->create([
            'candidate_id' => (string) Str::uuid(),
            'idempotency_key' => hash('sha256', "{$source->id}:blocked:201"),
            'protocol_version' => PollCandidate::PROTOCOL_VERSION,
            'command_id' => $source->id,
            'paperless_document_id' => 201,
            'marker_disposition' => PollCandidate::MARKER_UNCLASSIFIED,
            'status' => PollCandidate::STATUS_READY,
            'trigger_metadata' => [
                'trigger_source' => 'poll',
                'force' => true,
                'command_id' => $source->id,
            ],
        ]);

        app(PollCandidateConsumer::class)->consumeEntireCommand($source->id);
        $batch = app(StagedDocumentBatchDispatcher::class)->dispatchForPollCommand($source->id);

        $this->assertNull($batch);
        $this->assertDatabaseHas('pipeline_runs', [
            'command_id' => $source->id,
            'batch_command_id' => null,
            'status' => 'blocked',
            'error_type' => 'embedding_index_not_ready',
            'orchestration_driver' => 'temporal',
        ]);
        Queue::assertNothingPushed();

        $embedding->update(['status' => EmbeddingIndexState::STATUS_COMPLETE]);
        app(PipelineRecoveryDispatcher::class)->recoverDocumentPipelineRuns();

        $this->assertNull($batch);
        $this->assertDatabaseHas('pipeline_runs', [
            'command_id' => $source->id,
            'batch_command_id' => null,
            'status' => 'queued',
            'orchestration_driver' => 'temporal',
        ]);
        $this->assertDatabaseCount('temporal_outbox_intents', 1);
        Queue::assertNothingPushed();
    }

    public function test_blocked_singleton_coalesced_with_poll_is_not_attached_to_batch(): void
    {
        Queue::fake();
        $source = Command::query()->create([
            'type' => Command::TYPE_POLL_RECONCILIATION,
            'status' => Command::STATUS_SUCCEEDED,
            'payload' => [],
        ]);
        $singleton = PipelineRun::query()->create([
            'command_id' => $source->id,
            'type' => 'document',
            'status' => PipelineRun::STATUS_BLOCKED,
            'scope' => 'single_document',
            'trigger_source' => 'webhook',
            'paperless_document_id' => 301,
            'progress_current_phase' => 'blocked',
            'error_type' => 'embedding_index_not_ready',
        ]);
        $pollTarget = PipelineRun::query()->create([
            'command_id' => $source->id,
            'type' => 'document',
            'status' => PipelineRun::STATUS_BLOCKED,
            'scope' => 'single_document',
            'trigger_source' => 'poll',
            'paperless_document_id' => 302,
            'progress_current_phase' => 'staged_batch_wait',
            'error_type' => 'embedding_index_not_ready',
        ]);

        $batch = app(StagedDocumentBatchDispatcher::class)->dispatchForPollCommand($source->id);

        $this->assertNotNull($batch);
        $this->assertSame(1, $batch->payload['document_count']);
        $this->assertNull($singleton->fresh()->batch_command_id);
        $this->assertSame($batch->id, $pollTarget->fresh()->batch_command_id);
        Queue::assertNothingPushed();
    }

    public function test_retrying_poll_command_cannot_seal_partial_candidates(): void
    {
        Queue::fake();
        $source = Command::query()->create([
            'type' => Command::TYPE_POLL_RECONCILIATION,
            'status' => 'retrying',
            'payload' => [],
        ]);
        PipelineRun::query()->create([
            'command_id' => $source->id,
            'type' => 'document',
            'status' => 'pending',
            'scope' => 'single_document',
            'trigger_source' => 'poll',
            'paperless_document_id' => 301,
            'progress_current_phase' => 'staged_batch_wait',
        ]);

        $this->assertNull(
            app(StagedDocumentBatchDispatcher::class)->dispatchForPollCommand($source->id),
        );
        Queue::assertNothingPushed();
    }
}
