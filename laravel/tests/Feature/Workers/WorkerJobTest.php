<?php

namespace Tests\Feature\Workers;

use App\Jobs\RunPythonWorkerJob;
use App\Models\Command;
use App\Models\EmbeddingIndexState;
use App\Models\PipelineRun;
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
                ->where('allowedTypes.1', WorkerJob::TYPE_PROCESS_DOCUMENT)
                ->where('allowedTypes.2', WorkerJob::TYPE_REINDEX)
                ->where('allowedTypes.3', WorkerJob::TYPE_REINDEX_OCR)
                ->where('allowedTypes.4', WorkerJob::TYPE_REINDEX_EMBED)
                ->where('quickControls.poll_url', route('maintenance.poll'))
                ->where('quickControls.reindex_url', route('maintenance.reindex'))
                ->where('quickControls.embedding_build_url', route('embedding-index.build'))
                ->where('quickControls.worker_job_store_url', route('worker-jobs.store'))
                ->where('readiness.queued', 0)
                ->where('readiness.running', 0)
                ->where('readiness.failed', 0)
            );
    }

    public function test_authenticated_users_can_view_worker_job_detail_with_logs(): void
    {
        $user = User::factory()->create();
        $job = WorkerJob::factory()->create([
            'type' => WorkerJob::TYPE_PROCESS_DOCUMENT,
            'status' => WorkerJob::STATUS_SUCCEEDED,
            'payload' => ['paperless_document_id' => 123],
            'progress' => ['phase' => 'review', 'done' => 1, 'total' => 1, 'failed' => 0],
            'result' => ['ingest' => ['review_suggestions_imported' => 1]],
            'dispatch_key' => hash('sha256', 'detail-test'),
            'dispatch_attempts' => 1,
            'dispatched_at' => now()->subMinutes(5),
            'worker_id' => 'worker-1',
            'lease_expires_at' => now()->addMinute(),
            'heartbeat_at' => now(),
        ]);
        $job->logs()->create([
            'stream' => 'stdout',
            'level' => 'info',
            'event' => 'document_processed',
            'phase' => 'review',
            'paperless_document_id' => 123,
            'message' => 'Processed document 123.',
            'context' => ['duration_ms' => 10],
        ]);
        $suggestion = ReviewSuggestion::factory()->create([
            'worker_job_id' => $job->id,
            'paperless_document_id' => 123,
            'proposed_title' => 'Detailed invoice',
            'dedupe_key' => hash('sha256', 'suggestion-detail'),
        ]);

        $this->actingAs($user)
            ->get(route('worker-jobs.show', $job))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('worker/Show')
                ->where('job.id', $job->id)
                ->where('job.type', WorkerJob::TYPE_PROCESS_DOCUMENT)
                ->where('job.payload.paperless_document_id', 123)
                ->where('job.progress.phase', 'review')
                ->where('job.ingest.review_suggestions_imported', 1)
                ->where('job.dispatch_key', $job->dispatch_key)
                ->where('logs.data.0.message', 'Processed document 123.')
                ->where('logs.data.0.paperless_document_id', 123)
                ->where('reviewSuggestions.0.id', $suggestion->id)
                ->where('reviewSuggestions.0.dedupe_key', $suggestion->dedupe_key)
                ->where('actions', null)
            );
    }

    public function test_admin_sees_worker_job_detail_actions(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $job = WorkerJob::factory()->create(['status' => WorkerJob::STATUS_CANCELLING]);

        $this->actingAs($admin)
            ->get(route('worker-jobs.show', $job))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('worker/Show')
                ->where('isAdmin', true)
                ->where('actions.can_stop', true)
                ->where('actions.can_force_kill', true)
                ->where('actions.force_kill_url', route('worker-jobs.force-kill', $job))
            );
    }

    public function test_non_admin_cannot_force_kill_worker_job(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $job = WorkerJob::factory()->create(['status' => WorkerJob::STATUS_RUNNING]);

        $this->actingAs($user)
            ->post(route('worker-jobs.force-kill', $job))
            ->assertForbidden();
    }

    public function test_worker_job_index_links_to_detail_page(): void
    {
        $indexPage = file_get_contents(resource_path('js/pages/worker/Index.svelte'));

        $this->assertIsString($indexPage);
        $this->assertStringContainsString('workerJobShow(job.id).url', $indexPage);
    }

    public function test_worker_job_index_exposes_full_control_labels(): void
    {
        $indexPage = file_get_contents(resource_path('js/pages/worker/Index.svelte'));
        $showPage = file_get_contents(resource_path('js/pages/worker/Show.svelte'));

        $this->assertIsString($indexPage);
        $this->assertIsString($showPage);
        $this->assertStringContainsString('Run forced poll reconciliation', $indexPage);
        $this->assertStringContainsString('Process document ID', $indexPage);
        $this->assertStringContainsString('Force process document', $indexPage);
        $this->assertStringContainsString('Queue forced OCR reindex worker', $indexPage);
        $this->assertStringContainsString('quickControls.poll_url', $indexPage);
        $this->assertStringContainsString('quickControls.reindex_url', $indexPage);
        $this->assertStringContainsString('quickControls.embedding_build_url', $indexPage);
        $this->assertStringContainsString('Retry whole job', $indexPage);
        $this->assertStringContainsString('Retry failed documents only', $indexPage);
        $this->assertStringContainsString('Retry failed documents only', $showPage);
    }

    public function test_queueing_poll_routes_to_durable_command_not_worker_job(): void
    {
        Queue::fake();
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)
            ->post(route('worker-jobs.store'), ['type' => WorkerJob::TYPE_POLL])
            ->assertRedirect(route('worker-jobs.index'));

        $this->assertDatabaseCount('worker_jobs', 0);
        $this->assertDatabaseHas('commands', [
            'type' => Command::TYPE_POLL_RECONCILIATION,
            'status' => Command::STATUS_PENDING,
            'created_by_user_id' => $user->id,
        ]);
        Queue::assertNothingPushed();
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

    public function test_cancel_stale_worker_jobs_command_unblocks_expired_cancelling_lease(): void
    {
        $expiredLease = WorkerJob::factory()->create([
            'status' => WorkerJob::STATUS_CANCELLING,
            'worker_id' => 'stale-worker',
            'lease_expires_at' => now()->subMinute(),
            'heartbeat_at' => now()->subMinutes(5),
            'cancellation_requested_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);
        $activeLease = WorkerJob::factory()->create([
            'status' => WorkerJob::STATUS_CANCELLING,
            'worker_id' => 'active-worker',
            'lease_expires_at' => now()->addMinutes(5),
            'heartbeat_at' => now(),
            'cancellation_requested_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        $this->artisan('worker-jobs:cancel-stale', ['--minutes' => 30])
            ->expectsOutput('Cancelled 1 stale worker job(s).')
            ->assertSuccessful();

        $this->assertSame(WorkerJob::STATUS_CANCELLED, $expiredLease->refresh()->status);
        $this->assertNull($expiredLease->lease_expires_at);
        $this->assertSame(WorkerJob::STATUS_CANCELLING, $activeLease->refresh()->status);
    }

    public function test_queueing_duplicate_active_worker_job_reuses_existing_dispatch(): void
    {
        Queue::fake();
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)->post(route('worker-jobs.store'), ['type' => WorkerJob::TYPE_POLL]);
        $this->actingAs($user)->post(route('worker-jobs.store'), ['type' => WorkerJob::TYPE_POLL]);

        $this->assertSame(0, WorkerJob::query()->count());
        $this->assertSame(2, Command::query()->where('type', Command::TYPE_POLL_RECONCILIATION)->count());
        Queue::assertNothingPushed();
    }

    public function test_poll_force_payload_is_queued(): void
    {
        Queue::fake();
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)
            ->post(route('worker-jobs.store'), ['type' => WorkerJob::TYPE_POLL, 'force' => '1'])
            ->assertRedirect(route('worker-jobs.index'));

        $command = Command::query()->firstOrFail();
        $this->assertSame(Command::TYPE_POLL_RECONCILIATION, $command->type);
        $this->assertTrue($command->payload['force']);
    }

    public function test_embedding_reindex_queues_command_and_worker_fallback_without_absurd(): void
    {
        Queue::fake();
        Config::set('archibot.absurd_database_url', '');
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)
            ->post(route('worker-jobs.store'), ['type' => WorkerJob::TYPE_REINDEX_EMBED])
            ->assertRedirect(route('worker-jobs.index'));

        $command = Command::query()->firstOrFail();
        $workerJob = WorkerJob::query()->firstOrFail();

        $this->assertSame(Command::TYPE_EMBEDDING_INDEX_BUILD, $command->type);
        $this->assertSame(Command::STATUS_QUEUED, $command->status);
        $this->assertSame($workerJob->id, $command->payload['legacy_fallback_worker_job_id']);
        $this->assertSame(WorkerJob::TYPE_REINDEX_EMBED, $workerJob->type);
        $this->assertSame($command->id, $workerJob->payload['command_id']);
        Queue::assertPushed(RunPythonWorkerJob::class, fn (RunPythonWorkerJob $queued): bool => $queued->workerJobId === $workerJob->id);
    }

    public function test_process_document_force_payload_is_queued(): void
    {
        Queue::fake();
        $user = User::factory()->create(['is_admin' => true]);

        EmbeddingIndexState::query()->create(['status' => EmbeddingIndexState::STATUS_COMPLETE]);

        $this->actingAs($user)
            ->post(route('worker-jobs.store'), [
                'type' => WorkerJob::TYPE_PROCESS_DOCUMENT,
                'paperless_document_id' => 123,
                'force' => '1',
            ])
            ->assertRedirect(route('pipeline-runs.show', PipelineRun::query()->firstOrFail()));

        $this->assertDatabaseCount('worker_jobs', 0);
        $run = PipelineRun::query()->firstOrFail();
        $this->assertSame('manual', $run->trigger_source);
        $this->assertSame(123, $run->paperless_document_id);
        $this->assertTrue($run->reprocess_requested);
        $this->assertSame('manual_force', $run->reprocess_reason);
    }

    public function test_reindex_ocr_force_payload_is_queued(): void
    {
        Queue::fake();
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)
            ->post(route('worker-jobs.store'), ['type' => WorkerJob::TYPE_REINDEX_OCR, 'force' => '1'])
            ->assertRedirect(route('worker-jobs.index'));

        $this->assertSame(['mode' => 'ocr', 'force' => true], WorkerJob::query()->firstOrFail()->payload);
    }

    public function test_retry_creates_durable_command_for_migrated_poll_job(): void
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

        $this->assertSame(1, WorkerJob::query()->count());
        $command = Command::query()->firstOrFail();
        $this->assertSame(Command::TYPE_POLL_RECONCILIATION, $command->type);
        $this->assertSame($original->id, $command->payload['legacy_worker_job_id']);
        $this->assertSame('whole_job', $command->payload['retry_mode']);
        Queue::assertNothingPushed();
    }

    public function test_retry_failed_documents_only_payload_is_queued_when_failed_document_ids_are_available(): void
    {
        Queue::fake();
        $user = User::factory()->create(['is_admin' => true]);
        $original = WorkerJob::factory()->create([
            'type' => WorkerJob::TYPE_POLL,
            'status' => WorkerJob::STATUS_PARTIALLY_FAILED,
            'payload' => ['mode' => 'inbox', 'force' => false],
        ]);
        $original->logs()->create([
            'stream' => 'stdout',
            'level' => 'error',
            'event' => 'document_failed',
            'paperless_document_id' => 101,
            'message' => 'Document 101 failed.',
            'context' => [],
        ]);
        $original->logs()->create([
            'stream' => 'stdout',
            'level' => 'error',
            'event' => 'document_failed',
            'paperless_document_id' => 202,
            'message' => 'Document 202 failed.',
            'context' => [],
        ]);

        $this->actingAs($user)
            ->post(route('worker-jobs.retry', $original), ['failed_only' => '1'])
            ->assertRedirect(route('worker-jobs.index'));

        $this->assertSame(1, WorkerJob::query()->count());
        $command = Command::query()->firstOrFail();
        $this->assertSame(Command::TYPE_POLL_RECONCILIATION, $command->type);
        $this->assertSame($original->id, $command->payload['legacy_worker_job_id']);
        $this->assertSame('failed_only', $command->payload['retry_mode']);
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
