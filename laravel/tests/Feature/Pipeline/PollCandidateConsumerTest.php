<?php

namespace Tests\Feature\Pipeline;

use App\Jobs\RunPythonActorJob;
use App\Models\Command;
use App\Models\EmbeddingIndexState;
use App\Models\PollCandidate;
use App\Services\Pipeline\DocumentPipelineStarter;
use App\Services\Pipeline\PollCandidateConsumer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Tests\TestCase;

class PollCandidateConsumerTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_and_poll_candidate_coalesce_and_only_new_run_dispatches(): void
    {
        Queue::fake();
        EmbeddingIndexState::query()->create(['status' => 'complete']);
        $command = $this->command();
        $webhook = app(DocumentPipelineStarter::class)->start(
            'webhook',
            42,
            '2026-05-08T12:00:00Z',
        );
        $candidate = $this->candidate($command, 'candidate-one', false, '2026-05-08T14:00:00+02:00');

        $counts = app(PollCandidateConsumer::class)->consumeCommand($command->id);

        $this->assertSame(['completed' => 1, 'skipped' => 0, 'failed' => 0], $counts);
        $this->assertSame('coalesced', $candidate->fresh()->starter_outcome);
        $this->assertSame($webhook->pipelineRun->id, $candidate->fresh()->pipeline_run_id);
        $this->assertDatabaseCount('pipeline_runs', 1);
        Queue::assertPushed(RunPythonActorJob::class, 1);
    }

    public function test_marker_skip_and_forced_poll_have_distinct_semantics(): void
    {
        Queue::fake();
        EmbeddingIndexState::query()->create(['status' => 'complete']);
        $command = $this->command();
        $marked = $this->candidate($command, 'marked', false, null, PollCandidate::MARKER_ALREADY_CLASSIFIED);
        $forced = $this->candidate($command, 'forced', true, null, PollCandidate::MARKER_ALREADY_CLASSIFIED);

        $counts = app(PollCandidateConsumer::class)->consumeCommand($command->id);

        $this->assertSame(['completed' => 1, 'skipped' => 1, 'failed' => 0], $counts);
        $this->assertSame('marker_skipped', $marked->fresh()->starter_outcome);
        $this->assertSame('force_created', $forced->fresh()->starter_outcome);
        $this->assertTrue($forced->fresh()->pipelineRun->reprocess_requested);
        Queue::assertPushed(RunPythonActorJob::class, 1);
    }

    public function test_distinct_force_candidates_create_distinct_runs(): void
    {
        Queue::fake();
        EmbeddingIndexState::query()->create(['status' => 'complete']);
        $firstCommand = $this->command();
        $secondCommand = $this->command();
        $first = $this->candidate($firstCommand, 'force-one', true, '2026-05-08T12:00:00Z');
        $second = $this->candidate($secondCommand, 'force-two', true, '2026-05-08T12:00:00Z');
        $consumer = app(PollCandidateConsumer::class);

        $consumer->consumeCommand($firstCommand->id);
        $consumer->consumeCommand($secondCommand->id);

        $this->assertSame('force_created', $first->fresh()->starter_outcome);
        $this->assertSame('force_created', $second->fresh()->starter_outcome);
        $this->assertNotSame($first->fresh()->pipeline_run_id, $second->fresh()->pipeline_run_id);
        $this->assertDatabaseCount('pipeline_runs', 2);
        Queue::assertPushed(RunPythonActorJob::class, 2);
    }

    public function test_unsupported_protocol_is_terminal_without_normalizing_untrusted_state(): void
    {
        Queue::fake();
        EmbeddingIndexState::query()->create(['status' => 'complete']);
        $command = $this->command();
        $candidate = $this->candidate($command, 'unsupported', false, 'not-a-timestamp');
        $candidate->forceFill(['protocol_version' => 99])->save();

        $counts = app(PollCandidateConsumer::class)->consumeCommand($command->id);

        $this->assertSame(['completed' => 0, 'skipped' => 0, 'failed' => 1], $counts);
        $this->assertSame(PollCandidate::STATUS_SKIPPED, $candidate->fresh()->status);
        $this->assertSame('protocol_rejected', $candidate->fresh()->starter_outcome);
        $this->assertSame('unsupported_protocol_version', $candidate->fresh()->error_type);
        $this->assertDatabaseCount('pipeline_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_invalid_content_state_is_terminal_and_does_not_abort_consumption(): void
    {
        Queue::fake();
        EmbeddingIndexState::query()->create(['status' => 'complete']);
        $command = $this->command();
        $candidate = $this->candidate($command, 'invalid-state', false, 'not-a-timestamp');

        $counts = app(PollCandidateConsumer::class)->consumeCommand($command->id);

        $this->assertSame(['completed' => 0, 'skipped' => 0, 'failed' => 1], $counts);
        $this->assertSame(PollCandidate::STATUS_SKIPPED, $candidate->fresh()->status);
        $this->assertSame('invalid_content_state', $candidate->fresh()->error_type);
        $this->assertDatabaseCount('pipeline_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_crash_replay_after_force_run_creation_coalesces_without_duplicate_dispatch(): void
    {
        Queue::fake();
        EmbeddingIndexState::query()->create(['status' => 'complete']);
        $command = $this->command();
        $candidate = $this->candidate($command, 'force-replay', true, '2026-05-08T12:00:00Z');
        $consumer = app(PollCandidateConsumer::class);
        $consumer->consumeCommand($command->id);
        $runId = $candidate->fresh()->pipeline_run_id;

        $candidate->forceFill([
            'status' => PollCandidate::STATUS_CLAIMED,
            'claimed_at' => now()->subMinutes(6),
            'completed_at' => null,
            'starter_outcome' => null,
            'pipeline_run_id' => null,
        ])->save();
        $counts = $consumer->replayPending();

        $this->assertSame(['completed' => 1, 'skipped' => 0, 'failed' => 0], $counts);
        $this->assertSame('coalesced', $candidate->fresh()->starter_outcome);
        $this->assertSame($runId, $candidate->fresh()->pipeline_run_id);
        $this->assertDatabaseCount('pipeline_runs', 1);
        Queue::assertPushed(RunPythonActorJob::class, 1);
    }

    public function test_reclaimed_candidate_rejects_stale_consumer_completion_and_failure(): void
    {
        $command = $this->command();
        $candidate = $this->candidate($command, 'two-consumers', false, null);
        $consumer = app(PollCandidateConsumer::class);

        $first = $consumer->claimCandidate($candidate->id);
        $this->assertNotNull($first);
        $candidate->forceFill(['claimed_at' => now()->subMinutes(6)])->save();
        $second = $consumer->claimCandidate($candidate->id);

        $this->assertNotNull($second);
        $this->assertNotSame($first->token, $second->token);
        $this->assertSame($first->version + 1, $second->version);

        $finish = new ReflectionMethod(PollCandidateConsumer::class, 'finishWithoutRun');
        $this->assertFalse($finish->invoke($consumer, $first, 'stale_consumer'));
        $this->assertSame(PollCandidate::STATUS_CLAIMED, $candidate->fresh()->status);
        $this->assertSame($second->token, $candidate->fresh()->claim_token);
        $this->assertNull($candidate->fresh()->error_type);

        $this->assertTrue($finish->invoke($consumer, $second, 'current_consumer'));
        $this->assertFalse($finish->invoke($consumer, $first, 'stale_overwrite'));
        $this->assertSame('current_consumer', $candidate->fresh()->error_type);
        $this->assertSame($second->version, $candidate->fresh()->claim_version);
    }

    public function test_candidate_audit_row_restricts_command_deletion(): void
    {
        $command = $this->command();
        $this->candidate($command, 'retained-audit', false, null);

        $this->expectException(QueryException::class);
        $command->delete();
    }

    public function test_candidate_audit_survives_pipeline_run_deletion_with_null_link(): void
    {
        Queue::fake();
        EmbeddingIndexState::query()->create(['status' => EmbeddingIndexState::STATUS_COMPLETE]);
        $command = $this->command();
        $candidate = $this->candidate($command, 'null-on-run-delete', false, null);
        app(PollCandidateConsumer::class)->consumeCommand($command->id);

        $candidate->fresh()->pipelineRun->delete();

        $this->assertDatabaseHas('poll_candidates', [
            'id' => $candidate->id,
            'pipeline_run_id' => null,
            'status' => PollCandidate::STATUS_COMPLETED,
        ]);
    }

    private function command(): Command
    {
        return Command::query()->create([
            'type' => Command::TYPE_POLL_RECONCILIATION,
            'status' => Command::STATUS_SUCCEEDED,
            'payload' => [],
        ]);
    }

    private function candidate(
        Command $command,
        string $token,
        bool $force,
        ?string $modified,
        string $marker = PollCandidate::MARKER_UNCLASSIFIED,
    ): PollCandidate {
        return PollCandidate::query()->create([
            'candidate_id' => '00000000-0000-4000-8000-'.str_pad(substr(hash('sha256', $token), 0, 12), 12, '0'),
            'protocol_version' => PollCandidate::PROTOCOL_VERSION,
            'command_id' => $command->id,
            'paperless_document_id' => 42,
            'discovered_modified' => $modified,
            'marker_disposition' => $marker,
            'trigger_metadata' => ['trigger_source' => 'poll', 'force' => $force, 'command_id' => $command->id],
            'idempotency_key' => hash('sha256', $token),
            'status' => PollCandidate::STATUS_READY,
        ]);
    }
}
