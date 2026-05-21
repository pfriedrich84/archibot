<?php

namespace App\Support;

use App\Models\AppSetting;
use App\Models\DocumentEmbedding;
use App\Models\EmbeddingIndexState;
use App\Services\Paperless\PaperlessClient;
use Illuminate\Http\Request;
use Throwable;

class EmbeddingIndexSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public function forRequest(Request $request): array
    {
        $state = EmbeddingIndexState::query()->latest()->first();
        $pgvectorEmbeddedCount = DocumentEmbedding::query()
            ->distinct()
            ->count('paperless_document_id');
        $storedRows = DocumentEmbedding::query()->count();
        $stateEmbeddedCount = $state?->embedded_count ?? 0;
        $embeddedCount = max($pgvectorEmbeddedCount, $stateEmbeddedCount);
        $documentCount = $state?->document_count;
        $documentCountError = null;

        if ($documentCount === null || $documentCount <= 0) {
            try {
                $documentCount = $this->paperlessDocumentCount($request);
            } catch (Throwable $exception) {
                $documentCount = null;
                $documentCountError = $exception->getMessage();
            }
        }

        $missingCount = is_int($documentCount)
            ? max(0, $documentCount - $embeddedCount)
            : null;
        $failedCount = $state?->failed_count ?? 0;
        $status = $state?->status;

        if (($status === null || $status === 'missing') && $embeddedCount > 0) {
            $status = $missingCount === 0 && $failedCount === 0 ? EmbeddingIndexState::STATUS_COMPLETE : 'partial';
        }

        $status ??= 'missing';

        $ready = $status === EmbeddingIndexState::STATUS_COMPLETE
            || ($embeddedCount > 0 && $missingCount === 0 && $failedCount === 0);

        return [
            'id' => $state?->id,
            'status' => $ready ? EmbeddingIndexState::STATUS_COMPLETE : $status,
            'embedding_model' => $state?->embedding_model ?: AppSetting::getValue('embedding.model'),
            'dimensions' => $state?->dimensions,
            'document_count' => $documentCount ?? 0,
            'document_count_known' => $documentCount !== null,
            'embedded_count' => $embeddedCount,
            'stored_embedding_rows' => $storedRows,
            'pgvector_embedded_count' => $pgvectorEmbeddedCount,
            'missing_count' => $missingCount,
            'failed_count' => $failedCount,
            'started_at' => $state?->started_at?->toISOString(),
            'completed_at' => $state?->completed_at?->toISOString(),
            'error' => $state?->error,
            'document_count_error' => $documentCountError,
            'ready' => $ready,
        ];
    }

    private function paperlessDocumentCount(Request $request): int
    {
        $paperlessUrl = AppSetting::getValue('paperless.url');
        $token = $request->user()?->paperless_token;

        if (! $paperlessUrl || ! $token) {
            throw new \RuntimeException('Paperless connection is not configured for this user.');
        }

        return app(PaperlessClient::class, ['baseUrl' => $paperlessUrl])->documentCount($token);
    }
}
