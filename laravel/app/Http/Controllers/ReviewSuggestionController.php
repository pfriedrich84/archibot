<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\EmbeddingIndexState;
use App\Models\PipelineRun;
use App\Models\ReviewSuggestion;
use App\Models\WorkerJob;
use App\Services\Paperless\PaperlessClient;
use App\Services\Workers\WorkerJobDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ReviewSuggestionController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:pending,accepted,rejected,all'],
            'min_conf' => ['nullable', 'integer', 'min:0', 'max:100'],
            'max_conf' => ['nullable', 'integer', 'min:0', 'max:100'],
            'judge_verdict' => ['nullable', 'string', 'max:50'],
            'correspondent_id' => ['nullable', 'integer', 'min:1'],
            'storage_path_id' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', 'string', 'in:created_desc,confidence_asc,confidence_desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        if (isset($filters['min_conf'], $filters['max_conf']) && (int) $filters['min_conf'] > (int) $filters['max_conf']) {
            [$filters['min_conf'], $filters['max_conf']] = [$filters['max_conf'], $filters['min_conf']];
        }

        $status = $filters['status'] ?? ReviewSuggestion::STATUS_PENDING;
        $query = $status === ReviewSuggestion::STATUS_PENDING
            ? ReviewSuggestion::pendingReviewQueueQuery()
            : ReviewSuggestion::query();

        if ($status !== 'all' && $status !== ReviewSuggestion::STATUS_PENDING) {
            $query->where('status', $status);
        }
        if (isset($filters['min_conf'])) {
            $query->where('confidence', '>=', $filters['min_conf']);
        }
        if (isset($filters['max_conf'])) {
            $query->where('confidence', '<=', $filters['max_conf']);
        }
        if (! empty($filters['judge_verdict'])) {
            $query->where('judge_verdict', $filters['judge_verdict']);
        }
        if (! empty($filters['correspondent_id'])) {
            $query->where(function ($query) use ($filters): void {
                $query->where('proposed_correspondent_id', $filters['correspondent_id'])
                    ->orWhere(fn ($query) => $query
                        ->whereNull('proposed_correspondent_id')
                        ->where('original_correspondent_id', $filters['correspondent_id']));
            });
        }
        if (! empty($filters['storage_path_id'])) {
            $query->where(function ($query) use ($filters): void {
                $query->where('proposed_storage_path_id', $filters['storage_path_id'])
                    ->orWhere(fn ($query) => $query
                        ->whereNull('proposed_storage_path_id')
                        ->where('original_storage_path_id', $filters['storage_path_id']));
            });
        }
        if (! empty($filters['q'])) {
            $query->where(function ($query) use ($filters): void {
                $query->where('proposed_title', 'like', '%'.$filters['q'].'%')
                    ->orWhere('original_title', 'like', '%'.$filters['q'].'%')
                    ->orWhere('proposed_correspondent_name', 'like', '%'.$filters['q'].'%')
                    ->orWhere('proposed_document_type_name', 'like', '%'.$filters['q'].'%');
            });
        }

        match ($filters['sort'] ?? 'created_desc') {
            'confidence_asc' => $query->orderBy('confidence')->latest('id'),
            'confidence_desc' => $query->orderByDesc('confidence')->latest('id'),
            default => $query->latest(),
        };

        $suggestions = $query
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString()
            ->through(fn (ReviewSuggestion $suggestion) => $this->summary($suggestion));

        return Inertia::render('review/Index', [
            'suggestions' => $suggestions,
            'filters' => [
                'status' => $status,
                'min_conf' => $filters['min_conf'] ?? null,
                'max_conf' => $filters['max_conf'] ?? null,
                'judge_verdict' => $filters['judge_verdict'] ?? '',
                'correspondent_id' => $filters['correspondent_id'] ?? null,
                'storage_path_id' => $filters['storage_path_id'] ?? null,
                'sort' => $filters['sort'] ?? 'created_desc',
                'per_page' => $filters['per_page'] ?? 25,
                'q' => $filters['q'] ?? '',
            ],
            'actions' => [
                'index' => route('review.index'),
                'bulkAccept' => route('review.bulk.accept'),
                'bulkReject' => route('review.bulk.reject'),
            ],
        ]);
    }

    public function show(Request $request, ReviewSuggestion $reviewSuggestion): Response
    {
        $entityOptions = $this->entityOptions($request);

        return Inertia::render('review/Show', [
            'suggestion' => $this->detail($reviewSuggestion, $entityOptions),
            'entityOptions' => $entityOptions,
        ]);
    }

    public function accept(Request $request, ReviewSuggestion $reviewSuggestion): RedirectResponse
    {
        $this->review($request, $reviewSuggestion, ReviewSuggestion::STATUS_ACCEPTED);

        $this->queueCommitWorker($request, $reviewSuggestion, app(WorkerJobDispatcher::class));

        return redirect()->route('review.index');
    }

    public function reject(Request $request, ReviewSuggestion $reviewSuggestion): RedirectResponse
    {
        $this->review($request, $reviewSuggestion, ReviewSuggestion::STATUS_REJECTED);

        return redirect()->route('review.index');
    }

    public function reprocess(Request $request, ReviewSuggestion $reviewSuggestion): RedirectResponse
    {
        abort_unless((bool) $request->user()?->is_admin, 403);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $reason = (string) ($validated['reason'] ?? 'manual_admin_reprocess');
        if ($reason === '') {
            $reason = 'manual_admin_reprocess';
        }

        $dedupeKey = hash('sha256', implode(':', [
            'manual_reprocess',
            (string) $reviewSuggestion->paperless_document_id,
            (string) Str::uuid(),
        ]));

        $gateOpen = $this->embeddingIndexIsComplete();
        $run = PipelineRun::query()->create([
            'type' => 'document',
            'status' => $gateOpen ? PipelineRun::STATUS_PENDING : PipelineRun::STATUS_BLOCKED,
            'scope' => 'single_document',
            'trigger_source' => 'manual',
            'paperless_document_id' => $reviewSuggestion->paperless_document_id,
            'pipeline_dedupe_key' => $dedupeKey,
            'coalesced_sources' => ['manual'],
            'progress_current_phase' => $gateOpen ? 'queued' : 'blocked',
            'progress_message' => $gateOpen ? 'Manual admin reprocess queued.' : 'Waiting for embedding index to complete.',
            'progress_updated_at' => now(),
            'reprocess_requested' => true,
            'reprocess_reason' => $reason,
            'reprocess_mode' => 'manual',
            'requested_by_user_id' => $request->user()->id,
            'error_type' => $gateOpen ? null : 'embedding_index_not_ready',
            'error' => $gateOpen ? null : 'Waiting for embedding index to complete.',
        ]);

        AuditLog::query()->create([
            'actor_user_id' => $request->user()->id,
            'event' => 'pipeline_run.manual_reprocess_queued',
            'target_type' => 'pipeline_run',
            'target_id' => (string) $run->id,
            'metadata' => [
                'review_suggestion_id' => $reviewSuggestion->id,
                'paperless_document_id' => $reviewSuggestion->paperless_document_id,
                'reason' => $reason,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return redirect()->route('review.show', $reviewSuggestion)->with('status', 'Manual reprocess queued.');
    }

    public function save(Request $request, ReviewSuggestion $reviewSuggestion): RedirectResponse
    {
        $this->assertReviewable($reviewSuggestion);

        $validated = $request->validate([
            'proposed_title' => ['nullable', 'string', 'max:255'],
            'proposed_date' => ['nullable', 'date'],
            'proposed_correspondent_id' => ['nullable', 'integer', 'min:1'],
            'proposed_correspondent_name' => ['nullable', 'string', 'max:255'],
            'proposed_document_type_id' => ['nullable', 'integer', 'min:1'],
            'proposed_document_type_name' => ['nullable', 'string', 'max:255'],
            'proposed_storage_path_id' => ['nullable', 'integer', 'min:1'],
            'proposed_storage_path_name' => ['nullable', 'string', 'max:255'],
        ]);

        $reviewSuggestion->fill($validated)->save();

        AuditLog::query()->create([
            'actor_user_id' => $request->user()->id,
            'event' => 'review_suggestion.saved',
            'target_type' => 'review_suggestion',
            'target_id' => (string) $reviewSuggestion->id,
            'metadata' => [
                'paperless_document_id' => $reviewSuggestion->paperless_document_id,
                'fields' => array_keys($validated),
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return redirect()->route('review.show', $reviewSuggestion);
    }

    public function bulkAccept(Request $request): RedirectResponse
    {
        return $this->bulkReview($request, ReviewSuggestion::STATUS_ACCEPTED);
    }

    public function bulkReject(Request $request): RedirectResponse
    {
        return $this->bulkReview($request, ReviewSuggestion::STATUS_REJECTED);
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

    private function embeddingIndexIsComplete(): bool
    {
        return EmbeddingIndexState::query()->latest()->value('status') === EmbeddingIndexState::STATUS_COMPLETE;
    }

    private function bulkReview(Request $request, string $status): RedirectResponse
    {
        $validated = $request->validate([
            'suggestion_ids' => ['required', 'array', 'min:1'],
            'suggestion_ids.*' => ['integer', 'distinct'],
        ]);

        $changed = 0;
        $skipped = 0;
        $suggestions = ReviewSuggestion::query()
            ->whereIn('id', $validated['suggestion_ids'])
            ->get();

        foreach ($suggestions as $suggestion) {
            if ($suggestion->status !== ReviewSuggestion::STATUS_PENDING || ! $this->isLatestForDocument($suggestion)) {
                $skipped++;

                continue;
            }

            $this->review($request, $suggestion, $status);
            if ($status === ReviewSuggestion::STATUS_ACCEPTED) {
                $this->queueCommitWorker($request, $suggestion, app(WorkerJobDispatcher::class));
            }
            $changed++;
        }

        $skipped += count($validated['suggestion_ids']) - $suggestions->count();

        return redirect()->route('review.index')->with('status', "Review bulk action completed: {$changed} changed, {$skipped} skipped.");
    }

    private function queueCommitWorker(Request $request, ReviewSuggestion $reviewSuggestion, WorkerJobDispatcher $dispatcher): ?WorkerJob
    {
        if ($reviewSuggestion->source_suggestion_id === null) {
            $reviewSuggestion->forceFill([
                'commit_status' => ReviewSuggestion::COMMIT_STATUS_QUEUED,
            ])->save();

            return null;
        }

        $payload = [
            'review_suggestion_id' => $reviewSuggestion->id,
            'source_suggestion_id' => $reviewSuggestion->source_suggestion_id,
            'paperless_document_id' => $reviewSuggestion->paperless_document_id,
            'title' => $reviewSuggestion->proposed_title,
            'date' => $reviewSuggestion->proposed_date?->toDateString(),
            'correspondent_id' => $reviewSuggestion->proposed_correspondent_id,
            'doctype_id' => $reviewSuggestion->proposed_document_type_id,
            'storage_path_id' => $reviewSuggestion->proposed_storage_path_id,
            'tag_ids' => collect($reviewSuggestion->proposed_tags ?? [])
                ->pluck('id')
                ->filter(fn ($id) => is_numeric($id))
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all(),
        ];

        $workerJob = $dispatcher->dispatch(
            type: WorkerJob::TYPE_COMMIT_REVIEW,
            payload: $payload,
            user: $request->user(),
            request: $request,
            dedupeKey: WorkerJobDispatcher::dispatchKey(WorkerJob::TYPE_COMMIT_REVIEW, ['review_suggestion_id' => $reviewSuggestion->id]),
            auditMetadata: [
                'review_suggestion_id' => $reviewSuggestion->id,
                'source_suggestion_id' => $reviewSuggestion->source_suggestion_id,
            ],
        );

        $reviewSuggestion->forceFill([
            'commit_status' => ReviewSuggestion::COMMIT_STATUS_QUEUED,
            'commit_worker_job_id' => $workerJob->id,
        ])->save();

        return $workerJob;
    }

    private function review(Request $request, ReviewSuggestion $suggestion, string $status): void
    {
        $this->assertReviewable($suggestion);

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
     * @return array{correspondents: array<int, array{id: int, name: string}>, documentTypes: array<int, array{id: int, name: string}>, storagePaths: array<int, array{id: int, name: string}>}
     */
    private function entityOptions(Request $request): array
    {
        $paperlessUrl = AppSetting::getValue('paperless.url');
        $token = $request->user()?->paperless_token;

        if (! $paperlessUrl || ! $token) {
            return ['correspondents' => [], 'documentTypes' => [], 'storagePaths' => []];
        }

        $client = new PaperlessClient($paperlessUrl);

        try {
            return [
                'correspondents' => $client->correspondents($token),
                'documentTypes' => $client->documentTypes($token),
                'storagePaths' => $client->storagePaths($token),
            ];
        } catch (\Throwable) {
            return ['correspondents' => [], 'documentTypes' => [], 'storagePaths' => []];
        }
    }

    /**
     * @param  array<int, array{id: int, name: string}>  $options
     */
    private function entityName(array $options, ?int $id): ?string
    {
        if ($id === null) {
            return null;
        }

        foreach ($options as $option) {
            if ((int) $option['id'] === $id) {
                return (string) $option['name'];
            }
        }

        return null;
    }

    private function assertReviewable(ReviewSuggestion $suggestion): void
    {
        abort_unless($suggestion->status === ReviewSuggestion::STATUS_PENDING && $this->isLatestForDocument($suggestion), 409);
    }

    private function isLatestForDocument(ReviewSuggestion $suggestion): bool
    {
        return ! ReviewSuggestion::query()
            ->where('paperless_document_id', $suggestion->paperless_document_id)
            ->where('id', '>', $suggestion->id)
            ->exists();
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
            'worker_job_id' => $suggestion->worker_job_id,
            'commit_worker_job_id' => $suggestion->commit_worker_job_id,
            'worker_job_url' => $suggestion->worker_job_id ? route('worker-jobs.show', $suggestion->worker_job_id) : null,
            'commit_worker_job_url' => $suggestion->commit_worker_job_id ? route('worker-jobs.show', $suggestion->commit_worker_job_id) : null,
            'preview_url' => route('review.preview', $suggestion),
            'status' => $suggestion->status,
            'confidence' => $suggestion->confidence,
            'original_title' => $suggestion->original_title,
            'proposed_title' => $suggestion->proposed_title,
            'proposed_correspondent_id' => $suggestion->proposed_correspondent_id,
            'proposed_correspondent_name' => $suggestion->proposed_correspondent_name,
            'proposed_document_type_id' => $suggestion->proposed_document_type_id,
            'proposed_document_type_name' => $suggestion->proposed_document_type_name,
            'proposed_storage_path_id' => $suggestion->proposed_storage_path_id,
            'proposed_storage_path_name' => $suggestion->proposed_storage_path_name,
            'judge_verdict' => $suggestion->judge_verdict,
            'created_at' => $suggestion->created_at?->toISOString(),
        ];
    }

    /**
     * @param  array{correspondents: array<int, array{id: int, name: string}>, documentTypes: array<int, array{id: int, name: string}>, storagePaths: array<int, array{id: int, name: string}>}  $entityOptions
     * @return array<string, mixed>
     */
    private function detail(ReviewSuggestion $suggestion, array $entityOptions): array
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
                'correspondent_name' => $this->entityName($entityOptions['correspondents'], $suggestion->original_correspondent_id),
                'document_type_id' => $suggestion->original_document_type_id,
                'document_type_name' => $this->entityName($entityOptions['documentTypes'], $suggestion->original_document_type_id),
                'storage_path_id' => $suggestion->original_storage_path_id,
                'storage_path_name' => $this->entityName($entityOptions['storagePaths'], $suggestion->original_storage_path_id),
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
            'save_url' => route('review.save', $suggestion),
            'reprocess_url' => route('review.reprocess', $suggestion),
        ];
    }
}
