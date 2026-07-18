<?php

namespace Tests\Feature\Admin;

use App\Jobs\RunPythonActorJob;
use App\Models\ActorExecution;
use App\Models\AuditLog;
use App\Models\Command;
use App\Models\PipelineRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_is_forbidden_from_admin_maintenance_page(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->get(route('admin.maintenance.index'))
            ->assertForbidden();
    }

    public function test_admin_ui_reset_uses_shared_backend_with_confirmation_and_audit(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Command::query()->create(['type' => Command::TYPE_REINDEX, 'status' => Command::STATUS_SUCCEEDED, 'payload' => []]);

        $this->actingAs($admin)->post(route('admin.maintenance.reset'), ['confirmation' => 'wrong'])
            ->assertSessionHasErrors('confirmation');
        $this->assertSame(1, Command::query()->count());

        $this->actingAs($admin)->post(route('admin.maintenance.reset'), ['confirmation' => 'RESET'])
            ->assertRedirect(route('admin.maintenance.index'));
        $this->assertSame(0, Command::query()->count());
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->id,
            'event' => 'maintenance.reset_completed',
            'target_type' => 'system',
        ]);
    }

    public function test_non_admin_cannot_use_ui_reset(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)->post(route('admin.maintenance.reset'), ['confirmation' => 'RESET'])
            ->assertForbidden();
    }

    public function test_recovery_now_runs_pipeline_actor_recovery_and_writes_audit_log(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $execution = ActorExecution::query()->create([
            'actor_name' => 'test_actor',
            'status' => ActorExecution::STATUS_RUNNING,
            'attempt' => 5,
            'max_attempts' => 5,
            'started_at' => now()->subMinutes(30),
        ]);
        $run = PipelineRun::query()->create([
            'type' => 'document',
            'status' => PipelineRun::STATUS_CANCEL_REQUESTED,
            'scope' => 'single_document',
            'trigger_source' => 'manual',
            'paperless_document_id' => 42,
        ]);
        $command = Command::query()->create([
            'type' => Command::TYPE_REINDEX,
            'status' => Command::STATUS_PENDING,
            'payload' => [],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.maintenance.recover-pipeline-actors'))
            ->assertRedirect();

        $this->assertSame(ActorExecution::STATUS_FAILED_PERMANENT, $execution->fresh()->status);
        $this->assertSame(PipelineRun::STATUS_CANCELLED, $run->fresh()->status);
        $this->assertSame(Command::STATUS_QUEUED, $command->fresh()->status);
        Queue::assertPushed(RunPythonActorJob::class, fn (RunPythonActorJob $job): bool => $job->actorName === 'reindex'
            && $job->commandId === $command->id);

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->id,
            'event' => 'maintenance.pipeline_recovery_requested',
            'target_type' => 'pipeline_recovery',
            'target_id' => 'scan',
        ]);
    }

    public function test_recovery_reports_when_another_scan_holds_the_lock(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $lock = Cache::lock('archibot:pipeline-recovery-scan', 60);
        $this->assertTrue($lock->get());

        try {
            $this->actingAs($admin)
                ->post(route('admin.maintenance.recover-pipeline-actors'))
                ->assertRedirect()
                ->assertSessionHas('status', 'Durable pipeline recovery skipped because another scan is active.');
        } finally {
            $lock->release();
        }
    }

    public function test_maintenance_controls_route_all_productive_actions_to_commands(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->post(route('admin.maintenance.commands'), ['type' => 'poll'])
            ->assertRedirect();
        $this->actingAs($admin)
            ->post(route('admin.maintenance.commands'), ['type' => 'reindex'])
            ->assertRedirect();
        $this->actingAs($admin)
            ->post(route('admin.maintenance.commands'), ['type' => 'reindex_embed'])
            ->assertRedirect();
        $this->actingAs($admin)
            ->post(route('admin.maintenance.commands'), ['type' => 'reindex_ocr', 'force' => '1'])
            ->assertRedirect();

        $this->assertSame(1, Command::query()->where('type', Command::TYPE_POLL_RECONCILIATION)->count());
        $this->assertSame(1, Command::query()->where('type', Command::TYPE_REINDEX)->count());
        $this->assertSame(1, Command::query()->where('type', Command::TYPE_EMBEDDING_INDEX_BUILD)->count());
        $this->assertSame(1, Command::query()->where('type', Command::TYPE_REINDEX_OCR)->count());
        $ocrCommand = Command::query()->where('type', Command::TYPE_REINDEX_OCR)->firstOrFail();
        $this->assertTrue($ocrCommand->payload['force']);

        Queue::assertPushed(RunPythonActorJob::class, 4);
        $this->assertSame(1, AuditLog::query()->where('event', 'maintenance.ocr_reindex_requested')->count());
    }

    public function test_cli_maintenance_commands_use_the_same_durable_backend_as_ui_controls(): void
    {
        Queue::fake();

        $cases = [
            ['poll', false, Command::TYPE_POLL_RECONCILIATION],
            ['poll', true, Command::TYPE_POLL_RECONCILIATION],
            ['reindex', false, Command::TYPE_REINDEX],
            ['reindex_embed', false, Command::TYPE_EMBEDDING_INDEX_BUILD],
            ['reindex_ocr', true, Command::TYPE_REINDEX_OCR],
        ];

        foreach ($cases as [$type, $force, $expectedType]) {
            $arguments = ['type' => $type];
            if ($force) {
                $arguments['--force'] = true;
            }
            $this->artisan('archibot:maintenance-command', $arguments)->assertSuccessful();

            $command = Command::query()->latest('id')->firstOrFail();
            $this->assertSame($expectedType, $command->type);
            $this->assertSame(Command::STATUS_QUEUED, $command->status);
            $this->assertSame('cli', $command->payload['source']);
            $this->assertNull($command->created_by_user_id);
            if ($type === 'poll' || $type === 'reindex_ocr') {
                $this->assertSame($force, $command->payload['force']);
            }
        }

        $this->assertSame(1, AuditLog::query()->where('event', 'maintenance.ocr_reindex_requested')->count());
        Queue::assertPushed(RunPythonActorJob::class, count($cases));
    }

    public function test_cli_maintenance_command_starts_manual_document_pipeline(): void
    {
        Queue::fake();

        $this->artisan('archibot:maintenance-command', [
            'type' => 'process_document',
            '--document-id' => 42,
            '--force' => true,
        ])->assertSuccessful();

        $run = PipelineRun::query()->firstOrFail();
        $this->assertSame('manual', $run->trigger_source);
        $this->assertSame(42, $run->paperless_document_id);
        $this->assertTrue($run->reprocess_requested);
        $this->assertSame('manual_force', $run->reprocess_reason);
        $this->assertSame('manual', $run->reprocess_mode);
        $this->assertNull($run->requested_by_user_id);
    }

    public function test_admin_can_start_manual_document_pipeline_from_maintenance(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->post(route('admin.maintenance.document-pipeline'), [
                'paperless_document_id' => 42,
                'force' => '1',
            ])
            ->assertRedirect();

        $run = PipelineRun::query()->firstOrFail();
        $this->assertSame('manual', $run->trigger_source);
        $this->assertSame(42, $run->paperless_document_id);
        $this->assertTrue($run->reprocess_requested);
        $this->assertSame('manual_force', $run->reprocess_reason);
        $this->assertSame('manual', $run->reprocess_mode);
        $this->assertSame($admin->id, $run->requested_by_user_id);

    }

    public function test_non_admin_cannot_start_manual_document_pipeline_from_maintenance(): void
    {
        Queue::fake();
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->post(route('admin.maintenance.document-pipeline'), [
                'paperless_document_id' => 42,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('pipeline_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_non_admin_cannot_dispatch_maintenance_command(): void
    {
        Queue::fake();
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->post(route('admin.maintenance.commands'), ['type' => 'poll'])
            ->assertForbidden();

        $this->assertDatabaseCount('commands', 0);
        Queue::assertNothingPushed();
    }

    public function test_cli_reset_clears_durable_operational_state(): void
    {
        Command::query()->create([
            'type' => Command::TYPE_REINDEX,
            'status' => Command::STATUS_FAILED,
        ]);

        $this->assertDatabaseCount('commands', 1);

        $this->artisan('archibot:reset', ['--yes' => true])
            ->expectsOutput('Archibot Laravel reset complete.')
            ->assertSuccessful();

        $this->assertDatabaseCount('commands', 0);
        $audit = AuditLog::query()->where('event', 'maintenance.reset_completed')->firstOrFail();
        $this->assertNull($audit->actor_user_id);
        $this->assertSame('local_operator', $audit->metadata['actor_principal']);
    }
}
