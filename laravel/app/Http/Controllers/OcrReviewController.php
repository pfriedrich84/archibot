<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\OcrReview;
use App\Services\Paperless\PaperlessClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class OcrReviewController extends Controller
{
    public function index(): Response
    {
        $reviews = OcrReview::query()
            ->latest()
            ->paginate(25)
            ->through(fn (OcrReview $review) => $this->serialize($review));

        return Inertia::render('ocr/Index', [
            'reviews' => $reviews,
            'autoWriteBackEnabled' => AppSetting::getValue('ocr.auto_write_back', '0') === '1',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'paperless_document_id' => ['required', 'integer', 'min:1'],
            'ocr_content' => ['required', 'string'],
        ]);

        $originalContent = $this->paperless($request)
            ->documentContent($request->user()->paperless_token, (int) $validated['paperless_document_id']);

        $review = OcrReview::query()->create([
            'paperless_document_id' => (int) $validated['paperless_document_id'],
            'original_content' => $originalContent,
            'ocr_content' => $validated['ocr_content'],
            'status' => OcrReview::STATUS_PENDING,
            'created_by_user_id' => $request->user()->id,
        ]);

        $this->audit($request, 'ocr_review.created', $review, [
            'paperless_document_id' => $review->paperless_document_id,
        ]);

        if (AppSetting::getValue('ocr.auto_write_back', '0') === '1') {
            $review->forceFill([
                'approved_content' => $review->ocr_content,
                'reviewed_by_user_id' => $request->user()->id,
                'reviewed_at' => now(),
            ])->save();

            $this->audit($request, 'ocr_review.auto_write_requested', $review, [
                'paperless_document_id' => $review->paperless_document_id,
            ]);

            try {
                $this->paperless($request)->updateDocumentContent(
                    $request->user()->paperless_token,
                    $review->paperless_document_id,
                    $review->ocr_content,
                );
            } catch (Throwable $exception) {
                $review->forceFill([
                    'status' => OcrReview::STATUS_WRITE_BACK_FAILED,
                    'write_back_error' => $exception->getMessage(),
                ])->save();

                $this->audit($request, 'ocr_review.write_back_failed', $review, [
                    'paperless_document_id' => $review->paperless_document_id,
                    'error' => $exception->getMessage(),
                    'auto_write' => true,
                ]);

                return redirect()->route('ocr-reviews.show', $review)
                    ->with('error', 'Automatic Paperless write-back failed. The OCR text was kept for retry.');
            }

            $review->forceFill([
                'status' => OcrReview::STATUS_WRITTEN_BACK,
                'written_back_at' => now(),
            ])->save();

            $this->audit($request, 'ocr_review.written_back', $review, [
                'paperless_document_id' => $review->paperless_document_id,
                'auto_write' => true,
            ]);
        }

        return redirect()->route('ocr-reviews.show', $review);
    }

    public function show(OcrReview $ocrReview): Response
    {
        return Inertia::render('ocr/Show', [
            'review' => $this->serialize($ocrReview, true),
        ]);
    }

    public function approve(Request $request, OcrReview $ocrReview): RedirectResponse
    {
        abort_unless(in_array($ocrReview->status, [OcrReview::STATUS_PENDING, OcrReview::STATUS_WRITE_BACK_FAILED], true), 409);

        $validated = $request->validate([
            'approved_content' => ['required', 'string'],
        ]);

        $ocrReview->forceFill([
            'approved_content' => $validated['approved_content'],
            'reviewed_by_user_id' => $request->user()->id,
            'reviewed_at' => now(),
            'write_back_error' => null,
        ])->save();

        $this->audit($request, 'ocr_review.approved', $ocrReview, [
            'paperless_document_id' => $ocrReview->paperless_document_id,
        ]);

        try {
            $this->paperless($request)->updateDocumentContent(
                $request->user()->paperless_token,
                $ocrReview->paperless_document_id,
                $validated['approved_content'],
            );
        } catch (Throwable $exception) {
            $ocrReview->forceFill([
                'status' => OcrReview::STATUS_WRITE_BACK_FAILED,
                'write_back_error' => $exception->getMessage(),
            ])->save();

            $this->audit($request, 'ocr_review.write_back_failed', $ocrReview, [
                'paperless_document_id' => $ocrReview->paperless_document_id,
                'error' => $exception->getMessage(),
            ]);

            return redirect()->route('ocr-reviews.show', $ocrReview)
                ->with('error', 'Paperless write-back failed. The approved OCR text was kept for retry.');
        }

        $ocrReview->forceFill([
            'status' => OcrReview::STATUS_WRITTEN_BACK,
            'written_back_at' => now(),
        ])->save();

        $this->audit($request, 'ocr_review.written_back', $ocrReview, [
            'paperless_document_id' => $ocrReview->paperless_document_id,
        ]);

        return redirect()->route('ocr-reviews.show', $ocrReview)
            ->with('status', 'OCR text was written back to Paperless.');
    }

    public function reject(Request $request, OcrReview $ocrReview): RedirectResponse
    {
        abort_unless($ocrReview->status === OcrReview::STATUS_PENDING, 409);

        $ocrReview->forceFill([
            'status' => OcrReview::STATUS_REJECTED,
            'reviewed_by_user_id' => $request->user()->id,
            'reviewed_at' => now(),
        ])->save();

        $this->audit($request, 'ocr_review.rejected', $ocrReview, [
            'paperless_document_id' => $ocrReview->paperless_document_id,
        ]);

        return redirect()->route('ocr-reviews.index');
    }

    public function restore(Request $request, OcrReview $ocrReview): RedirectResponse
    {
        abort_unless(in_array($ocrReview->status, [OcrReview::STATUS_WRITTEN_BACK, OcrReview::STATUS_WRITE_BACK_FAILED], true), 409);

        $this->paperless($request)->updateDocumentContent(
            $request->user()->paperless_token,
            $ocrReview->paperless_document_id,
            $ocrReview->original_content,
        );

        $ocrReview->forceFill([
            'status' => OcrReview::STATUS_RESTORED,
            'restored_at' => now(),
        ])->save();

        $this->audit($request, 'ocr_review.restored', $ocrReview, [
            'paperless_document_id' => $ocrReview->paperless_document_id,
        ]);

        return redirect()->route('ocr-reviews.show', $ocrReview)
            ->with('status', 'Original Paperless content was restored.');
    }

    private function paperless(Request $request): PaperlessClient
    {
        $paperlessUrl = AppSetting::getValue('paperless.url');
        $token = $request->user()->paperless_token;

        abort_if(! $paperlessUrl || ! $token, 503, 'Paperless connection is not available.');

        return new PaperlessClient($paperlessUrl);
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
            'write_back_error' => $review->write_back_error,
            'created_at' => $review->created_at?->toISOString(),
            'reviewed_at' => $review->reviewed_at?->toISOString(),
            'written_back_at' => $review->written_back_at?->toISOString(),
            'restored_at' => $review->restored_at?->toISOString(),
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
