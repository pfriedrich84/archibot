<?php

namespace Tests\Feature\Pipeline;

use App\Models\EmbeddingIndexState;
use App\Models\PipelineRun;
use App\Models\TemporalOutboxIntent;
use App\Services\Pipeline\DocumentPipelineStarter;
use App\Services\Pipeline\PipelineStartGate;
use App\Services\Temporal\TemporalOutbox;
use App\Services\Temporal\TemporalWorkflowDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class DocumentPipelineStartServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_gate_open_creates_queued_run_and_temporal_start_intent(): void
    {
        Queue::fake();
        EmbeddingIndexState::query()->create(['status' => 'complete']);

        $result = app(DocumentPipelineStarter::class)->start(
            triggerSource: 'webhook',
            paperlessDocumentId: 42,
            paperlessModified: '2026-05-08T12:00:00Z',
        );

        $this->assertSame('created', $result->outcome);
        $this->assertTrue($result->created);
        $this->assertSame(PipelineRun::STATUS_QUEUED, $result->pipelineRun->status);
        $this->assertSame('webhook', $result->pipelineRun->trigger_source);
        $this->assertSame(['webhook'], $result->pipelineRun->coalesced_sources);
        $this->assertDatabaseHas('pipeline_events', ['event_type' => 'pipeline.start.pending']);
        $this->assertDatabaseHas('pipeline_events', ['event_type' => 'pipeline.document_actor_queued']);
        $this->assertSame(TemporalWorkflowDispatcher::DRIVER, $result->pipelineRun->orchestration_driver);
        $this->assertDatabaseHas('temporal_outbox_intents', [
            'workflow_id' => $result->pipelineRun->temporal_workflow_id,
            'workflow_type' => TemporalWorkflowDispatcher::DOCUMENT_WORKFLOW,
            'status' => TemporalOutboxIntent::STATUS_PENDING,
        ]);
        Queue::assertNothingPushed();
    }

    public function test_outbox_failure_rolls_back_document_run(): void
    {
        EmbeddingIndexState::query()->create(['status' => 'complete']);
        $outbox = $this->mock(TemporalOutbox::class);
        $outbox->shouldReceive('startWorkflow')->once()->andThrow(new RuntimeException('outbox unavailable'));

        try {
            app(DocumentPipelineStarter::class)->start(
                triggerSource: 'poll',
                paperlessDocumentId: 42,
                paperlessModified: '2026-05-08T12:00:00Z',
            );
            $this->fail('Expected outbox failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('outbox unavailable', $exception->getMessage());
        }

        $this->assertDatabaseCount('pipeline_runs', 0);
        $this->assertDatabaseCount('pipeline_events', 0);
        $this->assertDatabaseCount('temporal_outbox_intents', 0);
    }

    public function test_temporal_intent_is_committed_with_queued_projection(): void
    {
        EmbeddingIndexState::query()->create(['status' => EmbeddingIndexState::STATUS_COMPLETE]);
        Queue::fake();

        $result = app(DocumentPipelineStarter::class)->start(
            triggerSource: 'webhook',
            paperlessDocumentId: 43,
            paperlessModified: '2026-05-08T12:00:00Z',
        );

        $this->assertSame(PipelineRun::STATUS_QUEUED, $result->pipelineRun->status);
        $this->assertNull($result->pipelineRun->started_at);
        $this->assertDatabaseHas('pipeline_events', [
            'pipeline_run_id' => $result->pipelineRun->id,
            'event_type' => 'pipeline.document_actor_queued',
        ]);
        $this->assertDatabaseCount('temporal_outbox_intents', 1);
        Queue::assertNothingPushed();
    }

    public function test_gate_closed_creates_blocked_run(): void
    {
        Queue::fake();
        EmbeddingIndexState::query()->create(['status' => 'stale']);

        $result = app(DocumentPipelineStarter::class)->start(
            triggerSource: 'webhook',
            paperlessDocumentId: 42,
            paperlessModified: null,
        );

        $run = $result->pipelineRun;
        $this->assertSame('blocked', $result->outcome);
        $this->assertSame('embedding_index_not_ready', $result->blockedReason);
        $this->assertSame(PipelineRun::STATUS_BLOCKED, $run->status);
        $this->assertSame('blocked', $run->progress_current_phase);
        $this->assertSame('Waiting for embedding index to complete.', $run->progress_message);
        $this->assertSame('embedding_index_not_ready', $run->error_type);
        $this->assertDatabaseHas('pipeline_events', [
            'pipeline_run_id' => $run->id,
            'event_type' => 'pipeline.blocked.embedding_index_not_ready',
        ]);
        Queue::assertNothingPushed();
    }

    public function test_start_revalidates_and_dispatches_inside_one_shared_fence(): void
    {
        Queue::fake();
        $events = [];
        EmbeddingIndexState::query()->create(['status' => EmbeddingIndexState::STATUS_COMPLETE]);
        $this->mock(PipelineStartGate::class, function (MockInterface $mock) use (&$events): void {
            $mock->shouldReceive('pipelineStart')->once()->andReturnUsing(function ($callback) use (&$events) {
                $events[] = 'shared-acquired';
                try {
                    $result = $callback();
                    $this->assertDatabaseCount('temporal_outbox_intents', 1);
                    $events[] = 'intent-observed-inside-fence';

                    return $result;
                } finally {
                    $events[] = 'shared-released';
                }
            });
            $mock->shouldReceive('isOpen')->once()->andReturnUsing(function () use (&$events): bool {
                $events[] = 'gate-revalidated';

                return true;
            });
        });

        $result = app(DocumentPipelineStarter::class)->start(
            triggerSource: 'webhook',
            paperlessDocumentId: 42,
            paperlessModified: '2026-05-08T12:00:00Z',
        );

        $this->assertSame('created', $result->outcome);
        $this->assertSame(PipelineRun::STATUS_QUEUED, $result->pipelineRun->status);
        $this->assertSame([
            'shared-acquired',
            'gate-revalidated',
            'intent-observed-inside-fence',
            'shared-released',
        ], $events);
        Queue::assertNothingPushed();
    }

    public function test_stale_transition_ordered_before_start_blocks_without_dispatch(): void
    {
        Queue::fake();
        $state = EmbeddingIndexState::query()->create(['status' => EmbeddingIndexState::STATUS_COMPLETE]);
        $this->mock(PipelineStartGate::class, function (MockInterface $mock) use ($state): void {
            $mock->shouldReceive('pipelineStart')->once()->andReturnUsing(function ($callback) use ($state) {
                // Model the exclusive transition winning before this shared
                // start fence is granted.
                $state->forceFill(['status' => EmbeddingIndexState::STATUS_STALE])->save();

                return $callback();
            });
            $mock->shouldReceive('isOpen')->once()->andReturnFalse();
        });

        $result = app(DocumentPipelineStarter::class)->start(
            triggerSource: 'webhook',
            paperlessDocumentId: 42,
            paperlessModified: '2026-05-08T12:00:00Z',
        );

        $this->assertSame('blocked', $result->outcome);
        $this->assertSame(PipelineRun::STATUS_BLOCKED, $result->pipelineRun->status);
        $this->assertSame('embedding_index_not_ready', $result->pipelineRun->error_type);
        Queue::assertNothingPushed();
    }

    public function test_same_document_content_coalesces_sources_and_changed_modified_creates_new_run(): void
    {
        Queue::fake();
        EmbeddingIndexState::query()->create(['status' => 'complete']);
        $starter = app(DocumentPipelineStarter::class);

        $first = $starter->start('webhook', 42, '2026-05-08T12:00:00Z');
        $second = $starter->start('poll', 42, '2026-05-08T12:00:00Z');
        $changed = $starter->start('poll', 42, '2026-05-09T12:00:00Z');

        $this->assertSame('created', $first->outcome);
        $this->assertSame('coalesced', $second->outcome);
        $this->assertFalse($second->created);
        $this->assertSame($first->pipelineRun->id, $second->pipelineRun->id);
        $this->assertEqualsCanonicalizing(['webhook', 'poll'], $second->pipelineRun->coalesced_sources);
        $this->assertSame('created', $changed->outcome);
        $this->assertNotSame($first->dedupeKey, $changed->dedupeKey);
        $this->assertDatabaseCount('pipeline_runs', 2);
        $this->assertDatabaseHas('pipeline_events', ['event_type' => 'pipeline.start.coalesced']);
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('temporal_outbox_intents', 2);
    }

    public function test_manual_force_always_creates_new_run_for_identical_content(): void
    {
        Queue::fake();
        EmbeddingIndexState::query()->create(['status' => 'complete']);
        $starter = app(DocumentPipelineStarter::class);

        $first = $starter->start(
            triggerSource: 'manual',
            paperlessDocumentId: 42,
            paperlessModified: '2026-05-08T12:00:00Z',
            reprocessRequested: true,
            reprocessMode: 'manual',
            forceNewRun: true,
            forceToken: 'first',
        );
        $second = $starter->start(
            triggerSource: 'manual',
            paperlessDocumentId: 42,
            paperlessModified: '2026-05-08T12:00:00Z',
            reprocessRequested: true,
            reprocessMode: 'manual',
            forceNewRun: true,
            forceToken: 'second',
        );

        $this->assertSame('force_created', $first->outcome);
        $this->assertSame('force_created', $second->outcome);
        $this->assertNotSame($first->dedupeKey, $second->dedupeKey);
        $this->assertDatabaseCount('pipeline_runs', 2);
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('temporal_outbox_intents', 2);
    }

    public function test_laravel_dedupe_key_matches_canonical_known_vector(): void
    {
        $key = app(DocumentPipelineStarter::class)->dedupeKey(
            42,
            '2026-05-08T12:00:00Z',
            'hash',
        );

        $this->assertSame(hash('sha256', '42:2026-05-08T12:00:00.000000Z:hash:v1'), $key);
    }

    public function test_equivalent_timestamp_offsets_share_one_canonical_dedupe_key(): void
    {
        $starter = app(DocumentPipelineStarter::class);

        $zulu = $starter->dedupeKey(42, '2026-05-08T12:00:00Z', 'HASH');
        $offset = $starter->dedupeKey(42, '2026-05-08T14:00:00+02:00', 'hash');
        $fractional = $starter->dedupeKey(42, '2026-05-08T12:00:00.000000+00:00', ' hash ');

        $this->assertSame($zulu, $offset);
        $this->assertSame($zulu, $fractional);
    }

    public function test_dedupe_keys_match_shared_pipeline_start_contract_vectors(): void
    {
        $contract = $this->pipelineStartContract();
        $starter = app(DocumentPipelineStarter::class);

        foreach ($contract['dedupe_vectors'] as $vector) {
            $this->assertSame(
                $vector['expected_sha256'],
                $starter->dedupeKey(
                    $vector['paperless_document_id'],
                    $vector['paperless_modified'],
                    $vector['content_hash'],
                    $vector['pipeline_version'],
                ),
                $vector['name'],
            );
        }
    }

    public function test_force_dedupe_keys_match_shared_pipeline_start_contract_vectors(): void
    {
        $contract = $this->pipelineStartContract();
        $starter = app(DocumentPipelineStarter::class);

        foreach ($contract['force_vectors'] as $vector) {
            $this->assertSame(
                $vector['expected_sha256'],
                $starter->forceDedupeKey(
                    $vector['paperless_document_id'],
                    $vector['paperless_modified'],
                    $vector['content_hash'],
                    $vector['force_token'],
                    $vector['pipeline_version'],
                ),
                $vector['name'],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function pipelineStartContract(): array
    {
        $path = dirname(base_path()).'/tests/fixtures/pipeline_start_contract.json';
        $contract = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($contract);

        return $contract;
    }
}
