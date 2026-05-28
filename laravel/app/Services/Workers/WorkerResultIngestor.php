<?php

namespace App\Services\Workers;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\EntityApproval;
use App\Models\OcrReview;
use App\Models\ReviewSuggestion;
use App\Models\WorkerJob;
use App\Services\Paperless\PaperlessClient;
use Illuminate\Support\Arr;
use Throwable;

class WorkerResultIngestor
{
    /**
     * Ingest stable worker output arrays into Laravel-owned app state.
     *
     * Expected review suggestion shape is intentionally close to Laravel's
     * ReviewSuggestion attributes so Python can emit it without depending on
     * Laravel internals:
     *
     * {"review_suggestions": [{"paperless_document_id": 123, ...}]}
     *
     * @return array{review_suggestions_imported: int, entity_approvals_upserted: int, ocr_reviews_imported: int}
     */
    public function ingest(WorkerJob $workerJob): array
    {
        $result = $workerJob->result ?? [];
        $items = Arr::get($result, 'review_suggestions', []);

        $imported = 0;
        $entityApprovalsUpserted = 0;

        if (! is_array($items)) {
            $items = [];
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $documentId = Arr::get($item, 'paperless_document_id', Arr::get($item, 'document_id'));

            if (! is_numeric($documentId)) {
                continue;
            }

            $sourceSuggestionId = Arr::get($item, 'source_suggestion_id', Arr::get($item, 'python_suggestion_id'));
            $dedupeKey = $this->reviewSuggestionDedupeKey((int) $documentId, $item, $sourceSuggestionId);

            $suggestion = ReviewSuggestion::query()->updateOrCreate(
                ['dedupe_key' => $dedupeKey],
                [
                    'worker_job_id' => $workerJob->id,
                    'source_suggestion_id' => is_numeric($sourceSuggestionId) ? (int) $sourceSuggestionId : null,
                    'paperless_document_id' => (int) $documentId,
                    'status' => (string) Arr::get($item, 'status', ReviewSuggestion::STATUS_PENDING),
                    'confidence' => Arr::get($item, 'confidence'),
                    'reasoning' => Arr::get($item, 'reasoning'),
                    'original_title' => Arr::get($item, 'original.title', Arr::get($item, 'original_title')),
                    'original_date' => Arr::get($item, 'original.date', Arr::get($item, 'original_date')),
                    'original_correspondent_id' => Arr::get($item, 'original.correspondent_id', Arr::get($item, 'original_correspondent_id')),
                    'original_document_type_id' => Arr::get($item, 'original.document_type_id', Arr::get($item, 'original_document_type_id')),
                    'original_storage_path_id' => Arr::get($item, 'original.storage_path_id', Arr::get($item, 'original_storage_path_id')),
                    'original_tags' => Arr::get($item, 'original.tags', Arr::get($item, 'original_tags', [])),
                    'proposed_title' => Arr::get($item, 'proposed.title', Arr::get($item, 'proposed_title')),
                    'proposed_date' => Arr::get($item, 'proposed.date', Arr::get($item, 'proposed_date')),
                    'proposed_correspondent_name' => Arr::get($item, 'proposed.correspondent_name', Arr::get($item, 'proposed_correspondent_name')),
                    'proposed_correspondent_id' => Arr::get($item, 'proposed.correspondent_id', Arr::get($item, 'proposed_correspondent_id')),
                    'proposed_document_type_name' => Arr::get($item, 'proposed.document_type_name', Arr::get($item, 'proposed_document_type_name')),
                    'proposed_document_type_id' => Arr::get($item, 'proposed.document_type_id', Arr::get($item, 'proposed_document_type_id')),
                    'proposed_storage_path_name' => Arr::get($item, 'proposed.storage_path_name', Arr::get($item, 'proposed_storage_path_name')),
                    'proposed_storage_path_id' => Arr::get($item, 'proposed.storage_path_id', Arr::get($item, 'proposed_storage_path_id')),
                    'proposed_tags' => Arr::get($item, 'proposed.tags', Arr::get($item, 'proposed_tags', [])),
                    'context_documents' => Arr::get($item, 'context_documents', []),
                    'raw_response' => Arr::get($item, 'raw_response'),
                    'judge_verdict' => Arr::get($item, 'judge_verdict'),
                    'judge_reasoning' => Arr::get($item, 'judge_reasoning'),
                    'original_proposed_snapshot' => Arr::get($item, 'original_proposed_snapshot'),
                ]
            );

            $entityApprovalsUpserted += $this->upsertEntityApprovals($suggestion);
            if ($suggestion->wasRecentlyCreated) {
                $imported++;
            }
        }

        return [
            'review_suggestions_imported' => $imported,
            'entity_approvals_upserted' => $entityApprovalsUpserted,
            'ocr_reviews_imported' => $this->importOcrReviews($workerJob),
        ];
    }

