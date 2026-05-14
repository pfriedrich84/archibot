<?php

namespace Tests\Feature\Admin;

use App\Jobs\RunMaintenanceResetCommand;
use App\Jobs\RunPythonWorkerJob;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WorkerJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_wrong_reset_confirmation_is_rejected(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->from(route('admin.maintenance.index'))
            ->post(route('admin.maintenance.reset'), [
                'confirmation' => 'WRONG',
            ])
            ->assertRedirect(route('admin.maintenance.index'))
            ->assertSessionHasErrors('confirmation');

        Queue::assertNotPushed(RunMaintenanceResetCommand::class);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'maintenance.reset_requested',
        ]);
    }

    public function test_reset_database_queues_reset_job_and_writes_audit_log(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->post(route('admin.maintenance.reset'), [
                'confirmation' => 'RESET',
            ])
            ->assertRedirect();

        Queue::assertPushed(RunMaintenanceResetCommand::class, fn (RunMaintenanceResetCommand $job): bool => $job->includeConfig === false
            && $job->actorUserId === $admin->id);

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->id,
            'event' => 'maintenance.reset_requested',
            'target_type' => 'maintenance_reset',
            'target_id' => 'database',
        ]);
    }

    public function test_reset_with_config_requires_reset_config_confirmation(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->from(route('admin.maintenance.index'))
            ->post(route('admin.maintenance.reset'), [
                'include_config' => '1',
                'confirmation' => 'RESET',
            ])
            ->assertRedirect(route('admin.maintenance.index'))
            ->assertSessionHasErrors('confirmation');

        Queue::assertNotPushed(RunMaintenanceResetCommand::class);

        $this->actingAs($admin)
            ->post(route('admin.maintenance.reset'), [
                'include_config' => '1',
                'confirmation' => 'RESET CONFIG',
            ])
            ->assertRedirect();

        Queue::assertPushed(RunMaintenanceResetCommand::class, fn (RunMaintenanceResetCommand $job): bool => $job->includeConfig === true
            && $job->actorUserId === $admin->id);
    }

    public function test_recovery_now_runs_recovery_safely_and_writes_audit_log(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $job = WorkerJob::query()->create([
            'type' => WorkerJob::TYPE_POLL,
            'status' => WorkerJob::STATUS_QUEUED,
            'payload' => ['mode' => 'inbox'],
            'dispatch_key' => 'stale-queued',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.maintenance.recover-worker-jobs'))
            ->assertRedirect();

        Queue::assertPushed(RunPythonWorkerJob::class, fn (RunPythonWorkerJob $queued): bool => $queued->workerJobId === $job->id);

        $this->assertSame(1, $job->refresh()->dispatch_attempts);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->id,
            'event' => 'maintenance.worker_jobs_recovery_requested',
            'target_type' => 'worker_jobs',
            'target_id' => 'recovery',
        ]);
    }

    public function test_maintenance_controls_dispatch_worker_jobs_and_write_audit_logs(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['is_admin' => true]);

        $actions = [
            [WorkerJob::TYPE_POLL, ['mode' => 'inbox', 'force' => false]],
            [WorkerJob::TYPE_REINDEX, ['mode' => 'full']],
            [WorkerJob::TYPE_REINDEX_OCR, ['mode' => 'ocr', 'force' => true], ['force' => '1']],
            [WorkerJob::TYPE_REINDEX_EMBED, ['mode' => 'embed']],
        ];

        foreach ($actions as $action) {
            $type = $action[0];
            $expectedPayload = $action[1];
            $extraPayload = $action[2] ?? [];

            $this->actingAs($admin)
                ->post(route('admin.maintenance.worker-jobs'), [
                    'type' => $type,
                    ...$extraPayload,
                ])
                ->assertRedirect();

            $this->assertDatabaseHas('worker_jobs', [
                'type' => $type,
                'status' => WorkerJob::STATUS_QUEUED,
            ]);

            $workerJob = WorkerJob::query()->where('type', $type)->latest()->firstOrFail();
            $this->assertSame($expectedPayload, $workerJob->payload);
        }

        Queue::assertPushed(RunPythonWorkerJob::class, 4);
        $this->assertSame(4, WorkerJob::query()->count());
        $this->assertSame(4, AuditLog::query()->where('event', 'maintenance.worker_job_requested')->count());
    }

    public function test_non_admin_cannot_dispatch_maintenance_worker_job_or_reset(): void
    {
        Queue::fake();
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->post(route('admin.maintenance.worker-jobs'), ['type' => WorkerJob::TYPE_POLL])
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('admin.maintenance.reset'), ['confirmation' => 'RESET'])
            ->assertForbidden();

        $this->assertDatabaseCount('worker_jobs', 0);
        Queue::assertNothingPushed();
    }
}
