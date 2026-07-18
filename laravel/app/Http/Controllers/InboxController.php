<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\ReviewSuggestion;
use App\Services\Paperless\PaperlessClient;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class InboxController extends Controller
{
    public function index(Request $request): Response
    {
        $inboxTagId = (int) (AppSetting::getValue('paperless.inbox_tag_id', '0') ?? 0);
        $token = $request->user()->paperless_token;
        $documents = [];
        $inboxTagName = null;
        $error = null;

        if (! $token) {
            $error = 'Paperless connection is not available.';
        } elseif ($inboxTagId <= 0) {
            $error = 'Paperless inbox tag ID is not configured.';
        } else {
            try {
                $client = app(PaperlessClient::class);
                $entityMaps = $this->entityMaps($client, $token);
                $inboxTagName = $entityMaps['tags'][$inboxTagId] ?? null;
                $documents = $this->documentsWithReviewStatus(
                    $client->documents($token, $inboxTagId),
                    $entityMaps,
                );
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }

        return Inertia::render('inbox/Index', [
            'documents' => $documents,
            'inboxTagId' => $inboxTagId,
            'inboxTagName' => $inboxTagName,
            'kpis' => $this->kpis($documents),
            'error' => $error,
        ]);
    }

    /**
     * @return array{correspondents: array<int, string>, documentTypes: array<int, string>, tags: array<int, string>}
     */
    private function entityMaps(PaperlessClient $client, string $token): array
    {
        return [
            'correspondents' => $this->entityMap(fn () => $client->correspondents($token)),
            'documentTypes' => $this->entityMap(fn () => $client->documentTypes($token)),
            'tags' => $this->entityMap(fn () => $client->tags($token)),
        ];
    }

    /**
     * @param  callable(): array<int, array{id: int, name: string}>  $callback
     * @return array<int, string>
     */
    private function entityMap(callable $callback): array
    {
        try {
            return collect($callback())->pluck('name', 'id')->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $documents
     * @param  array{correspondents: array<int, string>, documentTypes: array<int, string>, tags: array<int, string>}  $entityMaps
     * @return array<int, array<string, mixed>>
     */
    private function documentsWithReviewStatus(array $documents, array $entityMaps): array
    {
        $documentIds = collect($documents)
            ->pluck('id')
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->all();

        $suggestions = ReviewSuggestion::query()
            ->whereIn('paperless_document_id', $documentIds)
            ->latest()
            ->get()
            ->unique('paperless_document_id')
            ->keyBy('paperless_document_id');

        return collect($documents)
            ->map(function (array $document) use ($suggestions, $entityMaps): array {
                $documentId = (int) ($document['id'] ?? 0);
                $suggestion = $suggestions->get($documentId);
                $correspondentId = is_numeric($document['correspondent'] ?? null) ? (int) $document['correspondent'] : null;
                $documentTypeId = is_numeric($document['document_type'] ?? null) ? (int) $document['document_type'] : null;
                $tagIds = collect($document['tags'] ?? [])
                    ->filter(fn ($id) => is_numeric($id))
                    ->map(fn ($id) => (int) $id)
                    ->values();

                return [
                    'id' => $documentId,
                    'title' => $document['title'] ?? '',
                    'created_date' => $document['created_date'] ?? null,
                    'correspondent_id' => $correspondentId,
                    'correspondent_name' => $correspondentId ? ($entityMaps['correspondents'][$correspondentId] ?? null) : null,
                    'document_type_id' => $documentTypeId,
                    'document_type_name' => $documentTypeId ? ($entityMaps['documentTypes'][$documentTypeId] ?? null) : null,
                    'tags' => $tagIds->map(fn (int $id): array => [
                        'id' => $id,
                        'name' => $entityMaps['tags'][$id] ?? null,
                    ])->all(),
                    'review' => $suggestion ? [
                        'id' => $suggestion->id,
                        'status' => $suggestion->status,
                        'proposed_title' => $suggestion->proposed_title,
                    ] : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $documents
     * @return array<string, int>
     */
    private function kpis(array $documents): array
    {
        $withReview = collect($documents)->filter(fn (array $document) => $document['review'] !== null)->count();

        return [
            'total' => count($documents),
            'with_review' => $withReview,
            'without_review' => max(0, count($documents) - $withReview),
            'pending_review' => collect($documents)->filter(fn (array $document) => data_get($document, 'review.status') === ReviewSuggestion::STATUS_PENDING)->count(),
        ];
    }
}
