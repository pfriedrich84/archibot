<?php

namespace Tests\Feature\Workers;

use App\Models\User;
use App\Models\WorkerJob;
use App\Services\Workers\PythonWorkerCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WorkerJobCancellationTest extends TestCase
{
    use RefreshDatabase;

    public function test_stop_queued_job_marks_it_cancelled_and_logs(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $job = WorkerJob::factory()->create(['status' => WorkerJob::STATUS_QUEUED]);

        $this->actingAs($user)
            ->post(route('worker-jobs.stop', $job))
            ->assertRedirect(route('worker-jobs.index'));

        $job->refresh();
        $this->assertSame(WorkerJob::STATUS_CANCELLED, $job->status);
        $this->assertNotNull($job->cancellation_requested_at);
        $this->assertNotNull($job->finished_at);
        $this->assertTrue($job->progress['cancelled']);
        $this->assertSame('worker_job.cancelled', $job->logs()->firstOrFail()->event);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'worker_job.stop_requested',
            'target_id' => (string) $job->id,
        ]);
    }

    public function test_stop_running_job_marks_it_cancelling_with_kill_after_metadata(): void
    {
        Config::set('archibot_workers.cancel_grace_seconds', 45);
        $user = User::factory()->create(['is_admin' => true]);
        $job = WorkerJob::factory()->create([
            'status' => WorkerJob::STATUS_RUNNING,
            'worker_id' => 'worker-1',
            'lease_expires_at' => now()->addMinute(),
            'heartbeat_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('worker-jobs.stop', $job))
            ->assertRedirect(route('worker-jobs.index'));

        $job->refresh();
        $this->assertSame(WorkerJob::STATUS_CANCELLING, $job->status);
        $this->assertNotNull($job->cancellation_requested_at);
        $this->assertNotEmpty($job->progress['cancellation']['kill_after_at']);
        $this->assertSame('worker_job.cancel_requested', $job->logs()->firstOrFail()->event);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'worker_job.stop_requested',
            'target_id' => (string) $job->id,
        ]);
    }

    public function test_force_kill_marks_running_job_terminal(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $job = WorkerJob::factory()->create([
            'status' => WorkerJob::STATUS_CANCELLING,
            'worker_id' => 'worker-1',
            'lease_expires_at' => now()->addMinute(),
            'heartbeat_at' => now(),
            'cancellation_requested_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('worker-jobs.force-kill', $job))
            ->assertRedirect(route('worker-jobs.index'));

        $job->refresh();
        $this->assertSame(WorkerJob::STATUS_CANCELLED, $job->status);
        $this->assertNull($job->worker_id);
        $this->assertNull($job->lease_expires_at);
        $this->assertTrue($job->progress['force_killed_by_admin']);
        $this->assertStringContainsString('force_killed_by_admin', $job->error);
        $this->assertSame('worker_job.force_killed', $job->logs()->firstOrFail()->event);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'worker_job.force_killed',
            'target_id' => (string) $job->id,
        ]);
    }

    public function test_cancelling_worker_process_receives_sigint_once(): void
    {
        $job = WorkerJob::factory()->create([
            'status' => WorkerJob::STATUS_CANCELLING,
            'cancellation_requested_at' => now(),
            'progress' => [
                'cancellation' => [
                    'kill_after_at' => now()->addMinute()->toISOString(),
                ],
            ],
        ]);
        $process = new Process([PHP_BINARY, '-r', 'sleep(30);'], timeout: null);
        $process->start();

        try {
            $this->invokeSignalIfCancelling($job, $process);
            $firstSignalSentAt = $job->refresh()->progress['cancellation']['cancel_signal_sent_at'] ?? null;

            $this->assertNotEmpty($firstSignalSentAt);
            $this->assertSame('worker_job.cancel_signal_sent', $job->logs()->firstOrFail()->event);

            $this->invokeSignalIfCancelling($job, $process);

            $this->assertSame($firstSignalSentAt, $job->refresh()->progress['cancellation']['cancel_signal_sent_at']);
            $this->assertSame(1, $job->logs()->where('event', 'worker_job.cancel_signal_sent')->count());
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
    }

    public function test_cancelling_worker_process_is_stopped_after_grace_period(): void
    {
        $job = WorkerJob::factory()->create([
            'status' => WorkerJob::STATUS_CANCELLING,
            'cancellation_requested_at' => now()->subMinute(),
            'progress' => [
                'cancellation' => [
                    'cancel_signal_sent_at' => now()->subMinute()->toISOString(),
                    'kill_after_at' => now()->subSecond()->toISOString(),
                ],
            ],
        ]);
        $process = new Process([PHP_BINARY, '-r', 'sleep(30);'], timeout: null);
        $process->start();

        try {
            $this->invokeSignalIfCancelling($job, $process);

            $this->assertFalse($process->isRunning());
            $this->assertNotEmpty($job->refresh()->progress['cancellation']['forced_stop_at']);
            $this->assertSame('worker_job.cancel_force_stop', $job->logs()->firstOrFail()->event);
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
    }

    private function invokeSignalIfCancelling(WorkerJob $job, Process $process): void
    {
        $method = new \ReflectionMethod(PythonWorkerCommand::class, 'signalIfCancelling');
        $method->setAccessible(true);
        $method->invoke(app(PythonWorkerCommand::class), $job, $process);
    }
}
