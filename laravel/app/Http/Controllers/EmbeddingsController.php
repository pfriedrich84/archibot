<?php

namespace App\Http\Controllers;

use App\Models\Command;
use App\Support\EmbeddingIndexSnapshot;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmbeddingsController extends Controller
{
    public function index(Request $request, EmbeddingIndexSnapshot $snapshots): Response
    {
        $latestEmbeddingBuildCommand = Command::query()
            ->whereIn('type', [Command::TYPE_EMBEDDING_INDEX_BUILD, Command::TYPE_REINDEX])
            ->latest()
            ->first();

        return Inertia::render('processing/Embeddings', [
            'snapshot' => $snapshots->forRequest($request),
            'latestReindexJob' => null,
            'latestEmbeddingBuildCommand' => $latestEmbeddingBuildCommand ? [
                'id' => $latestEmbeddingBuildCommand->id,
                'type' => $latestEmbeddingBuildCommand->type,
                'status' => $latestEmbeddingBuildCommand->status,
                'error' => $latestEmbeddingBuildCommand->error,
                'created_at' => $latestEmbeddingBuildCommand->created_at?->toISOString(),
                'updated_at' => $latestEmbeddingBuildCommand->updated_at?->toISOString(),
            ] : null,
        ]);
    }
}
