<?php

namespace Tests\Feature\Review;

use App\Jobs\RunPythonActorJob;
use App\Models\AuditLog;
use App\Models\Command;
use App\Models\PipelineEvent;
use App\Models\ReviewSuggestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReviewCliEquivalenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_cli_review_commit_uses_the_same_durable_review_action_as_ui(): void
    {
        Queue::fake();
        $uninvolvedAdmin = User::factory()->create(['is_admin' => true]);
        $admin = User::factory()->create(['is_admin' => true]);
        $uiSuggestion = ReviewSuggestion::factory()->create(['paperless_document_id' => 101]);
        $cliSuggestion = ReviewSuggestion::factory()->create(['paperless_document_id' => 202]);

        $this->actingAs($admin)
            ->post(route('review.accept', $uiSuggestion))
            ->assertRedirect(route('review.index'));
        $this->artisan('archibot:review-commit', [
            'suggestion-id' => $cliSuggestion->id,
            '--user-id' => $admin->id,
        ])
            ->assertSuccessful();

        foreach ([$uiSuggestion->refresh(), $cliSuggestion->refresh()] as $suggestion) {
            $this->assertSame(ReviewSuggestion::STATUS_ACCEPTED, $suggestion->status);
            $this->assertSame(ReviewSuggestion::COMMIT_STATUS_QUEUED, $suggestion->commit_status);
            $this->assertNotNull($suggestion->commit_command_id);
            $this->assertSame(Command::TYPE_REVIEW_COMMIT, $suggestion->commitCommand->type);
            $this->assertSame(Command::STATUS_QUEUED, $suggestion->commitCommand->status);
            $this->assertSame([
                'job_control.review_commit_requested',
                'job_control.review_commit_actor_queued',
            ], PipelineEvent::query()->where('command_id', $suggestion->commit_command_id)->orderBy('id')->pluck('event_type')->all());
            $audit = AuditLog::query()->where('event', 'review_suggestion.accepted')
                ->where('target_id', (string) $suggestion->id)->firstOrFail();
            $this->assertSame($admin->id, $audit->actor_user_id);
        }

        $uiAudit = AuditLog::query()->where('event', 'review_suggestion.accepted')
            ->where('target_id', (string) $uiSuggestion->id)->firstOrFail();
        $cliAudit = AuditLog::query()->where('event', 'review_suggestion.accepted')
            ->where('target_id', (string) $cliSuggestion->id)->firstOrFail();
        $this->assertSame('authenticated_user', $uiAudit->metadata['actor_principal']);
        $this->assertSame('local_operator', $cliAudit->metadata['actor_principal']);
        $this->assertSame('authenticated_user', PipelineEvent::query()->where('command_id', $uiSuggestion->commit_command_id)
            ->where('event_type', 'job_control.review_commit_requested')->firstOrFail()->payload['actor_principal']);
        $this->assertSame('local_operator', PipelineEvent::query()->where('command_id', $cliSuggestion->commit_command_id)
            ->where('event_type', 'job_control.review_commit_requested')->firstOrFail()->payload['actor_principal']);

        $this->assertSame(2, AuditLog::query()->where('event', 'review_suggestion.accepted')->count());
        $this->assertSame(0, AuditLog::query()->where('event', 'review_suggestion.accepted')->where('actor_user_id', $uninvolvedAdmin->id)->count());
        Queue::assertPushed(RunPythonActorJob::class, 2);

        $commandIds = [$uiSuggestion->commit_command_id, $cliSuggestion->commit_command_id];
        $this->restartApplicationPreservingDatabase();
        $this->assertSame(2, Command::query()->whereIn('id', $commandIds)->where('status', Command::STATUS_QUEUED)->count());
        $this->assertSame(4, PipelineEvent::query()->whereIn('command_id', $commandIds)->count());
    }

    public function test_cli_review_commit_requires_explicit_operator_identity_and_never_selects_an_admin(): void
    {
        Queue::fake();
        User::factory()->create(['is_admin' => true]);
        $suggestion = ReviewSuggestion::factory()->create(['paperless_document_id' => 250]);

        $this->artisan('archibot:review-commit', ['suggestion-id' => $suggestion->id])
            ->assertFailed();

        $this->assertSame(ReviewSuggestion::STATUS_PENDING, $suggestion->refresh()->status);
        $this->assertNull($suggestion->commit_command_id);
        $this->assertDatabaseCount('commands', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        Queue::assertNothingPushed();
    }

    public function test_cli_review_commit_survives_application_restart_as_durable_state(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $suggestion = ReviewSuggestion::factory()->create(['paperless_document_id' => 303]);

        $this->artisan('archibot:review-commit', [
            'suggestion-id' => $suggestion->id,
            '--user-id' => $admin->id,
        ])
            ->assertSuccessful();
        $commandId = $suggestion->refresh()->commit_command_id;

        $this->restartApplicationPreservingDatabase();

        $this->assertDatabaseHas('commands', [
            'id' => $commandId,
            'type' => Command::TYPE_REVIEW_COMMIT,
            'status' => Command::STATUS_QUEUED,
        ]);
        $this->assertDatabaseHas('review_suggestions', [
            'id' => $suggestion->id,
            'commit_command_id' => $commandId,
            'commit_status' => ReviewSuggestion::COMMIT_STATUS_QUEUED,
        ]);
    }
}
