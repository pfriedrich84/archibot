<?php

namespace App\Services\Workers;

use App\Models\ReviewSuggestion;
use App\Models\WorkerJob;
use Illuminate\Support\Arr;

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
     * @return array{review_suggestions_imported: int}
     */
    public function ingest(WorkerJob $workerJob): array
    {
        $result = $workerJob->result ?? [];
        $items = Arr::get($result, 'review_suggestions', []);

        if (! is_array($items)) {
            return ['review_suggestions_imported' => 0];
        }

        $imported = 0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $documentId = Arr::get($item, 'paperless_document_id', Arr::get($item, 'document_id'));

            if (! is_numeric($documentId)) {
                continue;
            }

            ReviewSuggestion::query()->create([
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
            ]);

            $imported++;
        }

        return ['review_suggestions_imported' => $imported];
    }
}