    private function importOcrReviews(WorkerJob $workerJob): int
    {
        $items = Arr::get($workerJob->result ?? [], 'ocr_reviews', []);

        if (! is_array($items)) {
            return 0;
        }

        $paperlessUrl = AppSetting::getValue('paperless.url');
        $user = $workerJob->createdBy;

        if (! $paperlessUrl || ! $user?->paperless_token) {
            return 0;
        }

        $client = new PaperlessClient($paperlessUrl);
        $imported = 0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $documentId = Arr::get($item, 'paperless_document_id', Arr::get($item, 'document_id'));
            $ocrContent = Arr::get($item, 'ocr_content', Arr::get($item, 'content'));

            if (! is_numeric($documentId) || ! is_string($ocrContent) || $ocrContent === '') {
                continue;
            }

            try {
                $originalContent = $client->documentContent($user->paperless_token, (int) $documentId);
            } catch (Throwable) {
                continue;
            }

            $review = OcrReview::query()->firstOrCreate(
                ['dedupe_key' => $this->ocrReviewDedupeKey((int) $documentId, $ocrContent)],
                [
                    'paperless_document_id' => (int) $documentId,
                    'original_content' => $originalContent,
                    'ocr_content' => $ocrContent,
                    'status' => OcrReview::STATUS_PENDING,
                    'created_by_user_id' => $user->id,
                ]
            );

            if ($review->wasRecentlyCreated) {
                AuditLog::query()->create([
                    'actor_user_id' => $user->id,
                    'event' => 'ocr_review.created_from_worker',
                    'target_type' => 'ocr_review',
                    'target_id' => (string) $review->id,
                    'metadata' => [
                        'worker_job_id' => $workerJob->id,
                        'paperless_document_id' => $review->paperless_document_id,
                    ],
                ]);
            }

            if (AppSetting::getValue('ocr.auto_write_back', '0') === '1' && $review->status !== OcrReview::STATUS_WRITTEN_BACK) {
                $this->autoWriteOcrReview($client, $user->paperless_token, $workerJob, $review, $ocrContent);
            }

            $imported++;
        }

