<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ReviewSuggestion;
use App\Services\Paperless\PaperlessClient;
use App\Services\Paperless\PaperlessDocumentPermissions;
use App\Services\Pipeline\DocumentPipelineStarter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ClassifyWithArchiBotController extends Controller
{
    public function __construct(private readonly PaperlessDocumentPermissions $permissions) {}

    public function create(Request $request): Response
    {
        abort_unless($request->user() !== null, 403);

        return Inertia::render('review/ClassifyWithArchiBot', [
            'actions' => [
                'store' => route('classify-with-archibot.store'),
            ],
        ]);
    }

    public function store(Request $request, DocumentPipelineStarter $starter): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 403);

        $validated = $request->validate([
            'paperless_document_id' => ['required', 'integer', 'min:1'],
        ]);

        $documentId = (int) $validated['paperless_document_id'];
        $this->permissions->assertCanViewDocument($user, $documentId);
        $this->permissions->assertCanChangeDocument($user, $documentId);

        $document = app(PaperlessClient::class)->document($user->paperless_token, $documentId);
        $latestVersion = collect($document['versions'] ?? [])->sortByDesc('id')->first();
        $paperlessVersionId = is_array($latestVersion) ? (int) ($latestVersion['id'] ?? 0) : 0;
        $paperlessVersionChecksum = is_array($latestVersion)
            ? (string) ($latestVersion['checksum'] ?? '')
            : (string) ($document['checksum'] ?? '');

        abort_if($paperlessVersionId < 1 || $paperlessVersionChecksum === '', 409, 'Paperless document version is not verifiable.');

        $result = $starter->start(
            triggerSource: 'manual',
            paperlessDocumentId: $documentId,
            paperlessModified: isset($document['modified']) ? (string) $document['modified'] : null,
            forceNewRun: true,
            reprocessRequested: true,
            reprocessReason: 'classify_with_archibot',
            reprocessMode: 'manual',
            requestedByUserId: $user->id,
        );

        DB::transaction(function () use ($user, $documentId, $paperlessVersionId, $paperlessVersionChecksum, $result): void {
            ReviewSuggestion::query()->create([
                'pipeline_run_id' => $result->pipelineRun->id,
                'paperless_document_id' => $documentId,
                'paperless_version_id' => $paperlessVersionId,
                'paperless_version_checksum' => $paperlessVersionChecksum,
                'origin' => 'manual_archibot',
                'status' => ReviewSuggestion::STATUS_PENDING,
                'reasoning' => 'Manual Classify with ArchiBot request created a document-bound review placeholder.',
                'requested_by_user_id' => $user->id,
                'created_by_user_id' => $user->id,
                'request_source' => 'classify_with_archibot',
                'context_quality' => 'none',
                'context_document_count' => 0,
                'proposed_tags' => [],
                'original_tags' => [],
                'context_documents' => [],
            ]);

            AuditLog::query()->create([
                'actor_user_id' => $user->id,
                'event' => 'review_suggestion.classify_with_archibot_requested',
                'target_type' => 'pipeline_run',
                'target_id' => (string) $result->pipelineRun->id,
                'metadata' => [
                    'paperless_document_id' => $documentId,
                    'paperless_version_id' => $paperlessVersionId,
                    'request_source' => 'classify_with_archibot',
                ],
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        });

        return redirect()->route('review.index')->with('status', 'Classify with ArchiBot was queued.');
    }
}
