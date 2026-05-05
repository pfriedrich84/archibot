<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ReviewSuggestion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReviewSuggestionController extends Controller
{
    public function index(Request $request): Response
    {
        $suggestions = ReviewSuggestion::query()
            ->where('status', ReviewSuggestion::STATUS_PENDING)
            ->latest()
            ->paginate(25)
            ->through(fn (ReviewSuggestion $suggestion) => $this->summary($suggestion));

        return Inertia::render('review/Index', [
            'suggestions' => $suggestions,
        ]);
    }

    public function show(Request $request, ReviewSuggestion $reviewSuggestion): Response
    {
        return Inertia::render('review/Show', [
            'suggestion' => $this->detail($reviewSuggestion),
        ]);
    }

    public function accept(Request $request, ReviewSuggestion $reviewSuggestion): RedirectResponse
    {
        $this->review($request, $reviewSuggestion, ReviewSuggestion::STATUS_ACCEPTED);

        return redirect()->route('review.index');
    }

    public function reject(Request $request, ReviewSuggestion $reviewSuggestion): RedirectResponse
    {
        $this->review($request, $reviewSuggestion, ReviewSuggestion::STATUS_REJECTED);

        return redirect()->route('review.index');
    }

    private function review(Request $request, ReviewSuggestion $suggestion, string $status): void
    {
        abort_unless($suggestion->status === ReviewSuggestion::STATUS_PENDING, 409);

        $suggestion->markReviewed($status, $request->user());

        AuditLog::query()->create([
            'actor_user_id' => $request->user()->id,
            'event' => "review_suggestion.{$status}",
            'target_type' => 'review_suggestion',
            'target_id' => (string) $suggestion->id,
            'metadata' => [
                'paperless_document_id' => $suggestion->paperless_document_id,
                'confidence' => $suggestion->confidence,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(ReviewSuggestion $suggestion): array
    {
        return [
            'id' => $suggestion->id,
            'paperless_document_id' => $suggestion->paperless_document_id,
            'status' => $suggestion->status,
            'confidence' => $suggestion->confidence,
            'original_title' => $suggestion->original_title,
            'proposed_title' => $suggestion->proposed_title,
            'proposed_correspondent_name' => $suggestion->proposed_correspondent_name,
            'proposed_document_type_name' => $suggestion->proposed_document_type_name,
            'created_at' => $suggestion->created_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(ReviewSuggestion $suggestion): array
    {
        return [
            ...$this->summary($suggestion),
            'reasoning' => $suggestion->reasoning,
            'judge_verdict' => $suggestion->judge_verdict,
            'judge_reasoning' => $suggestion->judge_reasoning,
            'original' => [
                'title' => $suggestion->original_title,
                'date' => $suggestion->original_date?->toDateString(),
                'correspondent_id' => $suggestion->original_correspondent_id,
                'document_type_id' => $suggestion->original_document_type_id,
                'storage_path_id' => $suggestion->original_storage_path_id,
                'tags' => $suggestion->original_tags ?? [],
            ],
            'proposed' => [
                'title' => $suggestion->proposed_title,
                'date' => $suggestion->proposed_date?->toDateString(),
                'correspondent_id' => $suggestion->proposed_correspondent_id,
                'correspondent_name' => $suggestion->proposed_correspondent_name,
                'document_type_id' => $suggestion->proposed_document_type_id,
                'document_type_name' => $suggestion->proposed_document_type_name,
                'storage_path_id' => $suggestion->proposed_storage_path_id,
                'storage_path_name' => $suggestion->proposed_storage_path_name,
                'tags' => $suggestion->proposed_tags ?? [],
            ],
            'context_documents' => $suggestion->context_documents ?? [],
        ];
    }
}
