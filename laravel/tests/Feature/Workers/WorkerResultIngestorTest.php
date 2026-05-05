<?php

namespace Tests\Feature\Workers;

use App\Models\ReviewSuggestion;
use App\Models\WorkerJob;
use App\Services\Workers\WorkerResultIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->assertSame(['review_suggestions_imported' => 1], $summary);
        $suggestion = ReviewSuggestion::query()->firstOrFail();
        $this->assertSame(123, $suggestion->paperless_document_id);
        $this->assertSame(91, $suggestion->confidence);
        $this->assertSame('Scan 123', $suggestion->original_title);
        $this->assertSame('Invoice May', $suggestion->proposed_title);
        $this->assertSame('ACME', $suggestion->proposed_correspondent_name);
        $this->assertSame([['id' => 2, 'name' => 'Accounting']], $suggestion->proposed_tags);
    }

    public function test_ignores_invalid_review_suggestion_items(): void
    {
        $workerJob = WorkerJob::factory()->create([
            'result' => ['review_suggestions' => [['confidence' => 80], 'invalid']],
        ]);

        $summary = app(WorkerResultIngestor::class)->ingest($workerJob);

        $this->assertSame(['review_suggestions_imported' => 0], $summary);
        $this->assertDatabaseCount('review_suggestions', 0);
    }
}
