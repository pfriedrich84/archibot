<?php

namespace Tests\Feature\Pipeline;

use App\Jobs\RunPythonActorJob;
use App\Models\ActorExecution;
use App\Models\Command;
use App\Models\EmbeddingIndexState;
use App\Models\PipelineRun;
use App\Models\WebhookDelivery;
use App\Services\Actors\PythonActorRunner;
use App\Services\Pipeline\PipelineRecoveryDispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PostgresActorFencingUpgradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_reconciles_cross_actor_duplicates_per_source_before_unique_indexes(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL upgrade fixture requires pgsql.');
        }

        $migration = require database_path('migrations/2026_07_19_000000_add_actor_execution_fencing.php');
        $this->assertTrue($migration->withinTransaction);
        $migration->down();

        $command = Command::query()->create([
            'type' => Command::TYPE_REINDEX,
            'status' => Command::STATUS_RUNNING,
            'payload' => [],
        ]);
        $pipeline = PipelineRun::query()->create([
            'type' => 'document', 'status' => PipelineRun::STATUS_RUNNING,
            'scope' => 'single_document', 'trigger_source' => 'manual',
            'paperless_document_id' => 123, 'pipeline_dedupe_key' => 'upgrade-pipeline',
            'coalesced_sources' => ['manual'],
        ]);
        $webhook = WebhookDelivery::query()->create([
            'source' => 'paperless', 'event_type' => 'document_updated',
            'paperless_document_id' => 123, 'dedupe_key' => 'upgrade-webhook',
            'payload_hash' => hash('sha256', 'upgrade-webhook'), 'raw_payload' => [],
            'normalized_payload' => ['webhook_action' => 'refresh_embedding'], 'headers' => [],
            'status' => WebhookDelivery::STATUS_RUNNING, 'request_id' => 'upgrade-webhook',
            'received_at' => now(),
        ]);

        $fixtures = [
            ['command_id', $command->id, 'reindex', 'poll_reconciliation'],
            ['pipeline_run_id', $pipeline->id, 'handle_document_pipeline', 'stale_document_actor'],
            ['webhook_delivery_id', $webhook->id, 'handle_paperless_webhook', 'stale_webhook_actor'],
        ];
        $winners = [];
        foreach ($fixtures as [$column, $sourceId, $winnerActor, $loserActor]) {
            $base = [
                $column => $sourceId, 'max_attempts' => 5,
                'created_at' => now(), 'updated_at' => now(),
            ];
            DB::table('actor_executions')->insert($base + [
                'actor_name' => $loserActor, 'status' => 'pending', 'attempt' => 99,
            ]);
            DB::table('actor_executions')->insert($base + [
                'actor_name' => 'queued_'.$loserActor, 'status' => 'queued', 'attempt' => 50,
            ]);
            $winners[$column] = DB::table('actor_executions')->insertGetId($base + [
                'actor_name' => $winnerActor, 'status' => 'running', 'attempt' => 2,
            ]);
        }

        $migration->up();

        foreach ($fixtures as [$column, $sourceId]) {
            $winner = $winners[$column];
            $this->assertSame(1, DB::table('actor_executions')
                ->where($column, $sourceId)->whereIn('status', ['pending', 'queued', 'running'])->count());
            $this->assertSame('running', DB::table('actor_executions')->find($winner)->status);
            $this->assertSame(2, DB::table('pipeline_events')
                ->where($column, $sourceId)
                ->where('event_type', 'actor.execution.reconciled_duplicate')->count());
            $sourceTable = match ($column) {
                'pipeline_run_id' => 'pipeline_runs',
                'command_id' => 'commands',
                default => 'webhook_deliveries',
            };
            $expectedToken = $this->migrationToken($column, $sourceId, $winner);
            $this->assertSame($expectedToken, DB::table($sourceTable)->find($sourceId)->active_actor_token);
            $this->assertSame($expectedToken, DB::table('actor_executions')->find($winner)->execution_token);
            $this->assertLessThanOrEqual(64, strlen($expectedToken));
        }

        // The source-level index rejects a different actor name too.
        $this->expectException(QueryException::class);
        DB::table('actor_executions')->insert([
            'command_id' => $command->id, 'actor_name' => 'different_actor',
            'status' => 'queued', 'attempt' => 100, 'max_attempts' => 5,
            'execution_token' => 'cross-actor-duplicate', 'source_version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_upgrade_makes_sole_pending_command_pipeline_and_webhook_attempts_redispatchable_and_claimable(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL upgrade fixture requires pgsql.');
        }

        Queue::fake();
        $migration = require database_path('migrations/2026_07_19_000000_add_actor_execution_fencing.php');
        $migration->down();

        $command = Command::query()->create([
            'type' => Command::TYPE_REINDEX,
            'status' => Command::STATUS_PENDING,
            'payload' => [],
        ]);
        $pipeline = PipelineRun::query()->create([
            'type' => 'document', 'status' => PipelineRun::STATUS_PENDING,
            'scope' => 'single_document', 'trigger_source' => 'manual',
            'paperless_document_id' => 321, 'pipeline_dedupe_key' => 'sole-pending-pipeline',
            'coalesced_sources' => ['manual'],
        ]);
        $webhook = WebhookDelivery::query()->create([
            'source' => 'paperless', 'event_type' => 'document_updated',
            'paperless_document_id' => 321, 'dedupe_key' => 'sole-pending-webhook',
            'payload_hash' => hash('sha256', 'sole-pending-webhook'), 'raw_payload' => [],
            'normalized_payload' => ['webhook_action' => 'refresh_embedding'], 'headers' => [],
            'status' => WebhookDelivery::STATUS_RECEIVED, 'request_id' => 'sole-pending-webhook',
            'received_at' => now(),
        ]);

        $fixtures = [
            ['command_id', $command->id, PythonActorRunner::ACTOR_REINDEX],
            ['pipeline_run_id', $pipeline->id, PythonActorRunner::ACTOR_HANDLE_DOCUMENT_PIPELINE],
            ['webhook_delivery_id', $webhook->id, PythonActorRunner::ACTOR_HANDLE_PAPERLESS_WEBHOOK],
        ];
        foreach ($fixtures as [$column, $sourceId, $actor]) {
            DB::table('actor_executions')->insert([
                $column => $sourceId, 'actor_name' => $actor, 'status' => 'pending',
                'attempt' => 1, 'max_attempts' => 5, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $migration->up();

        foreach ($fixtures as [$column, $sourceId]) {
            $execution = ActorExecution::query()->where($column, $sourceId)->sole();
            $source = match ($column) {
                'command_id' => $command->fresh(),
                'pipeline_run_id' => $pipeline->fresh(),
                default => $webhook->fresh(),
            };
            $this->assertSame(ActorExecution::STATUS_RETRYING, $execution->status);
            $this->assertNotNull($execution->next_retry_at);
            $this->assertFalse($execution->next_retry_at->isFuture());
            $this->assertSame($execution->execution_token, $source->active_actor_token);
            $this->assertSame($execution->source_version, $source->lifecycle_version);
            $this->assertLessThanOrEqual(64, strlen($execution->execution_token));
        }
        $this->assertSame(Command::STATUS_PENDING, $command->fresh()->status);
        $this->assertSame(PipelineRun::STATUS_RETRYING, $pipeline->fresh()->status);
        $this->assertSame(WebhookDelivery::STATUS_FAILED, $webhook->fresh()->status);

        $result = app(PipelineRecoveryDispatcher::class)->recoverActorExecutions(limit: 10);
        $this->assertSame(3, $result['redispatched']);
        Queue::assertPushed(RunPythonActorJob::class, 3);

        $runner = $this->mock(PythonActorRunner::class, function (MockInterface $mock): void {
            $mock->shouldReceive('runReindex')->once();
            $mock->shouldReceive('runDocumentPipeline')->once();
            $mock->shouldReceive('runWebhookDelivery')->once();
        });
        Queue::pushed(RunPythonActorJob::class)->each(
            fn (RunPythonActorJob $job) => $job->handle($runner),
        );

        foreach ($fixtures as [$column, $sourceId]) {
            $executions = ActorExecution::query()->where($column, $sourceId)->orderBy('id')->get();
            $this->assertCount(2, $executions);
            $this->assertSame(ActorExecution::STATUS_FAILED, $executions->first()->status);
            $this->assertSame(ActorExecution::STATUS_QUEUED, $executions->last()->status);
            $this->assertNotSame($executions->first()->execution_token, $executions->last()->execution_token);
        }
        $this->assertSame(Command::STATUS_RUNNING, $command->fresh()->status);
        $this->assertSame(PipelineRun::STATUS_RUNNING, $pipeline->fresh()->status);
        $this->assertSame(WebhookDelivery::STATUS_RUNNING, $webhook->fresh()->status);
    }

    public function test_upgrade_retires_failed_process_document_pending_winner_for_pipeline_start_reconciliation(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL upgrade fixture requires pgsql.');
        }

        Queue::fake();
        $migration = require database_path('migrations/2026_07_19_000000_add_actor_execution_fencing.php');
        $migration->down();

        EmbeddingIndexState::query()->create(['status' => EmbeddingIndexState::STATUS_COMPLETE]);
        $webhook = WebhookDelivery::query()->create([
            'source' => 'paperless', 'event_type' => 'document_updated',
            'paperless_document_id' => 654, 'dedupe_key' => 'failed-process-document-pending',
            'payload_hash' => hash('sha256', 'failed-process-document-pending'), 'raw_payload' => [],
            'normalized_payload' => ['webhook_action' => 'process_document'], 'headers' => [],
            'status' => WebhookDelivery::STATUS_FAILED, 'error' => 'recoverable_processing',
            'request_id' => 'failed-process-document-pending', 'received_at' => now(),
        ]);
        $executionId = DB::table('actor_executions')->insertGetId([
            'webhook_delivery_id' => $webhook->id,
            'paperless_document_id' => $webhook->paperless_document_id,
            'actor_name' => PythonActorRunner::ACTOR_HANDLE_PAPERLESS_WEBHOOK,
            'status' => 'pending', 'attempt' => 1, 'max_attempts' => 5,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration->up();

        $execution = ActorExecution::query()->findOrFail($executionId);
        $this->assertSame(ActorExecution::STATUS_SKIPPED, $execution->status);
        $this->assertSame('migration_source_not_directly_retryable', $execution->error_type);
        $this->assertNull($execution->next_retry_at);
        $this->assertSame(WebhookDelivery::STATUS_FAILED, $webhook->fresh()->status);
        $this->assertNull($webhook->fresh()->active_actor_token);
        $this->assertDatabaseHas('pipeline_events', [
            'webhook_delivery_id' => $webhook->id,
            'event_type' => 'actor.execution.reconciled_inactive_source',
        ]);

        $actorRecovery = app(PipelineRecoveryDispatcher::class)->recoverActorExecutions(limit: 10);
        $this->assertSame(['stale' => 0, 'redispatched' => 0, 'failed_permanent' => 0], $actorRecovery);
        Queue::assertNothingPushed();

        // The obsolete webhook actor fence is gone, so the ordinary
        // process-document reconciliation path remains authoritative. Make
        // the fixture stale enough for that periodic path and verify it starts
        // a durable pipeline rather than redispatching the retired webhook actor.
        DB::table('webhook_deliveries')->where('id', $webhook->id)->update([
            'updated_at' => now()->subHours(2),
        ]);
        $this->assertSame(1, app(PipelineRecoveryDispatcher::class)->recoverQueuedWebhookDeliveries(limit: 10));
        $run = PipelineRun::query()->where('webhook_delivery_id', $webhook->id)->sole();
        $this->assertSame(PipelineRun::STATUS_QUEUED, $run->status);
        $this->assertSame(WebhookDelivery::STATUS_PROCESSED, $webhook->fresh()->status);
        Queue::assertPushed(RunPythonActorJob::class, 1);
        Queue::assertPushed(RunPythonActorJob::class, fn (RunPythonActorJob $job): bool =>
            $job->actorName === PythonActorRunner::ACTOR_HANDLE_DOCUMENT_PIPELINE
            && $job->commandId === $run->id
        );
        Queue::assertNotPushed(RunPythonActorJob::class, fn (RunPythonActorJob $job): bool =>
            $job->actorName === PythonActorRunner::ACTOR_HANDLE_PAPERLESS_WEBHOOK
        );
    }

    #[DataProvider('bigintSourceFixtures')]
    public function test_upgrade_token_is_fixed_length_at_bigint_maxima(string $column, string $sourceTable): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL upgrade fixture requires pgsql.');
        }

        $migration = require database_path('migrations/2026_07_19_000000_add_actor_execution_fencing.php');
        $migration->down();
        $maximum = 9223372036854775807;

        if ($sourceTable === 'commands') {
            DB::table($sourceTable)->insert([
                'id' => $maximum, 'type' => Command::TYPE_REINDEX, 'status' => 'pending',
                'payload' => '{}', 'created_at' => now(), 'updated_at' => now(),
            ]);
        } elseif ($sourceTable === 'pipeline_runs') {
            DB::table($sourceTable)->insert([
                'id' => $maximum, 'type' => 'document', 'status' => 'pending',
                'scope' => 'single_document', 'trigger_source' => 'manual',
                'paperless_document_id' => 1, 'pipeline_dedupe_key' => "bigint-{$column}",
                'coalesced_sources' => '[]', 'created_at' => now(), 'updated_at' => now(),
            ]);
        } else {
            DB::table($sourceTable)->insert([
                'id' => $maximum, 'source' => 'paperless', 'event_type' => 'document_updated',
                'paperless_document_id' => 1, 'dedupe_key' => "bigint-{$column}",
                'payload_hash' => hash('sha256', $column), 'raw_payload' => '{}',
                'normalized_payload' => '{"webhook_action":"refresh_embedding"}', 'headers' => '{}',
                'status' => 'received', 'request_id' => "bigint-{$column}",
                'received_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('actor_executions')->insert([
            'id' => $maximum, $column => $maximum, 'actor_name' => 'bigint_fixture',
            'status' => 'pending', 'attempt' => 2147483647, 'max_attempts' => 2147483647,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration->up();

        $expected = $this->migrationToken($column, $maximum, $maximum);
        $sourceToken = DB::table($sourceTable)->where('id', $maximum)->value('active_actor_token');
        $execution = DB::table('actor_executions')->where('id', $maximum)->first();
        $this->assertSame($expected, $sourceToken);
        $this->assertSame($expected, $execution->execution_token);
        $this->assertSame('retrying', $execution->status);
        $this->assertSame(45, strlen($execution->execution_token));
    }

    public static function bigintSourceFixtures(): array
    {
        return [
            'command' => ['command_id', 'commands'],
            'pipeline' => ['pipeline_run_id', 'pipeline_runs'],
            'webhook' => ['webhook_delivery_id', 'webhook_deliveries'],
        ];
    }

    private function migrationToken(string $column, int $sourceId, int $executionId): string
    {
        return 'migration-v1-'.md5("actor-execution:{$column}:{$sourceId}:{$executionId}");
    }
}
