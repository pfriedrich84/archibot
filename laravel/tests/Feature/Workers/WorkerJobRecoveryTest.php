<?php

namespace Tests\Feature\Workers;

use App\Jobs\RunPythonWorkerJob;
use App\Models\AppSetting;
use App\Models\WorkerJob;
use App\Services\Workers\WorkerJobRecovery;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WorkerJobRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_redispatch_default_is_conservative_and_documented(): void
    {
        $this->assertSame(900, (int) config('archibot_workers.pending_redispatch_seconds'));
        $this->assertStringContainsString('| `ARCHIBOT_PENDING_REDISPATCH_SECONDS` | `900` |', file_get_contents(base_path('../docs/developer/worker-jobs.md')));
    }

    public function test_redispatches_queued_job_with_null_dispatched_at(): void
    {
        Queue::fake();

        $job = WorkerJob::factory()->create([
            'status' => WorkerJob::STATUS_QUEUED,
            'dispatch_attempts' => 0,
            'dispatched_at' => null,
        ]);

        $count = app(WorkerJobRecovery::class)->redispatchStaleQueued(30);

        $this->assertSame(1, $count);
        $this->assertSame(1, $job->refresh()->dispatch_attempts);
        $this->assertNotNull($job->dispatched_at);
        $this->assertSame('Queued worker job was redispatched by recovery.', $job->logs()->firstOrFail()->message);
        Queue::assertPushed(RunPythonWorkerJob::class, fn (RunPythonWorkerJob $queued) => $queued->workerJobId === $job->id);
    }

    public function test_stale_queued_job_is_not_redispatched_again_on_next_recovery_tick(): void
    {
        Queue::fake();

        $job = WorkerJob::factory()->create([
            'status' => WorkerJob::STATUS_QUEUED,
            'dispatch_attempts' => 1,
            'dispatched_at' => now()->subSeconds(31),
        ]);

        $firstCount = app(WorkerJobRecovery::class)->redispatchStaleQueued(30);
        $secondCount = app(WorkerJobRecovery::class)->redispatchStaleQueued(30);

        $this->assertSame(1, $firstCount);
        $this->assertSame(0, $secondCount);
        $job->refresh();
        $this->assertSame(2, $job->dispatch_attempts);
        $this->assertSame(1, $job->logs()->where('event', 'worker_job.redispatched')->count());
        Queue::assertPushed(RunPythonWorkerJob::class, 1);
    }

    public function test_does_not_redispatch_fresh_queued_job(): void
    {
        Queue::fake();

        $job = WorkerJob::factory()->create([
            'status' => WorkerJob::STATUS_QUEUED,
            'dispatch_attempts' => 1,
            'dispatched_at' => now(),
        ]);

        $count = app(WorkerJobRecovery::class)->redispatchStaleQueued(30);

        $this->assertSame(0, $count);
        $this->assertSame(1, $job->refresh()->dispatch_attempts);
        Queue::assertNothingPushed();
    }

    public function test_queued_job_redispatches_again_only_after_backoff_window(): void
    {
        Queue::fake();
        Config::set('archibot_workers.max_dispatch_attempts', 5);

        $job = WorkerJob::factory()->create([
            'status' => WorkerJob::STATUS_QUEUED,
            'dispatch_attempts' => 2,
            'dispatched_at' => now()->subSeconds(61),
        ]);

        $count = app(WorkerJobRecovery::class)->redispatchStaleQueued(30);

        $this->assertSame(1, $count);
        $this->assertSame(3, $job->refresh()->dispatch_attempts);
        Queue::assertPushed(RunPythonWorkerJob::class, fn (RunPythonWorkerJob $queued) => $queued->workerJobId === $job->id);
    }

    public function test_fails_queued_job_when_dispatch_attempts_are_exhausted(): void
    {
        Queue::fake();
        Config::set('archibot_workers.max_dispatch_attempts', 2);

        $job = WorkerJob::factory()->create([
            'status' => WorkerJob::STATUS_QUEUED,
            'dispatch_attempts' => 2,
            'dispatched_at' => now()->subHour(),
        ]);

        $summary = app(WorkerJobRecovery::class)->recoverAll(30, 10);

        $this->assertSame(0, $summary['redispatched_queued']);
        $this->assertSame(1, $summary['failed_queued']);
        $job->refresh();
        $this->assertSame(WorkerJob::STATUS_FAILED, $job->status);
        $this->assertSame('queued_dispatch_attempts_exhausted', $job->error);
        $this->assertSame('queued_dispatch_attempts_exhausted', $job->progress['reason']);
        $this->assertSame('worker_job.queued_dispatch_failed', $job->logs()->firstOrFail()->event);
        $this->assertNotNull($job->finished_at);
        Queue::assertNothingPushed();
    }

    public function test_requeues_stale_running_job_when_dispatch_attempts_are_below_configured_max(): void
    {
        Queue::fake();
        Config::set('archibot_workers.max_dispatch_attempts', 3);

        $job = WorkerJob::factory()->create([
            'status' => WorkerJob::STATUS_RUNNING,
            'dispatch_attempts' => 1,
            'worker_id' => 'lost-worker',
            'lease_expires_at' => now()->subMinute(),
            'heartbeat_at' => now()->subMinutes(20),
        ]);

        $summary = app(WorkerJobRecovery::class)->recoverStaleRunning(10);

        $this->assertSame(['requeued_running' => 1, 'failed_running' => 0], $summary);
        $job->refresh();
        $this->assertSame(WorkerJob::STATUS_QUEUED, $job->status);
        $this->assertSame(2, $job->dispatch_attempts);
        $this->assertNull($job->worker_id);
        $this->assertNull($job->lease_expires_at);
        $this->assertNull($job->heartbeat_at);
        Queue::assertPushed(RunPythonWorkerJob::class, fn (RunPythonWorkerJob $queued) => $queued->workerJobId === $job->id);
    }

    public function test_fails_stale_running_job_when_dispatch_attempts_are_exhausted(): void
    {
        Queue::fake();
        Config::set('archibot_workers.max_dispatch_attempts', 2);

        $job = WorkerJob::factory()->create([
            'status' => WorkerJob::STATUS_RUNNING,
            'dispatch_attempts' => 2,
            'worker_id' => 'lost-worker',
            'lease_expires_at' => now()->subMinute(),
            'heartbeat_at' => now()->subMinutes(20),
        ]);

        $summary = app(WorkerJobRecovery::class)->recoverStaleRunning(10);

        $this->assertSame(['requeued_running' => 0, 'failed_running' => 1], $summary);
        $job->refresh();
        $this->assertSame(WorkerJob::STATUS_FAILED, $job->status);
        $this->assertSame('stale_running_timeout', $job->error);
        $this->assertSame('stale_running_timeout', $job->progress['reason']);
        $this->assertNotNull($job->finished_at);
        $this->assertNull($job->worker_id);
        $this->assertNull($job->lease_expires_at);
        Queue::assertNothingPushed();
    }

    public function test_stale_cancelling_job_becomes_cancelled(): void
    {
        $job = WorkerJob::factory()->create([
            'status' => WorkerJob::STATUS_CANCELLING,
            'cancellation_requested_at' => now()->subMinutes(31),
            'updated_at' => now()->subMinutes(31),
        ]);

        $count = app(WorkerJobRecovery::class)->cancelStaleCancelling(30);

        $this->assertSame(1, $count);
        $this->assertSame(WorkerJob::STATUS_CANCELLED, $job->refresh()->status);
    }

    public function test_recover_command_prints_summary_and_tracks_success(): void
    {
        Queue::fake();
        AppSetting::put('worker_jobs.recovery.last_error', 'previous failure');

        WorkerJob::factory()->create([
            'status' => WorkerJob::STATUS_QUEUED,
            'dispatch_attempts' => 0,
            'dispatched_at' => null,
        ]);

        $this->artisan('worker-jobs:recover', ['--pending-seconds' => 30, '--running-minutes' => 10])
            ->expectsOutput('Worker job recovery summary:')
            ->expectsOutput('Redispatched queued: 1')
            ->expectsOutput('Failed queued: 0')
            ->expectsOutput('Requeued running: 0')
            ->expectsOutput('Failed running: 0')
            ->expectsOutput('Cancelled cancelling: 0')
            ->assertSuccessful();

        $this->assertNotNull(AppSetting::getValue('worker_jobs.recovery.last_successful_at'));
        $this->assertNull(AppSetting::getValue('worker_jobs.recovery.last_error'));
    }

    public function test_recover_command_tracks_failure(): void
    {
        $recovery = \Mockery::mock(WorkerJobRecovery::class);
        $recovery->shouldReceive('recoverAll')
            ->once()
            ->andThrow(new Exception('database unavailable'));

        $this->app->instance(WorkerJobRecovery::class, $recovery);

        $this->artisan('worker-jobs:recover')
            ->expectsOutput('Worker job recovery failed: database unavailable')
            ->assertFailed();

        $this->assertSame('database unavailable', AppSetting::getValue('worker_jobs.recovery.last_error'));
        $this->assertNotNull(AppSetting::getValue('worker_jobs.recovery.last_error_at'));
    }

    public function test_recover_command_dry_run_does_not_dispatch_or_change_jobs(): void
    {
        Queue::fake();
        Config::set('archibot_workers.max_dispatch_attempts', 2);

        $redispatchableJob = WorkerJob::factory()->create([
            'status' => WorkerJob::STATUS_QUEUED,
            'dispatch_attempts' => 0,
            'dispatched_at' => null,
        ]);
        $exhaustedJob = WorkerJob::factory()->create([
            'status' => WorkerJob::STATUS_QUEUED,
            'dispatch_attempts' => 2,
            'dispatched_at' => now()->subHour(),
        ]);

        $this->artisan('worker-jobs:recover', ['--pending-seconds' => 30, '--dry-run' => true])
            ->expectsOutput('Dry run: no worker jobs will be changed or dispatched.')
            ->expectsOutput('Redispatched queued: 1')
            ->expectsOutput('Failed queued: 1')
            ->assertSuccessful();

        $this->assertSame(0, $redispatchableJob->refresh()->dispatch_attempts);
        $this->assertNull($redispatchableJob->dispatched_at);
        $this->assertSame(WorkerJob::STATUS_QUEUED, $exhaustedJob->refresh()->status);
        Queue::assertNothingPushed();
    }
}
