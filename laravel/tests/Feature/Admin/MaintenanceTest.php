<?php

namespace Tests\Feature\Admin;

use App\Jobs\RunPythonActorJob;
use App\Jobs\RunPythonWorkerJob;
use App\Models\AuditLog;
use App\Models\Command;
use App\Models\User;
use App\Models\WorkerJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
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

    public function test_maintenance_page_no_longer_exposes_reset_controls(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.maintenance.index'))
            ->assertOk()
            ->assertDontSee('Queue database reset')
            ->assertDontSee('RESET CONFIG');

        $this->assertStringNotContainsString('reset.form', file_get_contents(resource_path('js/pages/admin/Maintenance.svelte')));
    }

    public function test_gui_reset_route_is_removed_for_admin_and_non_admin_users(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($admin)
            ->post('/admin/maintenance/reset', ['confirmation' => 'RESET'])
            ->assertNotFound();

        $this->actingAs($user)
            ->post('/admin/maintenance/reset', ['confirmation' => 'RESET'])
            ->assertNotFound();

        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'maintenance.reset_requested',
        ]);
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

    public function test_maintenance_controls_route_all_productive_actions_to_commands(): void
    {
        Queue::fake();
        Config::set('archibot.absurd_database_url', '');
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->post(route('admin.maintenance.worker-jobs'), ['type' => WorkerJob::TYPE_POLL])
            ->assertRedirect();
        $this->actingAs($admin)
            ->post(route('admin.maintenance.worker-jobs'), ['type' => WorkerJob::TYPE_REINDEX])
            ->assertRedirect();
        $this->actingAs($admin)
            ->post(route('admin.maintenance.worker-jobs'), ['type' => WorkerJob::TYPE_REINDEX_EMBED])
            ->assertRedirect();
        $this->actingAs($admin)
            ->post(route('admin.maintenance.worker-jobs'), ['type' => WorkerJob::TYPE_REINDEX_OCR, 'force' => '1'])
            ->assertRedirect();

        $this->assertSame(1, Command::query()->where('type', Command::TYPE_POLL_RECONCILIATION)->count());
        $this->assertSame(1, Command::query()->where('type', Command::TYPE_REINDEX)->count());
        $this->assertSame(1, Command::query()->where('type', Command::TYPE_EMBEDDING_INDEX_BUILD)->count());
        $this->assertSame(1, Command::query()->where('type', Command::TYPE_REINDEX_OCR)->count());
        $ocrCommand = Command::query()->where('type', Command::TYPE_REINDEX_OCR)->firstOrFail();
        $this->assertTrue($ocrCommand->payload['force']);
        $this->assertSame(WorkerJob::TYPE_REINDEX_OCR, $ocrCommand->payload['legacy_worker_job_action']);

        $this->assertDatabaseCount('worker_jobs', 0);
        Queue::assertPushed(RunPythonActorJob::class, 4);
        Queue::assertNotPushed(RunPythonWorkerJob::class);
        $this->assertSame(1, AuditLog::query()->where('event', 'maintenance.ocr_reindex_requested')->count());
    }

    public function test_non_admin_cannot_dispatch_maintenance_worker_job(): void
    {
        Queue::fake();
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->post(route('admin.maintenance.worker-jobs'), ['type' => WorkerJob::TYPE_POLL])
            ->assertForbidden();

        $this->assertDatabaseCount('worker_jobs', 0);
        Queue::assertNothingPushed();
    }

    public function test_cli_reset_clears_worker_job_state(): void
    {
        $workerJob = WorkerJob::factory()->create([
            'status' => WorkerJob::STATUS_FAILED,
        ]);
        $workerJob->logs()->create([
            'stream' => 'stdout',
            'level' => 'error',
            'event' => 'test_log',
            'message' => 'old log',
            'context' => [],
        ]);

        $this->assertDatabaseCount('worker_jobs', 1);
        $this->assertDatabaseCount('worker_job_logs', 1);

        $this->artisan('archibot:reset', ['--yes' => true])
            ->expectsOutput('Archibot Laravel reset complete.')
            ->assertSuccessful();

        $this->assertDatabaseCount('worker_jobs', 0);
        $this->assertDatabaseCount('worker_job_logs', 0);
    }
}