        return $imported;
    }

    private function autoWriteOcrReview(PaperlessClient $client, string $token, WorkerJob $workerJob, OcrReview $review, string $ocrContent): void
    {
        $review->forceFill([
            'approved_content' => $ocrContent,
            'reviewed_by_user_id' => $workerJob->created_by_user_id,
            'reviewed_at' => now(),
        ])->save();

        try {
            $client->updateDocumentContent($token, $review->paperless_document_id, $ocrContent);
        } catch (Throwable $exception) {
            $review->forceFill([
                'status' => OcrReview::STATUS_WRITE_BACK_FAILED,
                'write_back_error' => $exception->getMessage(),
            ])->save();

            AuditLog::query()->create([
                'actor_user_id' => $workerJob->created_by_user_id,
                'event' => 'ocr_review.write_back_failed',
                'target_type' => 'ocr_review',
                'target_id' => (string) $review->id,
                'metadata' => [
                    'worker_job_id' => $workerJob->id,
                    'paperless_document_id' => $review->paperless_document_id,
                    'auto_write' => true,
                    'error' => $exception->getMessage(),
                ],
            ]);

            return;
        }

        $review->forceFill([
            'status' => OcrReview::STATUS_WRITTEN_BACK,
            'written_back_at' => now(),
        ])->save();

        AuditLog::query()->create([
            'actor_user_id' => $workerJob->created_by_user_id,
            'event' => 'ocr_review.written_back',
            'target_type' => 'ocr_review',
            'target_id' => (string) $review->id,
            'metadata' => [
                'worker_job_id' => $workerJob->id,
                'paperless_document_id' => $review->paperless_document_id,
                'auto_write' => true,
            ],
        ]);
    }

    /** @param array<string, mixed> $item */
    private function reviewSuggestionDedupeKey(int $documentId, array $item, mixed $sourceSuggestionId): string
    {
        if (is_scalar($sourceSuggestionId) && trim((string) $sourceSuggestionId) !== '') {
            return hash('sha256', 'review-suggestion:source:'.$documentId.':'.trim((string) $sourceSuggestionId));
        }

        return hash('sha256', 'review-suggestion:fallback:'.$documentId.':'.$this->stableJson([
            'content_hash' => Arr::get($item, 'content_hash'),
            'proposed_title' => Arr::get($item, 'proposed.title', Arr::get($item, 'proposed_title')),
            'proposed_date' => Arr::get($item, 'proposed.date', Arr::get($item, 'proposed_date')),
            'proposed_correspondent_name' => Arr::get($item, 'proposed.correspondent_name', Arr::get($item, 'proposed_correspondent_name')),
            'proposed_correspondent_id' => Arr::get($item, 'proposed.correspondent_id', Arr::get($item, 'proposed_correspondent_id')),
            'proposed_document_type_name' => Arr::get($item, 'proposed.document_type_name', Arr::get($item, 'proposed_document_type_name')),
            'proposed_document_type_id' => Arr::get($item, 'proposed.document_type_id', Arr::get($item, 'proposed_document_type_id')),
            'proposed_storage_path_name' => Arr::get($item, 'proposed.storage_path_name', Arr::get($item, 'proposed_storage_path_name')),
            'proposed_storage_path_id' => Arr::get($item, 'proposed.storage_path_id', Arr::get($item, 'proposed_storage_path_id')),
            'proposed_tags' => Arr::get($item, 'proposed.tags', Arr::get($item, 'proposed_tags', [])),
        ]));
    }

    private function ocrReviewDedupeKey(int $documentId, string $ocrContent): string
    {
        return hash('sha256', 'ocr-review:'.$documentId.':'.hash('sha256', $ocrContent));
    }

    /** @param array<mixed> $value */
    private function stableJson(array $value): string
    {
        $this->sortRecursively($value);

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @param array<mixed> $value */
    private function sortRecursively(array &$value): void
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->sortRecursively($item);
            }
        }
        unset($item);

        if (! array_is_list($value)) {
            ksort($value);
        }
    }

    private function upsertEntityApprovals(ReviewSuggestion $suggestion): int
    {
        $upserted = 0;

        $upserted += $this->upsertEntity($suggestion, EntityApproval::TYPE_CORRESPONDENT, $suggestion->proposed_correspondent_name, $suggestion->proposed_correspondent_id);
        $upserted += $this->upsertEntity($suggestion, EntityApproval::TYPE_DOCUMENT_TYPE, $suggestion->proposed_document_type_name, $suggestion->proposed_document_type_id);

        foreach ($suggestion->proposed_tags ?? [] as $tag) {
            $name = is_array($tag) ? ($tag['name'] ?? null) : $tag;
            $id = is_array($tag) ? ($tag['id'] ?? $tag['paperless_id'] ?? null) : null;
            $upserted += $this->upsertEntity($suggestion, EntityApproval::TYPE_TAG, $name, $id);
        }

        return $upserted;
    }

    private function upsertEntity(ReviewSuggestion $suggestion, string $type, mixed $name, mixed $paperlessId): int
    {
        if (! is_string($name) || trim($name) === '' || is_numeric($paperlessId)) {
            return 0;
        }

        $entity = EntityApproval::query()->firstOrCreate(
            ['type' => $type, 'name' => trim($name)],
            ['source_review_suggestion_id' => $suggestion->id]
        );

        return $entity->wasRecentlyCreated ? 1 : 0;
    }
}
