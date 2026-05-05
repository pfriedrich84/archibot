<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\ReviewSuggestion;
use App\Services\Paperless\PaperlessClient;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InboxController extends Controller
{
    public function index(Request $request): Response
    {
        $paperlessUrl = AppSetting::getValue('paperless.url');
        $inboxTagId = (int) (AppSetting::getValue('paperless.inbox_tag_id', '0') ?? 0);
        $token = $request->user()->paperless_token;
        $documents = [];
        $error = null;

        if (! $paperlessUrl || ! $token) {
            $error = 'Paperless connection is not available.';
        } elseif ($inboxTagId <= 0) {
            $error = 'Paperless inbox tag ID is not configured.';
        } else {
            try {
                $documents = $this->documentsWithReviewStatus(
                    app(PaperlessClient::class, ['baseUrl' => $paperlessUrl])->documents($token, $inboxTagId)
                );
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
            }
        }

        return Inertia::render('inbox/Index', [
            'documents' => $documents,
            'inboxTagId' => $inboxTagId,
            'error' => $error,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $documents
     * @return array<int, array<string, mixed>>
     */
    private function documentsWithReviewStatus(array $documents): array
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
            ->map(function (array $document) use ($suggestions): array {
                $documentId = (int) ($document['id'] ?? 0);
                $suggestion = $suggestions->get($documentId);

                return [
                    'id' => $documentId,
                    'title' => $document['title'] ?? '',
                    'created_date' => $document['created_date'] ?? null,
                    'correspondent' => $document['correspondent'] ?? null,
                    'document_type' => $document['document_type'] ?? null,
                    'tags' => $document['tags'] ?? [],
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
}
