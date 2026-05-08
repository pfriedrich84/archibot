<?php

namespace Tests\Feature\Workers;

use App\Jobs\RunPythonWorkerJob;
use App\Models\ReviewSuggestion;
use App\Models\User;
use App\Models\WorkerJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        Queue::assertPushed(RunPythonWorkerJob::class, fn (RunPythonWorkerJob $job) => $job->workerJobId === $workerJob->id);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $user->id,
            'event' => 'worker_job.queued',
            'target_id' => (string) $workerJob->id,
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
