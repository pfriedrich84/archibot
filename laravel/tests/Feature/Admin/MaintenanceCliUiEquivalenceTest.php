<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Command;
use App\Models\PipelineEvent;
use App\Models\PipelineRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MaintenanceCliUiEquivalenceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $uiPayload
     * @param  array<string, mixed>  $cliArguments
     * @param  array<int, string>  $eventTypes
     */
    #[DataProvider('commandCases')]
    public function test_cli_and_ui_commands_share_durable_outcome_progress_and_audit(
        array $uiPayload,
        array $cliArguments,
        string $commandType,
        string $auditEvent,
        array $eventTypes,
    ): void {
        Queue::fake();
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.maintenance.commands'), $uiPayload)->assertRedirect();
        $uiCommand = Command::query()->latest('id')->firstOrFail();

        $this->artisan('archibot:maintenance-command', $cliArguments)->assertSuccessful();
        $cliCommand = Command::query()->latest('id')->firstOrFail();

        foreach ([$uiCommand, $cliCommand] as $command) {
            $this->assertSame($commandType, $command->type);
            $this->assertSame(Command::STATUS_QUEUED, $command->status);
            $this->assertSame($eventTypes, PipelineEvent::query()
                ->where('command_id', $command->id)
                ->orderBy('id')
                ->pluck('event_type')
                ->all());
            $this->assertDatabaseHas('audit_logs', [
                'event' => $auditEvent,
                'target_id' => (string) $command->id,
            ]);
        }

        $this->assertSame($admin->id, $uiCommand->created_by_user_id);
        $this->assertNull($cliCommand->created_by_user_id);
        $this->assertSame('authenticated_user', PipelineEvent::query()
            ->where('command_id', $uiCommand->id)->oldest()->firstOrFail()->payload['actor_principal']);
        $this->assertSame('local_operator', PipelineEvent::query()
            ->where('command_id', $cliCommand->id)->oldest()->firstOrFail()->payload['actor_principal']);
        $this->assertSame('authenticated_user', AuditLog::query()
            ->where('target_id', (string) $uiCommand->id)->where('event', $auditEvent)->firstOrFail()->metadata['actor_principal']);
        $this->assertSame('local_operator', AuditLog::query()
            ->where('target_id', (string) $cliCommand->id)->where('event', $auditEvent)->firstOrFail()->metadata['actor_principal']);

        foreach (['force', 'limit'] as $option) {
            if (array_key_exists($option, $uiCommand->payload) || array_key_exists($option, $cliCommand->payload)) {
                $this->assertSame($uiCommand->payload[$option] ?? null, $cliCommand->payload[$option] ?? null);
            }
        }

        $ids = [$uiCommand->id, $cliCommand->id];
        $this->restartApplicationPreservingDatabase();

        $this->assertSame(2, Command::query()->whereIn('id', $ids)->where('status', Command::STATUS_QUEUED)->count());
        $this->assertSame(count($eventTypes) * 2, PipelineEvent::query()->whereIn('command_id', $ids)->count());
        $this->assertSame(2, AuditLog::query()->where('event', $auditEvent)->whereIn('target_id', array_map('strval', $ids))->count());
    }

    /** @return array<string, array{array<string, mixed>, array<string, mixed>, string, string, array<int, string>}> */
    public static function commandCases(): array
    {
        return [
            'poll' => [
                ['type' => 'poll', 'force' => false],
                ['type' => 'poll'],
                Command::TYPE_POLL_RECONCILIATION,
                'maintenance.poll_reconciliation_requested',
                ['job_control.poll_reconciliation_requested', 'job_control.poll_reconciliation_actor_queued'],
            ],
            'force poll' => [
                ['type' => 'poll', 'force' => true],
                ['type' => 'poll', '--force' => true],
                Command::TYPE_POLL_RECONCILIATION,
                'maintenance.poll_reconciliation_requested',
                ['job_control.poll_reconciliation_requested', 'job_control.poll_reconciliation_actor_queued'],
            ],
            'reindex' => [
                ['type' => 'reindex'],
                ['type' => 'reindex'],
                Command::TYPE_REINDEX,
                'maintenance.reindex_requested',
                ['job_control.reindex_requested', 'job_control.reindex_actor_queued'],
            ],
            'ocr reindex' => [
                ['type' => 'reindex_ocr', 'force' => true],
                ['type' => 'reindex_ocr', '--force' => true],
                Command::TYPE_REINDEX_OCR,
                'maintenance.ocr_reindex_requested',
                ['job_control.ocr_reindex_requested', 'job_control.ocr_reindex_actor_queued'],
            ],
            'embedding reindex' => [
                ['type' => 'reindex_embed'],
                ['type' => 'reindex_embed'],
                Command::TYPE_EMBEDDING_INDEX_BUILD,
                'embedding_index.build_requested',
                ['job_control.embedding_build_requested', 'job_control.embedding_build_actor_queued'],
            ],
        ];
    }

    public function test_cli_and_ui_reset_share_backend_outcome_audit_and_restart_state(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Command::query()->create(['type' => Command::TYPE_REINDEX, 'status' => Command::STATUS_FAILED, 'payload' => []]);

        $this->artisan('archibot:reset', ['--yes' => true])->assertSuccessful();
        $cliAudit = AuditLog::query()->where('event', 'maintenance.reset_completed')->firstOrFail();
        $this->assertSame('local_operator', $cliAudit->metadata['actor_principal']);
        $this->assertNull($cliAudit->actor_user_id);
        $cliCleared = $cliAudit->metadata['cleared_tables'];
        $this->assertDatabaseCount('commands', 0);

        Command::query()->create(['type' => Command::TYPE_REINDEX, 'status' => Command::STATUS_FAILED, 'payload' => []]);
        $this->actingAs($admin)->post(route('admin.maintenance.reset'), ['confirmation' => 'RESET'])->assertRedirect();
        $uiAudit = AuditLog::query()->where('event', 'maintenance.reset_completed')->firstOrFail();
        $this->assertSame('authenticated_user', $uiAudit->metadata['actor_principal']);
        $this->assertSame($admin->id, $uiAudit->actor_user_id);
        $this->assertSame($cliCleared, $uiAudit->metadata['cleared_tables']);
        $this->assertDatabaseCount('commands', 0);

        $auditId = $uiAudit->id;
        $this->restartApplicationPreservingDatabase();

        $this->assertDatabaseHas('audit_logs', [
            'id' => $auditId,
            'event' => 'maintenance.reset_completed',
            'actor_user_id' => $admin->id,
        ]);
        $this->assertDatabaseCount('commands', 0);
    }

    #[DataProvider('documentCases')]
    public function test_cli_and_ui_document_processing_share_restart_durable_progress_and_outcome(bool $force): void
    {
        Queue::fake();
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.maintenance.document-pipeline'), [
            'paperless_document_id' => 41,
            'force' => $force,
        ])->assertRedirect();
        $cliArguments = [
            'type' => 'process_document',
            '--document-id' => 42,
        ];
        if ($force) {
            $cliArguments['--force'] = true;
        }
        $this->artisan('archibot:maintenance-command', $cliArguments)->assertSuccessful();

        $runs = PipelineRun::query()->orderBy('id')->get();
        $this->assertCount(2, $runs);
        foreach ($runs as $run) {
            $this->assertSame(PipelineRun::STATUS_BLOCKED, $run->status);
            $this->assertSame('blocked', $run->progress_current_phase);
            $this->assertSame('Waiting for embedding index to complete.', $run->progress_message);
            $this->assertSame($force, $run->reprocess_requested);
            $this->assertSame($force ? 'manual_force' : null, $run->reprocess_reason);
            $this->assertSame($force ? 'manual' : null, $run->reprocess_mode);
            $this->assertSame('blocked', PipelineEvent::query()
                ->where('pipeline_run_id', $run->id)->where('event_type', 'pipeline.blocked.embedding_index_not_ready')
                ->firstOrFail()->payload['outcome']);
            $this->assertDatabaseHas('audit_logs', [
                'event' => 'maintenance.document_pipeline_requested',
                'target_id' => (string) $run->id,
            ]);
        }
        $this->assertSame($admin->id, $runs[0]->requested_by_user_id);
        $this->assertNull($runs[1]->requested_by_user_id);

        $ids = $runs->pluck('id')->all();
        $this->restartApplicationPreservingDatabase();

        $this->assertSame(2, PipelineRun::query()->whereIn('id', $ids)
            ->where('status', PipelineRun::STATUS_BLOCKED)
            ->where('progress_current_phase', 'blocked')->count());
        $this->assertSame(2, AuditLog::query()->where('event', 'maintenance.document_pipeline_requested')->count());
    }

    /** @return array<string, array{bool}> */
    public static function documentCases(): array
    {
        return [
            'normal document processing' => [false],
            'force document processing' => [true],
        ];
    }
}
