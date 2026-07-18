<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\OcrReview;
use App\Services\Paperless\PaperlessClient;
use App\Services\Paperless\PaperlessDocumentPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OcrReviewController extends Controller
{
    public function __construct(private readonly PaperlessDocumentPermissions $permissions) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 403);
        $validated = $request->validate(['per_page' => ['nullable', 'integer', 'in:10,25,50,100']]);

        // Load only identifiers until live Paperless authorization has completed. This
        // prevents inaccessible OCR snapshots from entering memory or paginator data.
        $visibleDocumentIds = OcrReview::query()
            ->select(['paperless_document_id'])
            ->distinct()
            ->pluck('paperless_document_id')
            ->filter(fn (int $documentId): bool => $this->permissions->canViewDocument($user, $documentId))
            ->values();

        $reviews = OcrReview::query()
            ->select([
                'id',
                'paperless_document_id',
                'status',
                'created_at',
                'reviewed_at',
            ])
            ->whereIn('paperless_document_id', $visibleDocumentIds)
            ->latest()
            ->paginate((int) ($validated['per_page'] ?? 25))
            ->withQueryString()
            ->through(fn (OcrReview $review) => $this->serialize($review));

        return Inertia::render('ocr/Index', [
            'reviews' => $reviews,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'paperless_document_id' => ['required', 'integer', 'min:1'],
            'ocr_content' => ['required', 'string'],
        ]);
        $user = $request->user();
        abort_unless($user !== null, 403);
        $documentId = (int) $validated['paperless_document_id'];

        // Store is a local mutation and must use a fresh live change check before
        // any Paperless or local OCR content is loaded or persisted.
        $this->permissions->assertCanChangeDocument($user, $documentId);
        $originalContent = $this->paperless($request)
            ->documentContent($user->paperless_token, $documentId);
        $this->permissions->assertCanChangeDocument($user, $documentId);

        $review = OcrReview::query()->create([
            'paperless_document_id' => $documentId,
            'original_content' => $originalContent,
            'ocr_content' => $validated['ocr_content'],
            'status' => OcrReview::STATUS_PENDING,
            'created_by_user_id' => $user->id,
        ]);

        $this->audit($request, 'ocr_review.created', $review, [
            'paperless_document_id' => $review->paperless_document_id,
        ]);

        return redirect()->route('ocr-reviews.show', $review)
            ->with('status', 'OCR correction created for review.');
    }

    public function show(Request $request, int $ocrReview): Response
    {
        $locator = $this->authorizedLocator($request, $ocrReview, false);
        $review = OcrReview::query()->findOrFail($locator->id);

        return Inertia::render('ocr/Show', [
            'review' => $this->serialize($review, true),
            'actions' => [
                'approve' => route('ocr-reviews.approve', $review),
                'reject' => route('ocr-reviews.reject', $review),
            ],
        ]);
    }

    public function approve(Request $request, int $ocrReview): RedirectResponse
    {
        $validated = $request->validate([
            'approved_content' => ['required', 'string'],
        ]);
        $locator = $this->authorizedLocator($request, $ocrReview, true);
        $review = OcrReview::query()->findOrFail($locator->id);
        abort_unless($review->status === OcrReview::STATUS_PENDING, 409);
        abort_unless($this->permissions->canChangeDocument($request->user(), $review->paperless_document_id), 404);

        $review->forceFill([
            'approved_content' => $validated['approved_content'],
            'status' => OcrReview::STATUS_APPROVED,
            'reviewed_by_user_id' => $request->user()->id,
            'reviewed_at' => now(),
        ])->save();

        $this->audit($request, 'ocr_review.approved', $review, [
            'paperless_document_id' => $review->paperless_document_id,
        ]);

        return redirect()->route('ocr-reviews.show', $review)
            ->with('status', 'OCR correction approved and stored locally.');
    }

    public function reject(Request $request, int $ocrReview): RedirectResponse
    {
        $locator = $this->authorizedLocator($request, $ocrReview, true);
        $review = OcrReview::query()->findOrFail($locator->id);
        abort_unless($review->status === OcrReview::STATUS_PENDING, 409);
        abort_unless($this->permissions->canChangeDocument($request->user(), $review->paperless_document_id), 404);

        $review->forceFill([
            'status' => OcrReview::STATUS_REJECTED,
            'reviewed_by_user_id' => $request->user()->id,
            'reviewed_at' => now(),
        ])->save();

        $this->audit($request, 'ocr_review.rejected', $review, [
            'paperless_document_id' => $review->paperless_document_id,
        ]);

        return redirect()->route('ocr-reviews.index')
            ->with('status', 'OCR correction rejected; Paperless content was not changed.');
    }

    private function paperless(Request $request): PaperlessClient
    {
        $token = $request->user()->paperless_token;

        abort_if(! $token, 503, 'Paperless connection is not available.');

        return app(PaperlessClient::class);
    }

    private function authorizedLocator(Request $request, int $ocrReviewId, bool $change): OcrReview
    {
        // Route model binding would hydrate long-text snapshots before authorization.
        $locator = OcrReview::query()
            ->select(['id', 'paperless_document_id'])
            ->findOrFail($ocrReviewId);
        $user = $request->user();
        abort_unless($user !== null, 404);

        $allowed = $change
            ? $this->permissions->canChangeDocument($user, $locator->paperless_document_id)
            : $this->permissions->canViewDocument($user, $locator->paperless_document_id);

        // Match missing records so denied callers cannot distinguish OCR row existence.
        abort_unless($allowed, 404);

        return $locator;
    }

    private function audit(Request $request, string $event, OcrReview $review, array $metadata = []): void
    {
        AuditLog::query()->create([
            'actor_user_id' => $request->user()->id,
            'event' => $event,
            'target_type' => 'ocr_review',
            'target_id' => (string) $review->id,
            'metadata' => $metadata,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(OcrReview $review, bool $includeContent = false): array
    {
        $payload = [
            'id' => $review->id,
            'paperless_document_id' => $review->paperless_document_id,
            'status' => $review->status,
            'created_at' => $review->created_at?->toISOString(),
            'reviewed_at' => $review->reviewed_at?->toISOString(),
        ];

        if ($includeContent) {
            $payload += [
                'original_content' => $review->original_content,
                'ocr_content' => $review->ocr_content,
                'approved_content' => $review->approved_content,
            ];
        }

        return $payload;
    }
}
