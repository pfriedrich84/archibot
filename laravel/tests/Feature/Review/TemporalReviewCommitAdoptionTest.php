<?php

namespace Tests\Feature\Review;

use App\Models\Command;
use App\Models\PipelineRun;
use App\Models\ReviewSuggestion;
use App\Models\TemporalOutboxIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class TemporalReviewCommitAdoptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_adopts_stuck_review_commits_without_legacy_redispatch(): void
    {
        $documentWorkflowId = 'archibot/document/501/version-1';
        $run = PipelineRun::query()->create([
            'type' => 'document',
            'status' => PipelineRun::STATUS_SUCCEEDED,
            'scope' => 'single_document',
            'trigger_source' => 'poll',
            'orchestration_driver' => 'temporal',
            'temporal_workflow_id' => $documentWorkflowId,
            'paperless_document_id' => 501,
            'pipeline_dedupe_key' => 'temporal-adoption-501',
        ]);
        $temporal = $this->stuckCommit(501, $run->id);
        $legacy = $this->stuckCommit(502);

        $migration = require database_path(
            'migrations/2026_09_14_000003_adopt_pending_review_commits_into_temporal.php',
        );
        $migration->up();

        $temporalCommand = $temporal->commitCommand()->firstOrFail();
        $legacyCommand = $legacy->commitCommand()->firstOrFail();
        $this->assertSame('temporal', $temporalCommand->payload['orchestration_driver']);
        $this->assertSame($documentWorkflowId, $temporalCommand->payload['temporal_workflow_id']);
        $this->assertSame('temporal', $legacyCommand->payload['orchestration_driver']);
        $this->assertSame(
            "archibot/review-commit/{$legacy->id}",
            $legacyCommand->payload['temporal_workflow_id'],
        );

        $signal = TemporalOutboxIntent::query()
            ->where('operation', TemporalOutboxIntent::OPERATION_SIGNAL)
            ->firstOrFail();
        $this->assertSame($documentWorkflowId, $signal->workflow_id);
        $this->assertSame('review_decision', $signal->signal_name);
        $this->assertSame($temporal->id, $signal->payload['review_suggestion_id']);

        $start = TemporalOutboxIntent::query()
            ->where('operation', TemporalOutboxIntent::OPERATION_START)
            ->firstOrFail();
        $this->assertSame("archibot/review-commit/{$legacy->id}", $start->workflow_id);
        $this->assertSame('archibot.review_commit', $start->workflow_type);
        $this->assertSame($legacy->id, $start->payload['review_suggestion_id']);
        $this->assertDatabaseCount('temporal_outbox_intents', 2);
        $this->assertDatabaseCount('actor_executions', 0);
    }

    public function test_signal_upgrade_redelivers_dropped_review_decision_to_active_workflow(): void
    {
        $workflowId = 'archibot/document/288';
        $run = PipelineRun::query()->create([
            'type' => 'document',
            'status' => PipelineRun::STATUS_RUNNING,
            'scope' => 'single_document',
            'trigger_source' => 'poll',
            'orchestration_driver' => 'temporal',
            'temporal_workflow_id' => $workflowId,
            'paperless_document_id' => 288,
            'pipeline_dedupe_key' => 'temporal-signal-upgrade-288',
            'progress_current_phase' => 'awaiting_review',
        ]);
        $suggestion = $this->stuckCommit(288, $run->id);
        $command = $suggestion->commitCommand()->firstOrFail();
        $payload = [
            'review_suggestion_id' => $suggestion->id,
            'command_id' => $command->id,
            'decision' => 'accepted',
            'temporal_workflow_id' => $workflowId,
        ];
        TemporalOutboxIntent::query()->create([
            'intent_key' => Uuid::uuid4()->toString(),
            'operation' => TemporalOutboxIntent::OPERATION_SIGNAL,
            'workflow_id' => $workflowId,
            'signal_name' => 'review_decision',
            'payload' => $payload,
            'status' => TemporalOutboxIntent::STATUS_DELIVERED,
            'available_at' => now(),
            'delivered_at' => now(),
        ]);

        $migration = require database_path(
            'migrations/2026_09_16_000000_upgrade_temporal_document_signals.php',
        );
        $migration->up();
        $migration->up();

        $upgraded = TemporalOutboxIntent::query()
            ->where('signal_name', 'review_decision_v2')
            ->firstOrFail();
        $this->assertSame($workflowId, $upgraded->workflow_id);
        $this->assertSame($payload, $upgraded->payload);
        $this->assertSame(TemporalOutboxIntent::STATUS_PENDING, $upgraded->status);
        $this->assertDatabaseCount('temporal_outbox_intents', 2);
    }

    private function stuckCommit(int $paperlessDocumentId, ?int $pipelineRunId = null): ReviewSuggestion
    {
        $command = Command::query()->create([
            'type' => Command::TYPE_REVIEW_COMMIT,
            'status' => Command::STATUS_RUNNING,
            'payload' => ['paperless_document_id' => $paperlessDocumentId],
            'started_at' => now()->subMinutes(10),
        ]);

        return ReviewSuggestion::factory()->create([
            'pipeline_run_id' => $pipelineRunId,
            'paperless_document_id' => $paperlessDocumentId,
            'status' => ReviewSuggestion::STATUS_ACCEPTED,
            'commit_status' => ReviewSuggestion::COMMIT_STATUS_RUNNING,
            'commit_command_id' => $command->id,
        ]);
    }
}
