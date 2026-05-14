<?php

namespace Tests\Feature\Workers;

use App\Models\AppSetting;
use App\Models\EntityApproval;
use App\Models\OcrReview;
use App\Models\ReviewSuggestion;
use App\Models\User;
use App\Models\WorkerJob;
use App\Services\Workers\WorkerResultIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkerResultIngestorTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_review_suggestions_from_worker_result(): void
    {
        $workerJob = WorkerJob::factory()->create([
            'result' => [
                'review_suggestions' => [
                    [
                        'source_suggestion_id' => 456,
                        'paperless_document_id' => 123,
                        'confidence' => 91,
                        'reasoning' => 'Looks like an invoice.',
                        'original' => [
                            'title' => 'Scan 123',
                            'date' => '2026-05-01',
                            'tags' => [['id' => 1, 'name' => 'Inbox']],
                        ],
                        'proposed' => [
                            'title' => 'Invoice May',
                            'date' => '2026-05-02',
                            'correspondent_name' => 'ACME',
                            'document_type_name' => 'Invoice',
                            'tags' => [['id' => 2, 'name' => 'Accounting']],
                        ],
                        'context_documents' => [['id' => 99, 'title' => 'Old invoice']],
                    ],
                ],
            ],
        ]);

        $summary = app(WorkerResultIngestor::class)->ingest($workerJob);

        $this->assertSame(['review_suggestions_imported' => 1, 'entity_approvals_upserted' => 2, 'ocr_reviews_imported' => 0], $summary);
        $suggestion = ReviewSuggestion::query()->firstOrFail();
        $this->assertSame($workerJob->id, $suggestion->worker_job_id);
        $this->assertSame(456, $suggestion->source_suggestion_id);
        $this->assertSame(123, $suggestion->paperless_document_id);
        $this->assertSame(91, $suggestion->confidence);
        $this->assertSame('Scan 123', $suggestion->original_title);
        $this->assertSame('Invoice May', $suggestion->proposed_title);
        $this->assertSame('ACME', $suggestion->proposed_correspondent_name);
        $this->assertSame([['id' => 2, 'name' => 'Accounting']], $suggestion->proposed_tags);
        $this->assertDatabaseHas('entity_approvals', [
            'type' => EntityApproval::TYPE_CORRESPONDENT,
            'name' => 'ACME',
            'status' => EntityApproval::STATUS_PENDING,
        ]);
        $this->assertDatabaseHas('entity_approvals', [
            'type' => EntityApproval::TYPE_DOCUMENT_TYPE,
            'name' => 'Invoice',
            'status' => EntityApproval::STATUS_PENDING,
        ]);
    }

    public function test_ingesting_same_worker_result_twice_does_not_duplicate_review_suggestions(): void
    {
        $workerJob = WorkerJob::factory()->create([
            'result' => [
                'review_suggestions' => [[
                    'source_suggestion_id' => 456,
                    'paperless_document_id' => 123,
                    'confidence' => 91,
                    'proposed' => [
                        'title' => 'Invoice May',
                        'correspondent_name' => 'ACME',
                        'document_type_name' => 'Invoice',
                    ],
                ]],
            ],
        ]);

        $ingestor = app(WorkerResultIngestor::class);
        $ingestor->ingest($workerJob);
        $ingestor->ingest($workerJob->refresh());

        $this->assertDatabaseCount('review_suggestions', 1);
        $this->assertDatabaseCount('entity_approvals', 2);
        $this->assertSame(1, ReviewSuggestion::query()->where('paperless_document_id', 123)->where('source_suggestion_id', 456)->count());
    }

    public function test_ingesting_fallback_review_suggestion_twice_does_not_duplicate(): void
    {
        $workerJob = WorkerJob::factory()->create([
            'result' => [
                'review_suggestions' => [[
                    'paperless_document_id' => 123,
                    'content_hash' => 'document-content-hash',
                    'proposed_title' => 'Invoice May',
                    'proposed_date' => '2026-05-02',
                    'proposed_tags' => [['name' => 'Accounting']],
                ]],
            ],
        ]);

        $ingestor = app(WorkerResultIngestor::class);
        $ingestor->ingest($workerJob);
        $ingestor->ingest($workerJob->refresh());

        $this->assertDatabaseCount('review_suggestions', 1);
        $this->assertNotNull(ReviewSuggestion::query()->firstOrFail()->dedupe_key);
    }

    public function test_ignores_invalid_review_suggestion_items(): void
    {
        $workerJob = WorkerJob::factory()->create([
            'result' => ['review_suggestions' => [['confidence' => 80], 'invalid']],
        ]);

        $summary = app(WorkerResultIngestor::class)->ingest($workerJob);

        $this->assertSame(['review_suggestions_imported' => 0, 'entity_approvals_upserted' => 0, 'ocr_reviews_imported' => 0], $summary);
        $this->assertDatabaseCount('review_suggestions', 0);
    }

    public function test_imports_ocr_reviews_from_worker_result_and_preserves_paperless_content(): void
    {
        AppSetting::put('paperless.url', 'https://paperless.example');
        Http::fake([
            'paperless.example/api/documents/123/' => Http::response([
                'id' => 123,
                'content' => 'Current Paperless content',
            ], 200),
        ]);

        $user = User::factory()->create(['paperless_token' => 'user-token']);
        $workerJob = WorkerJob::factory()->create([
            'created_by_user_id' => $user->id,
            'result' => [
                'ocr_reviews' => [
                    [
                        'paperless_document_id' => 123,
                        'ocr_content' => 'Generated OCR content',
                    ],
                ],
            ],
        ]);

        $summary = app(WorkerResultIngestor::class)->ingest($workerJob);

        $this->assertSame(['review_suggestions_imported' => 0, 'entity_approvals_upserted' => 0, 'ocr_reviews_imported' => 1], $summary);
        $review = OcrReview::query()->firstOrFail();
        $this->assertSame(123, $review->paperless_document_id);
        $this->assertSame('Current Paperless content', $review->original_content);
        $this->assertSame('Generated OCR content', $review->ocr_content);
        $this->assertSame(OcrReview::STATUS_PENDING, $review->status);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'ocr_review.created_from_worker',
            'target_id' => (string) $review->id,
        ]);
    }

    public function test_ingesting_same_ocr_review_twice_does_not_duplicate_ocr_reviews(): void
    {
        AppSetting::put('paperless.url', 'https://paperless.example');
        Http::fake([
            'paperless.example/api/documents/123/' => Http::response([
                'id' => 123,
                'content' => 'Current Paperless content',
            ], 200),
        ]);

        $user = User::factory()->create(['paperless_token' => 'user-token']);
        $workerJob = WorkerJob::factory()->create([
            'created_by_user_id' => $user->id,
            'result' => [
                'ocr_reviews' => [[
                    'paperless_document_id' => 123,
                    'ocr_content' => 'Generated OCR content',
                ]],
            ],
        ]);

        $ingestor = app(WorkerResultIngestor::class);
        $ingestor->ingest($workerJob);
        $ingestor->ingest($workerJob->refresh());

        $this->assertDatabaseCount('ocr_reviews', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertNotNull(OcrReview::query()->firstOrFail()->dedupe_key);
    }

    public function test_entity_approval_upsert_remains_idempotent_by_type_and_name(): void
    {
        EntityApproval::factory()->create([
            'type' => EntityApproval::TYPE_CORRESPONDENT,
            'name' => 'ACME',
        ]);

        $workerJob = WorkerJob::factory()->create([
            'result' => [
                'review_suggestions' => [[
                    'paperless_document_id' => 123,
                    'source_suggestion_id' => 456,
                    'proposed' => [
                        'correspondent_name' => 'ACME',
                        'document_type_name' => 'Invoice',
                    ],
                ]],
            ],
        ]);

        $summary = app(WorkerResultIngestor::class)->ingest($workerJob);

        $this->assertSame(1, $summary['entity_approvals_upserted']);
        $this->assertSame(1, EntityApproval::query()->where('type', EntityApproval::TYPE_CORRESPONDENT)->where('name', 'ACME')->count());
        $this->assertSame(1, EntityApproval::query()->where('type', EntityApproval::TYPE_DOCUMENT_TYPE)->where('name', 'Invoice')->count());
    }

    public function test_worker_imported_ocr_reviews_honor_global_auto_write_mode(): void
    {
        AppSetting::put('paperless.url', 'https://paperless.example');
        AppSetting::put('ocr.auto_write_back', '1');
        Http::fake([
            'paperless.example/api/documents/123/' => Http::sequence()
                ->push(['id' => 123, 'content' => 'Current Paperless content'], 200)
                ->push(['id' => 123], 200),
        ]);

        $user = User::factory()->create(['paperless_token' => 'user-token']);
        $workerJob = WorkerJob::factory()->create([
            'created_by_user_id' => $user->id,
            'result' => [
                'ocr_reviews' => [
                    [
                        'paperless_document_id' => 123,
                        'ocr_content' => 'Generated OCR content',
                    ],
                ],
            ],
        ]);

        $summary = app(WorkerResultIngestor::class)->ingest($workerJob);

        $this->assertSame(1, $summary['ocr_reviews_imported']);
        $review = OcrReview::query()->firstOrFail();
        $this->assertSame(OcrReview::STATUS_WRITTEN_BACK, $review->status);
        $this->assertSame('Generated OCR content', $review->approved_content);
        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && $request['content'] === 'Generated OCR content');
    }
}
