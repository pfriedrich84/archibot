<?php

namespace App\Http\Controllers;

use App\Models\Command;
use App\Support\DiagnosticPresenter;
use App\Support\EmbeddingIndexSnapshot;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmbeddingsController extends Controller
{
    public function __construct(private readonly DiagnosticPresenter $diagnostics) {}

    public function index(Request $request, EmbeddingIndexSnapshot $snapshots): Response
    {
        $snapshot = $this->diagnostics->embeddingSnapshot($snapshots->forRequest($request));

        $latestEmbeddingBuildCommand = Command::query()
            ->whereIn('type', [Command::TYPE_EMBEDDING_INDEX_BUILD, Command::TYPE_REINDEX])
            ->latest()
            ->first();

        return Inertia::render('processing/Embeddings', [
            'snapshot' => $snapshot,
            'latestReindexJob' => null,
            'latestEmbeddingBuildCommand' => $latestEmbeddingBuildCommand ? [
                'id' => $latestEmbeddingBuildCommand->id,
                'type' => $this->diagnostics->typedScalar('command_type', $latestEmbeddingBuildCommand->type),
                'status' => $this->diagnostics->typedScalar('status', $latestEmbeddingBuildCommand->status),
                'queue' => $latestEmbeddingBuildCommand->queue === null ? null : $this->diagnostics->queueName($latestEmbeddingBuildCommand->queue),
                'priority' => is_int($latestEmbeddingBuildCommand->priority) ? $latestEmbeddingBuildCommand->priority : null,
                'error' => $this->diagnostics->redactedMessage($latestEmbeddingBuildCommand->error),
                'created_at' => $latestEmbeddingBuildCommand->created_at?->toISOString(),
                'updated_at' => $latestEmbeddingBuildCommand->updated_at?->toISOString(),
            ] : null,
        ]);
    }
}
