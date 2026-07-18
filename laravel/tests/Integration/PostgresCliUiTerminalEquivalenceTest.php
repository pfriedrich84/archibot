<?php

namespace Tests\Integration;

use App\Models\ActorExecution;
use App\Models\AuditLog;
use App\Models\Command;
use App\Models\EmbeddingIndexState;
use App\Models\PipelineEvent;
use App\Models\PipelineRun;
use App\Models\ReviewSuggestion;
use App\Models\User;
use App\Services\ArchibotResetService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * PostgreSQL-only cross-process acceptance coverage. Each producer request or
 * CLI subprocess commits before a fresh queue-worker process claims the jobs,
 * modelling the durable handoff across an application/worker restart.
 *
 * @group postgres
 */
class PostgresCliUiTerminalEquivalenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires the real PostgreSQL integration test service.');
        }

        // This suite intentionally avoids RefreshDatabase transactions: producer
        // and worker are separate processes and must observe committed rows.
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
        Config::set('queue.default', 'database');
        app(ArchibotResetService::class)->reset();
    }

    /** @dataProvider maintenanceCases */
    public function test_ui_and_cli_actor_commands_reach_equivalent_terminal_state_after_worker_restart(
        array $uiPayload,
        array $cliArguments,
        string $type,
        string $auditEvent,
    ): void {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->post(route('admin.maintenance.commands'), $uiPayload)->assertRedirect();
        $ui = Command::query()->latest('id')->firstOrFail();

        $this->artisanProcess(['archibot:maintenance-command', ...$cliArguments]);
        $cli = Command::query()->latest('id')->firstOrFail();
        $this->workerProcess(2);

        foreach ([$ui->fresh(), $cli->fresh()] as $command) {
            $this->assertSame($type, $command->type);
            $this->assertSame(Command::STATUS_SUCCEEDED, $command->status);
            $execution = ActorExecution::query()->where('command_id', $command->id)->latest()->firstOrFail();
            $this->assertSame(ActorExecution::STATUS_SUCCEEDED, $execution->status);
            $this->assertNotNull($execution->finished_at);
            $this->assertTrue(PipelineEvent::query()->where('command_id', $command->id)->exists());
            $audit = AuditLog::query()->where('event', $auditEvent)->where('target_id', (string) $command->id)->firstOrFail();
            $this->assertContains($audit->metadata['actor_principal'], ['authenticated_user', 'local_operator']);
            $this->assertSame($command->id, $audit->metadata['command_id']);
        }
        $this->assertSame($this->commandTerminalSnapshot($ui->fresh()), $this->commandTerminalSnapshot($cli->fresh()));
        $this->assertSame($ui->payload['force'] ?? null, $cli->payload['force'] ?? null);
        $this->assertSame($ui->payload['limit'] ?? null, $cli->payload['limit'] ?? null);
        $this->assertSame($this->commandDurableSideEffects($ui), $this->commandDurableSideEffects($cli));
        $this->assertSame(0, DB::table('jobs')->count(), 'fresh worker must consume both durable jobs');
    }

    /** @dataProvider maintenanceCases */
    public function test_ui_and_cli_actor_failures_are_terminally_equivalent_after_worker_restart(
        array $uiPayload,
        array $cliArguments,
        string $type,
        string $auditEvent,
    ): void {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->post(route('admin.maintenance.commands'), $uiPayload)->assertRedirect();
        $ui = Command::query()->latest('id')->firstOrFail();
        $this->artisanProcess(['archibot:maintenance-command', ...$cliArguments]);
        $cli = Command::query()->latest('id')->firstOrFail();

        $this->workerProcess(2, 'failed-permanent');

        foreach ([$ui->fresh(), $cli->fresh()] as $command) {
            $this->assertSame($type, $command->type);
            $this->assertSame(Command::STATUS_FAILED_PERMANENT, $command->status);
            $this->assertTrue(AuditLog::query()->where('event', $auditEvent)->where('target_id', (string) $command->id)->exists());
        }
        $this->assertSame($this->commandTerminalSnapshot($ui->fresh()), $this->commandTerminalSnapshot($cli->fresh()));
        $this->assertSame($this->commandDurableSideEffects($ui), $this->commandDurableSideEffects($cli));
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public static function maintenanceCases(): array
    {
        return [
            'poll' => [['type' => 'poll'], ['poll'], Command::TYPE_POLL_RECONCILIATION, 'maintenance.poll_reconciliation_requested'],
            'force poll' => [['type' => 'poll', 'force' => true], ['poll', '--force'], Command::TYPE_POLL_RECONCILIATION, 'maintenance.poll_reconciliation_requested'],
            'reindex' => [['type' => 'reindex'], ['reindex'], Command::TYPE_REINDEX, 'maintenance.reindex_requested'],
            'ocr reindex' => [['type' => 'reindex_ocr', 'force' => true], ['reindex_ocr', '--force'], Command::TYPE_REINDEX_OCR, 'maintenance.ocr_reindex_requested'],
            'embedding reindex' => [['type' => 'reindex_embed'], ['reindex_embed'], Command::TYPE_EMBEDDING_INDEX_BUILD, 'embedding_index.build_requested'],
        ];
    }

    public function test_process_document_ui_and_cli_reach_terminal_outcome_in_restarted_workers(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        EmbeddingIndexState::query()->create([
            'status' => EmbeddingIndexState::STATUS_COMPLETE,
            'embedding_model' => 'integration', 'dimensions' => 3,
            'content_scope' => 'trusted_without_inbox', 'completed_at' => now(),
            'document_count' => 0, 'embedded_count' => 0, 'failed_count' => 0,
        ]);
        $this->actingAs($admin)->post(route('admin.maintenance.document-pipeline'), ['paperless_document_id' => 901, 'force' => true])->assertRedirect();
        $ui = PipelineRun::query()->latest('id')->firstOrFail();
        $this->artisanProcess(['archibot:maintenance-command', 'process_document', '--document-id', '902', '--force']);
        $cli = PipelineRun::query()->latest('id')->firstOrFail();
        $this->workerProcess(2);

        foreach ([$ui->fresh(), $cli->fresh()] as $run) {
            $this->assertSame(PipelineRun::STATUS_SUCCEEDED, $run->status);
            $this->assertSame(ActorExecution::STATUS_SUCCEEDED, ActorExecution::query()->where('pipeline_run_id', $run->id)->latest()->firstOrFail()->status);
            $this->assertTrue(PipelineEvent::query()->where('pipeline_run_id', $run->id)->exists());
            $this->assertTrue(AuditLog::query()->where('event', 'maintenance.document_pipeline_requested')->where('target_id', (string) $run->id)->exists());
        }
        $this->assertSame($this->pipelineTerminalSnapshot($ui->fresh()), $this->pipelineTerminalSnapshot($cli->fresh()));
        $this->assertSame($this->pipelineDurableSideEffects($ui), $this->pipelineDurableSideEffects($cli));
        $this->assertTrue($ui->reprocess_requested);
        $this->assertTrue($cli->reprocess_requested);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_process_document_ui_and_cli_failures_are_terminally_equivalent_after_worker_restart(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        EmbeddingIndexState::query()->create([
            'status' => EmbeddingIndexState::STATUS_COMPLETE,
            'embedding_model' => 'integration', 'dimensions' => 3,
            'content_scope' => 'trusted_without_inbox', 'completed_at' => now(),
            'document_count' => 0, 'embedded_count' => 0, 'failed_count' => 0,
        ]);
        $this->actingAs($admin)->post(route('admin.maintenance.document-pipeline'), ['paperless_document_id' => 921, 'force' => true])->assertRedirect();
        $ui = PipelineRun::query()->latest('id')->firstOrFail();
        $this->artisanProcess(['archibot:maintenance-command', 'process_document', '--document-id', '922', '--force']);
        $cli = PipelineRun::query()->latest('id')->firstOrFail();
        $this->workerProcess(2, 'failed-permanent');

        foreach ([$ui->fresh(), $cli->fresh()] as $run) {
            $this->assertSame(PipelineRun::STATUS_FAILED_PERMANENT, $run->status);
            $this->assertSame(ActorExecution::STATUS_FAILED_PERMANENT, ActorExecution::query()->where('pipeline_run_id', $run->id)->latest()->firstOrFail()->status);
            $this->assertTrue(AuditLog::query()->where('event', 'maintenance.document_pipeline_requested')->where('target_id', (string) $run->id)->exists());
        }
        $this->assertSame($this->pipelineTerminalSnapshot($ui->fresh()), $this->pipelineTerminalSnapshot($cli->fresh()));
        $this->assertSame($this->pipelineDurableSideEffects($ui), $this->pipelineDurableSideEffects($cli));
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_review_commit_ui_and_cli_reach_terminal_outcome_in_restarted_workers(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $uiSuggestion = ReviewSuggestion::factory()->create(['paperless_document_id' => 910]);
        $cliSuggestion = ReviewSuggestion::factory()->create(['paperless_document_id' => 911]);
        $this->actingAs($admin)->post(route('review.accept', $uiSuggestion))->assertRedirect();
        $this->artisanProcess(['archibot:review-commit', (string) $cliSuggestion->id, '--user-id', (string) $admin->id]);
        $this->workerProcess(2);

        foreach ([$uiSuggestion->fresh(), $cliSuggestion->fresh()] as $suggestion) {
            $command = $suggestion->commitCommand()->firstOrFail();
            $this->assertSame(Command::STATUS_SUCCEEDED, $command->status);
            $this->assertSame(ActorExecution::STATUS_SUCCEEDED, ActorExecution::query()->where('command_id', $command->id)->latest()->firstOrFail()->status);
            $this->assertTrue(AuditLog::query()->where('event', 'review_suggestion.accepted')->where('target_id', (string) $suggestion->id)->exists());
        }
        $uiCommand = $uiSuggestion->fresh()->commitCommand()->firstOrFail();
        $cliCommand = $cliSuggestion->fresh()->commitCommand()->firstOrFail();
        $this->assertSame($this->commandTerminalSnapshot($uiCommand), $this->commandTerminalSnapshot($cliCommand));
        $this->assertSame($this->reviewDurableSideEffects($uiSuggestion, $uiCommand), $this->reviewDurableSideEffects($cliSuggestion, $cliCommand));
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_review_commit_ui_and_cli_failures_are_terminally_equivalent_after_worker_restart(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $uiSuggestion = ReviewSuggestion::factory()->create(['paperless_document_id' => 930]);
        $cliSuggestion = ReviewSuggestion::factory()->create(['paperless_document_id' => 931]);
        $this->actingAs($admin)->post(route('review.accept', $uiSuggestion))->assertRedirect();
        $this->artisanProcess(['archibot:review-commit', (string) $cliSuggestion->id, '--user-id', (string) $admin->id]);
        $this->workerProcess(2, 'failed-permanent');

        $uiCommand = $uiSuggestion->fresh()->commitCommand()->firstOrFail();
        $cliCommand = $cliSuggestion->fresh()->commitCommand()->firstOrFail();
        foreach ([$uiCommand, $cliCommand] as $command) {
            $this->assertSame(Command::STATUS_FAILED_PERMANENT, $command->status);
            $this->assertSame(ActorExecution::STATUS_FAILED_PERMANENT, ActorExecution::query()->where('command_id', $command->id)->latest()->firstOrFail()->status);
        }
        $this->assertSame($this->commandTerminalSnapshot($uiCommand), $this->commandTerminalSnapshot($cliCommand));
        $this->assertSame($this->reviewDurableSideEffects($uiSuggestion, $uiCommand), $this->reviewDurableSideEffects($cliSuggestion, $cliCommand));
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_reset_ui_and_cli_use_same_postgresql_side_effects_across_processes(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Command::query()->create(['type' => Command::TYPE_REINDEX, 'status' => Command::STATUS_FAILED, 'payload' => []]);
        $this->artisanProcess(['archibot:reset', '--yes']);
        $cli = AuditLog::query()->where('event', 'maintenance.reset_completed')->firstOrFail();
        $cliTables = $cli->metadata['cleared_tables'];

        Command::query()->create(['type' => Command::TYPE_REINDEX, 'status' => Command::STATUS_FAILED, 'payload' => []]);
        $this->actingAs($admin)->post(route('admin.maintenance.reset'), ['confirmation' => 'RESET'])->assertRedirect();
        $ui = AuditLog::query()->where('event', 'maintenance.reset_completed')->firstOrFail();
        $this->assertSame($cliTables, $ui->metadata['cleared_tables']);
        $this->assertSame('local_operator', $cli->metadata['actor_principal']);
        $this->assertSame('authenticated_user', $ui->metadata['actor_principal']);
        $this->assertNull($cli->actor_user_id);
        $this->assertSame($admin->id, $ui->actor_user_id);
        $this->assertDatabaseCount('commands', 0);
    }

    private function artisanProcess(array $arguments): void
    {
        $process = new Process([PHP_BINARY, 'artisan', ...$arguments], base_path(), $this->processEnvironment());
        $process->setTimeout(120);
        $process->mustRun();
    }

    /** @return array<string, mixed> */
    private function commandTerminalSnapshot(Command $command): array
    {
        $execution = ActorExecution::query()->where('command_id', $command->id)->latest()->firstOrFail();
        return [
            'command_status' => $command->status,
            'command_error' => $command->error,
            'finished' => $command->finished_at !== null,
            'execution_status' => $execution->status,
            'execution_error_type' => $execution->error_type,
            'progress' => [
                $execution->progress_total,
                $execution->progress_done,
                $execution->progress_failed,
                $execution->progress_skipped,
                $execution->progress_current_item,
                $execution->progress_message,
            ],
            'events' => PipelineEvent::query()->where('command_id', $command->id)
                ->orderBy('id')->pluck('event_type')->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function commandDurableSideEffects(Command $command): array
    {
        return [
            'actor_count' => ActorExecution::query()->where('command_id', $command->id)->count(),
            'terminal_actor_count' => ActorExecution::query()->where('command_id', $command->id)
                ->whereNotNull('finished_at')->count(),
            'event_levels' => PipelineEvent::query()->where('command_id', $command->id)
                ->orderBy('id')->pluck('level')->all(),
            'audit_events' => AuditLog::query()->where('target_id', (string) $command->id)
                ->orderBy('id')->pluck('event')->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function reviewDurableSideEffects(ReviewSuggestion $suggestion, Command $command): array
    {
        return [
            ...$this->commandDurableSideEffects($command),
            'suggestion_status' => $suggestion->fresh()->status,
            'commit_status' => $suggestion->fresh()->commit_status,
            'review_audits' => AuditLog::query()
                ->where('target_type', 'review_suggestion')
                ->where('target_id', (string) $suggestion->id)
                ->orderBy('id')->pluck('event')->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function pipelineTerminalSnapshot(PipelineRun $run): array
    {
        $execution = ActorExecution::query()->where('pipeline_run_id', $run->id)->latest()->firstOrFail();
        return [
            'run_status' => $run->status,
            'run_error_type' => $run->error_type,
            'phase' => $run->progress_current_phase,
            'progress' => [$run->progress_total, $run->progress_done, $run->progress_failed, $run->progress_skipped],
            'execution_status' => $execution->status,
            'execution_progress' => [$execution->progress_total, $execution->progress_done, $execution->progress_failed, $execution->progress_skipped],
            'events' => PipelineEvent::query()->where('pipeline_run_id', $run->id)
                ->orderBy('id')->pluck('event_type')->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function pipelineDurableSideEffects(PipelineRun $run): array
    {
        return [
            'actor_count' => ActorExecution::query()->where('pipeline_run_id', $run->id)->count(),
            'terminal_actor_count' => ActorExecution::query()->where('pipeline_run_id', $run->id)
                ->whereNotNull('finished_at')->count(),
            'event_levels' => PipelineEvent::query()->where('pipeline_run_id', $run->id)
                ->orderBy('id')->pluck('level')->all(),
            'audit_events' => AuditLog::query()->where('target_id', (string) $run->id)
                ->orderBy('id')->pluck('event')->all(),
        ];
    }

    private function workerProcess(int $jobs, string $scenario = 'success'): void
    {
        $process = new Process([PHP_BINARY, 'artisan', 'queue:work', 'database', '--stop-when-empty', '--max-jobs='.$jobs, '--tries=1'], base_path(), $this->processEnvironment($scenario));
        $process->setTimeout(180);
        $process->mustRun();
    }

    private function processEnvironment(string $scenario = 'success'): array
    {
        $fixture = base_path('tests/Fixtures/production_actor_process.py');
        chmod($fixture, 0755);
        $database = config('database.connections.pgsql');
        $user = rawurlencode((string) $database['username']);
        $password = rawurlencode((string) $database['password']);
        $host = (string) $database['host'];
        $port = (string) $database['port'];
        $name = rawurlencode((string) $database['database']);

        return [
            'APP_ENV' => 'testing',
            'QUEUE_CONNECTION' => 'database',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => $host,
            'DB_PORT' => $port,
            'DB_DATABASE' => (string) $database['database'],
            'DB_USERNAME' => (string) $database['username'],
            'DB_PASSWORD' => (string) $database['password'],
            'DATABASE_URL' => "postgresql+psycopg://{$user}:{$password}@{$host}:{$port}/{$name}",
            'ARCHIBOT_PYTHON_BINARY' => $fixture,
            'ARCHIBOT_ACTOR_FIXTURE_SCENARIO' => $scenario,
        ];
    }
}
