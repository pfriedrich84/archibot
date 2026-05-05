<?php

namespace App\Http\Controllers;

use App\Jobs\RunPythonWorkerJob;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\ReviewSuggestion;
use App\Models\WorkerJob;
use App\Services\Paperless\PaperlessClient;
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

        if ($reviewSuggestion->source_suggestion_id !== null) {
            $workerJob = WorkerJob::query()->create([
                'type' => WorkerJob::TYPE_COMMIT_REVIEW,
                'status' => WorkerJob::STATUS_QUEUED,
                'payload' => [
                    'review_suggestion_id' => $reviewSuggestion->id,
                    'source_suggestion_id' => $reviewSuggestion->source_suggestion_id,
                    'paperless_document_id' => $reviewSuggestion->paperless_document_id,
                ],
                'created_by_user_id' => $request->user()->id,
            ]);

            AuditLog::query()->create([
                'actor_user_id' => $request->user()->id,
                'event' => 'worker_job.queued',
                'target_type' => 'worker_job',
                'target_id' => (string) $workerJob->id,
                'metadata' => [
                    'type' => $workerJob->type,
                    'review_suggestion_id' => $reviewSuggestion->id,
                    'source_suggestion_id' => $reviewSuggestion->source_suggestion_id,
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            $reviewSuggestion->forceFill([
                'commit_status' => ReviewSuggestion::COMMIT_STATUS_QUEUED,
                'commit_worker_job_id' => $workerJob->id,
            ])->save();

            RunPythonWorkerJob::dispatch($workerJob->id);
        }

        return redirect()->route('review.index');
    }

    public function reject(Request $request, ReviewSuggestion $reviewSuggestion): RedirectResponse
    {
        $this->review($request, $reviewSuggestion, ReviewSuggestion::STATUS_REJECTED);

        return redirect()->route('review.index');
    }

    public function preview(Request $request, ReviewSuggestion $reviewSuggestion)
    {
        $paperlessUrl = AppSetting::getValue('paperless.url');
        $token = $request->user()->paperless_token;

        abort_if(! $paperlessUrl || ! $token, 503, 'Paperless connection is not available.');

        $client = new PaperlessClient($paperlessUrl);

        try {
            $client->document($token, $reviewSuggestion->paperless_document_id);
            $preview = $client->documentPreview($token, $reviewSuggestion->paperless_document_id);
        } catch (\Throwable) {
            abort(403, 'Paperless document is not accessible.');
        }

        abort_unless($preview->successful(), $preview->status(), 'Paperless preview is not available.');

        return response($preview->body(), 200, [
            'Content-Type' => $preview->header('Content-Type', 'application/pdf'),
            'Cache-Control' => 'private, no-store',
        ]);
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
            'source_suggestion_id' => $suggestion->source_suggestion_id,
            'paperless_document_id' => $suggestion->paperless_document_id,
            'commit_status' => $suggestion->commit_status,
            'commit_worker_job_id' => $suggestion->commit_worker_job_id,
            'preview_url' => route('review.preview', $suggestion),
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
