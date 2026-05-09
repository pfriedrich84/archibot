<?php

namespace Tests\Feature\Review;

use App\Jobs\RunPythonWorkerJob;
use App\Models\AppSetting;
use App\Models\EmbeddingIndexState;
use App\Models\PipelineRun;
use App\Models\ReviewSuggestion;
use App\Models\User;
use App\Models\WorkerJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReviewSuggestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_users_can_view_pending_review_queue(): void
    {
        $user = User::factory()->create();
        $suggestion = ReviewSuggestion::factory()->create([
            'paperless_document_id' => 123,
            'proposed_title' => 'Suggested title',
        ]);
        ReviewSuggestion::factory()->create(['status' => ReviewSuggestion::STATUS_REJECTED]);

        $this->actingAs($user)
            ->get(route('review.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('review/Index')
                ->has('suggestions.data', 1)
                ->where('suggestions.data.0.id', $suggestion->id)
                ->where('suggestions.data.0.proposed_title', 'Suggested title')
            );
    }

    public function test_pending_review_queue_only_shows_latest_suggestion_per_document(): void
    {
        $user = User::factory()->create();
        $older = ReviewSuggestion::factory()->create([
            'paperless_document_id' => 123,
            'proposed_title' => 'Older suggestion',
        ]);
        $latest = ReviewSuggestion::factory()->create([
            'paperless_document_id' => 123,
            'proposed_title' => 'Latest suggestion',
        ]);
        $otherDocument = ReviewSuggestion::factory()->create([
            'paperless_document_id' => 456,
            'proposed_title' => 'Other document',
        ]);

        $this->actingAs($user)
            ->get(route('review.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('review/Index')
                ->has('suggestions.data', 2)
                ->where('suggestions.data.0.id', $otherDocument->id)
                ->where('suggestions.data.1.id', $latest->id)
            );

        $this->actingAs($user)
            ->get(route('review.index', ['status' => 'all']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('suggestions.data', 3)
            );
    }

    public function test_older_pending_suggestions_can_not_be_reviewed_after_a_newer_suggestion_exists(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $older = ReviewSuggestion::factory()->create(['paperless_document_id' => 123]);
        ReviewSuggestion::factory()->create(['paperless_document_id' => 123]);

        $this->actingAs($user)
            ->post(route('review.accept', $older))
            ->assertStatus(409);

        $this->assertSame(ReviewSuggestion::STATUS_PENDING, $older->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_bulk_review_skips_older_pending_suggestions_when_newer_suggestion_exists(): void
    {
        $user = User::factory()->create();
        $older = ReviewSuggestion::factory()->create(['paperless_document_id' => 123]);
        $latest = ReviewSuggestion::factory()->create(['paperless_document_id' => 123]);

        $this->actingAs($user)
            ->post(route('review.bulk.reject'), [
                'suggestion_ids' => [$older->id, $latest->id],
            ])
            ->assertRedirect(route('review.index'));

        $this->assertSame(ReviewSuggestion::STATUS_PENDING, $older->refresh()->status);
        $this->assertSame(ReviewSuggestion::STATUS_REJECTED, $latest->refresh()->status);
    }

    public function test_authenticated_users_can_view_review_detail(): void
    {
        $user = User::factory()->create();
        $suggestion = ReviewSuggestion::factory()->create([
            'paperless_document_id' => 456,
            'reasoning' => 'Classifier reasoning',
        ]);

        $this->actingAs($user)
            ->get(route('review.show', $suggestion))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('review/Show')
                ->where('suggestion.paperless_document_id', 456)
                ->where('suggestion.reasoning', 'Classifier reasoning')
                ->where('suggestion.original.title', 'Original document title')
            );
    }

    public function test_accepting_a_pending_suggestion_records_decision_and_audit_log(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $suggestion = ReviewSuggestion::factory()->create(['paperless_document_id' => 789]);

        $this->actingAs($user)
            ->post(route('review.accept', $suggestion))
            ->assertRedirect(route('review.index'));

        $suggestion->refresh();
        $this->assertSame(ReviewSuggestion::STATUS_ACCEPTED, $suggestion->status);
        $this->assertSame($user->id, $suggestion->reviewed_by_user_id);
        $this->assertNotNull($suggestion->reviewed_at);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $user->id,
            'event' => 'review_suggestion.accepted',
            'target_type' => 'review_suggestion',
            'target_id' => (string) $suggestion->id,
        ]);
        Queue::assertNothingPushed();
    }

    public function test_accepting_event_driven_suggestion_marks_commit_queued_without_legacy_worker(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $suggestion = ReviewSuggestion::factory()->create([
            'paperless_document_id' => 789,
            'source_suggestion_id' => null,
        ]);

        $this->actingAs($user)
            ->post(route('review.accept', $suggestion))
            ->assertRedirect(route('review.index'));

        $suggestion->refresh();
        $this->assertSame(ReviewSuggestion::STATUS_ACCEPTED, $suggestion->status);
        $this->assertSame(ReviewSuggestion::COMMIT_STATUS_QUEUED, $suggestion->commit_status);
        $this->assertNull($suggestion->commit_worker_job_id);
        $this->assertDatabaseCount('worker_jobs', 0);
        Queue::assertNothingPushed();
    }

    public function test_accepting_python_origin_suggestion_queues_commit_worker_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $suggestion = ReviewSuggestion::factory()->create([
            'paperless_document_id' => 789,
            'source_suggestion_id' => 321,
        ]);

        $this->actingAs($user)
            ->post(route('review.accept', $suggestion))
            ->assertRedirect(route('review.index'));

        $workerJob = WorkerJob::query()->firstOrFail();
        $this->assertSame(WorkerJob::TYPE_COMMIT_REVIEW, $workerJob->type);
        $this->assertSame(WorkerJob::STATUS_QUEUED, $workerJob->status);
        $suggestion->refresh();
        $this->assertSame(ReviewSuggestion::COMMIT_STATUS_QUEUED, $suggestion->commit_status);
        $this->assertSame($workerJob->id, $suggestion->commit_worker_job_id);
        $this->assertSame($suggestion->id, $workerJob->payload['review_suggestion_id']);
        $this->assertSame(321, $workerJob->payload['source_suggestion_id']);
        $this->assertSame(789, $workerJob->payload['paperless_document_id']);
        $this->assertSame('Proposed document title', $workerJob->payload['title']);
        Queue::assertPushed(RunPythonWorkerJob::class, fn (RunPythonWorkerJob $job) => $job->workerJobId === $workerJob->id);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'worker_job.queued',
            'target_id' => (string) $workerJob->id,
        ]);
    }

    public function test_admin_can_queue_manual_reprocess_from_review_detail(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        EmbeddingIndexState::query()->create(['status' => 'complete']);
        $suggestion = ReviewSuggestion::factory()->create(['paperless_document_id' => 456]);

        $this->actingAs($admin)
            ->post(route('review.reprocess', $suggestion), ['reason' => 'try again'])
            ->assertRedirect(route('review.show', $suggestion));

        $run = PipelineRun::query()->firstOrFail();
        $this->assertSame(PipelineRun::STATUS_PENDING, $run->status);
        $this->assertSame('manual', $run->trigger_source);
        $this->assertSame(456, $run->paperless_document_id);
        $this->assertTrue($run->reprocess_requested);
        $this->assertSame('try again', $run->reprocess_reason);
        $this->assertSame($admin->id, $run->requested_by_user_id);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'pipeline_run.manual_reprocess_queued',
            'target_type' => 'pipeline_run',
            'target_id' => (string) $run->id,
        ]);
    }

    public function test_manual_reprocess_is_blocked_when_embedding_index_is_not_complete(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        EmbeddingIndexState::query()->create(['status' => 'building']);
        $suggestion = ReviewSuggestion::factory()->create(['paperless_document_id' => 456]);

        $this->actingAs($admin)
            ->post(route('review.reprocess', $suggestion), ['reason' => 'try again'])
            ->assertRedirect(route('review.show', $suggestion));

        $run = PipelineRun::query()->firstOrFail();
        $this->assertSame(PipelineRun::STATUS_BLOCKED, $run->status);
        $this->assertSame('blocked', $run->progress_current_phase);
        $this->assertSame('Waiting for embedding index to complete.', $run->progress_message);
        $this->assertSame('embedding_index_not_ready', $run->error_type);
        $this->assertSame('Waiting for embedding index to complete.', $run->error);
        $this->assertTrue($run->reprocess_requested);
    }

    public function test_non_admin_cannot_queue_manual_reprocess(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $suggestion = ReviewSuggestion::factory()->create(['paperless_document_id' => 456]);

        $this->actingAs($user)
            ->post(route('review.reprocess', $suggestion))
            ->assertForbidden();

        $this->assertDatabaseCount('pipeline_runs', 0);
    }

    public function test_review_preview_checks_document_access_and_proxies_paperless_preview(): void
    {
        AppSetting::put('paperless.url', 'https://paperless.example');
        Http::fake([
            'paperless.example/api/documents/456/' => Http::response(['id' => 456], 200),
            'paperless.example/api/documents/456/preview/' => Http::response('%PDF', 200, [
                'Content-Type' => 'application/pdf',
            ]),
        ]);

        $user = User::factory()->create(['paperless_token' => 'user-token']);
        $suggestion = ReviewSuggestion::factory()->create(['paperless_document_id' => 456]);

        $this->actingAs($user)
            ->get(route('review.preview', $suggestion))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertSee('%PDF', false);

        Http::assertSentCount(2);
    }

    public function test_review_preview_denies_when_paperless_document_check_fails(): void
    {
        AppSetting::put('paperless.url', 'https://paperless.example');
        Http::fake([
            'paperless.example/api/documents/456/' => Http::response([], 403),
        ]);

        $user = User::factory()->create(['paperless_token' => 'user-token']);
        $suggestion = ReviewSuggestion::factory()->create(['paperless_document_id' => 456]);

        $this->actingAs($user)
            ->get(route('review.preview', $suggestion))
            ->assertForbidden();
    }

    public function test_rejecting_a_pending_suggestion_records_decision_and_audit_log(): void
    {
        $user = User::factory()->create();
        $suggestion = ReviewSuggestion::factory()->create();

        $this->actingAs($user)
            ->post(route('review.reject', $suggestion))
            ->assertRedirect(route('review.index'));

        $this->assertSame(ReviewSuggestion::STATUS_REJECTED, $suggestion->refresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'review_suggestion.rejected',
            'target_id' => (string) $suggestion->id,
        ]);
    }

    public function test_review_queue_filters_by_confidence_judge_correspondent_and_storage(): void
    {
        $user = User::factory()->create();
        $match = ReviewSuggestion::factory()->create([
            'confidence' => 88,
            'judge_verdict' => 'corrected',
            'proposed_correspondent_id' => 10,
            'proposed_storage_path_id' => 20,
            'proposed_title' => 'Matching invoice',
        ]);
        ReviewSuggestion::factory()->create([
            'confidence' => 40,
            'judge_verdict' => 'accepted',
            'proposed_correspondent_id' => 11,
            'proposed_storage_path_id' => 21,
        ]);

        $this->actingAs($user)
            ->get(route('review.index', [
                'min_conf' => 80,
                'judge_verdict' => 'corrected',
                'correspondent_id' => 10,
                'storage_path_id' => 20,
                'q' => 'invoice',
                'sort' => 'confidence_desc',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('review/Index')
                ->has('suggestions.data', 1)
                ->where('suggestions.data.0.id', $match->id)
                ->where('filters.min_conf', '80')
                ->where('filters.judge_verdict', 'corrected')
            );
    }

    public function test_bulk_accept_marks_pending_suggestions_and_queues_commit_workers(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $first = ReviewSuggestion::factory()->create(['source_suggestion_id' => 101]);
        $second = ReviewSuggestion::factory()->create(['source_suggestion_id' => 102]);
        $reviewed = ReviewSuggestion::factory()->create(['status' => ReviewSuggestion::STATUS_REJECTED]);

        $this->actingAs($user)
            ->post(route('review.bulk.accept'), [
                'suggestion_ids' => [$first->id, $second->id, $reviewed->id],
            ])
            ->assertRedirect(route('review.index'));

        $this->assertSame(ReviewSuggestion::STATUS_ACCEPTED, $first->refresh()->status);
        $this->assertSame(ReviewSuggestion::STATUS_ACCEPTED, $second->refresh()->status);
        $this->assertSame(ReviewSuggestion::STATUS_REJECTED, $reviewed->refresh()->status);
        $this->assertSame(2, WorkerJob::query()->where('type', WorkerJob::TYPE_COMMIT_REVIEW)->count());
        Queue::assertPushed(RunPythonWorkerJob::class, 2);
    }

    public function test_bulk_reject_marks_pending_suggestions_and_skips_reviewed(): void
    {
        $user = User::factory()->create();
        $pending = ReviewSuggestion::factory()->create();
        $accepted = ReviewSuggestion::factory()->create(['status' => ReviewSuggestion::STATUS_ACCEPTED]);

        $this->actingAs($user)
            ->post(route('review.bulk.reject'), [
                'suggestion_ids' => [$pending->id, $accepted->id],
            ])
            ->assertRedirect(route('review.index'));

        $this->assertSame(ReviewSuggestion::STATUS_REJECTED, $pending->refresh()->status);
        $this->assertSame(ReviewSuggestion::STATUS_ACCEPTED, $accepted->refresh()->status);
    }

    public function test_saving_pending_suggestion_updates_editable_proposal_fields(): void
    {
        $user = User::factory()->create();
        $suggestion = ReviewSuggestion::factory()->create();

        $this->actingAs($user)
            ->post(route('review.save', $suggestion), [
                'proposed_title' => 'Edited title',
                'proposed_date' => '2026-05-07',
                'proposed_correspondent_id' => 44,
                'proposed_correspondent_name' => 'Edited correspondent',
                'proposed_document_type_id' => 55,
                'proposed_document_type_name' => 'Edited type',
                'proposed_storage_path_id' => 66,
                'proposed_storage_path_name' => 'Edited storage',
            ])
            ->assertRedirect(route('review.show', $suggestion));

        $suggestion->refresh();
        $this->assertSame('Edited title', $suggestion->proposed_title);
        $this->assertSame('Edited correspondent', $suggestion->proposed_correspondent_name);
        $this->assertSame(55, $suggestion->proposed_document_type_id);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'review_suggestion.saved',
            'target_id' => (string) $suggestion->id,
        ]);
    }

    public function test_reviewed_suggestion_can_not_be_saved(): void
    {
        $user = User::factory()->create();
        $suggestion = ReviewSuggestion::factory()->create(['status' => ReviewSuggestion::STATUS_ACCEPTED]);

        $this->actingAs($user)
            ->post(route('review.save', $suggestion), ['proposed_title' => 'Edited title'])
            ->assertStatus(409);
    }

    public function test_already_reviewed_suggestions_can_not_be_reviewed_again(): void
    {
        $user = User::factory()->create();
        $suggestion = ReviewSuggestion::factory()->create(['status' => ReviewSuggestion::STATUS_ACCEPTED]);

        $this->actingAs($user)
            ->post(route('review.reject', $suggestion))
            ->assertStatus(409);
    }
}
