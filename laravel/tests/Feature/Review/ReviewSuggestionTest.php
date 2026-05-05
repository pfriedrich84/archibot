<?php

namespace Tests\Feature\Review;

use App\Models\ReviewSuggestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_already_reviewed_suggestions_can_not_be_reviewed_again(): void
    {
        $user = User::factory()->create();
        $suggestion = ReviewSuggestion::factory()->create(['status' => ReviewSuggestion::STATUS_ACCEPTED]);

        $this->actingAs($user)
            ->post(route('review.reject', $suggestion))
            ->assertStatus(409);
    }
}
