<?php

namespace Tests\Feature;

use App\Models\Command;
use App\Models\TemporalOutboxIntent;
use App\Services\Pipeline\MaintenanceCommandDispatcher;
use App\Services\Pipeline\PipelineRecoveryDispatcher;
use App\Services\Temporal\TemporalOutbox;
use App\Services\Temporal\TemporalWorkflowDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class TemporalEmbeddingDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_embedding_build_is_transactionally_dispatched_to_temporal(): void
    {
        Queue::fake();
        $request = Request::create('/admin/maintenance', 'POST');

        $command = app(MaintenanceCommandDispatcher::class)
            ->queueEmbeddingIndexBuild($request, 12);

        $this->assertSame(Command::STATUS_QUEUED, $command->status);
        $this->assertSame(TemporalWorkflowDispatcher::DRIVER, $command->payload['orchestration_driver']);
        $this->assertSame("archibot/embedding-index/{$command->id}", $command->payload['temporal_workflow_id']);
        $this->assertDatabaseHas('temporal_outbox_intents', [
            'operation' => TemporalOutboxIntent::OPERATION_START,
            'workflow_id' => "archibot/embedding-index/{$command->id}",
            'workflow_type' => TemporalWorkflowDispatcher::EMBEDDING_WORKFLOW,
            'status' => TemporalOutboxIntent::STATUS_PENDING,
        ]);
        Queue::assertNothingPushed();
    }

    public function test_reindex_uses_a_distinct_temporal_generation(): void
    {
        Queue::fake();
        $request = Request::create('/admin/maintenance', 'POST');

        $command = app(MaintenanceCommandDispatcher::class)->queueReindex($request);

        $this->assertSame('reindex', $command->type);
        $this->assertSame(TemporalWorkflowDispatcher::DRIVER, $command->payload['orchestration_driver']);
        $this->assertDatabaseHas('temporal_outbox_intents', [
            'workflow_id' => "archibot/embedding-index/{$command->id}",
        ]);
        Queue::assertNothingPushed();
    }

    public function test_laravel_recovery_does_not_redispatch_temporal_embedding_commands(): void
    {
        Queue::fake();
        $request = Request::create('/admin/maintenance', 'POST');
        $command = app(MaintenanceCommandDispatcher::class)
            ->queueEmbeddingIndexBuild($request);
        $command->forceFill(['updated_at' => now()->subHour()])->save();

        $recovered = app(PipelineRecoveryDispatcher::class)->recoverPendingCommands();

        $this->assertSame(0, $recovered);
        $this->assertSame(Command::STATUS_QUEUED, $command->fresh()->status);
        $this->assertDatabaseCount('actor_executions', 0);
        $this->assertDatabaseCount('temporal_outbox_intents', 1);
        Queue::assertNothingPushed();
    }

    public function test_embedding_request_rolls_back_when_outbox_intent_cannot_be_written(): void
    {
        Queue::fake();
        $outbox = $this->mock(TemporalOutbox::class);
        $outbox->shouldReceive('startWorkflow')
            ->once()
            ->andThrow(new RuntimeException('outbox unavailable'));

        try {
            app(MaintenanceCommandDispatcher::class)
                ->queueEmbeddingIndexBuild(Request::create('/admin/maintenance', 'POST'));
            $this->fail('Expected Temporal outbox failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('outbox unavailable', $exception->getMessage());
        }

        $this->assertDatabaseCount('commands', 0);
        $this->assertDatabaseCount('embedding_index_state', 0);
        $this->assertDatabaseCount('pipeline_events', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('temporal_outbox_intents', 0);
        Queue::assertNothingPushed();
    }
}
