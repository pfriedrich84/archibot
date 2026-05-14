<?php

namespace Tests\Feature\Workers;

use App\Jobs\RunPythonWorkerJob;
use App\Models\ReviewSuggestion;
use App\Models\User;
use App\Models\WorkerJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkerJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_users_can_view_worker_jobs(): void
    {
        $user = User::factory()->create();
        $job = WorkerJob::factory()->create([
            'type' => WorkerJob::TYPE_REINDEX,
            'status' => WorkerJob::STATUS_SUCCEEDED,
            'result' => ['ingest' => ['review_suggestions_imported' => 1]],
        ]);
        $suggestion = ReviewSuggestion::factory()->create([
            'worker_job_id' => $job->id,
            'paperless_document_id' => 123,
            'proposed_title' => 'Imported invoice',
        ]);

        $this->actingAs($user)
            ->get(route('worker-jobs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('worker/Index')
                ->where('jobs.data.0.id', $job->id)
                ->where('jobs.data.0.type', WorkerJob::TYPE_REINDEX)
                ->where('jobs.data.0.ingest.review_suggestions_imported', 1)
                ->where('jobs.data.0.review_suggestions_count', 1)
                ->where('jobs.data.0.review_suggestions.0.id', $suggestion->id)
                ->where('jobs.data.0.review_suggestions.0.proposed_title', 'Imported invoice')
                ->where('allowedTypes.0', WorkerJob::TYPE_POLL)
                ->where('readiness.queued', 0)
                ->where('readiness.running', 0)
                ->where('readiness.failed', 0)
            );
    }

    public function test_queueing_worker_job_creates_record_dispatches_laravel_job_and_audit_log(): void
    {
        Queue::fake();
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)
            ->post(route('worker-jobs.store'), ['type' => WorkerJob::TYPE_POLL])
            ->assertRedirect(route('worker-jobs.index'));

        $workerJob = WorkerJob::query()->firstOrFail();
        $this->assertSame(WorkerJob::TYPE_POLL, $workerJob->type);
        $this->assertSame(WorkerJob::STATUS_QUEUED, $workerJob->status);
        $this->assertSame($user->id, $workerJob->created_by_user_id);
        $this->assertNotNull($workerJob->dispatch_key);
        $this->assertSame(1, $workerJob->dispatch_attempts);
        $this->assertNotNull($workerJob->dispatched_at);
        Queue::assertPushed(RunPythonWorkerJob::class, fn (RunPythonWorkerJob $job) => $job->workerJobId === $workerJob->id);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $user->id,
            'event' => 'worker_job.queued',
            'target_id' => (string) $workerJob->id,
        ]);
    }

    public function test_index_auto_cancels_stale_cancelling_jobs(): void
    {
        Config::set('archibot_workers.stale_cancelling_minutes', 30);
        $user = User::factory()->create();
        $job = WorkerJob::factory()->create([
            'type' => WorkerJob::TYPE_POLL,
            'status' => WorkerJob::STATUS_CANCELLING,
            'cancellation_requested_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
            'progress' => ['phase' => 'polling'],
        ]);

        $this->actingAs($user)
            ->get(route('worker-jobs.index'))
            ->assertOk();

        $job->refresh();
        $this->assertSame(WorkerJob::STATUS_CANCELLED, $job->status);
        $this->assertNotNull($job->finished_at);
        $this->assertSame('Worker job was force-cancelled after stale cancellation timeout.', $job->logs()->firstOrFail()->message);
    }

    public function test_cancel_stale_worker_jobs_command_unblocks_old_cancelling_jobs(): void
    {
        $stale = WorkerJob::factory()->create([
            'status' => WorkerJob::STATUS_CANCELLING,
            'cancellation_requested_at' => now()->subMinutes(31),
            'updated_at' => now()->subMinutes(31),
        ]);
        $fresh = WorkerJob::factory()->create([
            'status' => WorkerJob::STATUS_CANCELLING,
            'cancellation_requested_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        $this->artisan('worker-jobs:cancel-stale', ['--minutes' => 30])
            ->expectsOutput('Cancelled 1 stale worker job(s).')
            ->assertSuccessful();

        $this->assertSame(WorkerJob::STATUS_CANCELLED, $stale->refresh()->status);
        $this->assertSame(WorkerJob::STATUS_CANCELLING, $fresh->refresh()->status);
    }

    public function test_queueing_duplicate_active_worker_job_reuses_existing_dispatch(): void
    {
        Queue::fake();
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)->post(route('worker-jobs.store'), ['type' => WorkerJob::TYPE_POLL]);
        $this->actingAs($user)->post(route('worker-jobs.store'), ['type' => WorkerJob::TYPE_POLL]);

        $this->assertSame(1, WorkerJob::query()->count());
        Queue::assertPushed(RunPythonWorkerJob::class, 1);
    }

    public function test_retry_creates_new_dispatch_linked_to_original_job(): void
    {
        Queue::fake();
        $user = User::factory()->create(['is_admin' => true]);
        $original = WorkerJob::factory()->create([
            'type' => WorkerJob::TYPE_POLL,
            'status' => WorkerJob::STATUS_FAILED,
            'payload' => ['mode' => 'inbox'],
            'dispatch_key' => hash('sha256', 'existing'),
            'dispatch_attempts' => 1,
            'dispatched_at' => now()->subMinute(),
        ]);

        $this->actingAs($user)
            ->post(route('worker-jobs.retry', $original))
            ->assertRedirect(route('worker-jobs.index'));

        $retry = WorkerJob::query()
            ->where('id', '!=', $original->id)
            ->firstOrFail();

        $this->assertSame($original->id, $retry->retry_of_worker_job_id);
        $this->assertSame(WorkerJob::STATUS_QUEUED, $retry->status);
        $this->assertSame(1, $retry->dispatch_attempts);
        $this->assertNotNull($retry->dispatched_at);
        Queue::assertPushed(RunPythonWorkerJob::class, fn (RunPythonWorkerJob $job) => $job->workerJobId === $retry->id);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'worker_job.retried',
            'target_id' => (string) $retry->id,
        ]);
    }

    public function test_process_document_requires_document_id(): void
    {
        Queue::fake();
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)
            ->from(route('worker-jobs.index'))
            ->post(route('worker-jobs.store'), ['type' => WorkerJob::TYPE_PROCESS_DOCUMENT])
            ->assertRedirect(route('worker-jobs.index'))
            ->assertSessionHasErrors('paperless_document_id');

        Queue::assertNothingPushed();
    }
}
