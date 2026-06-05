<?php

namespace App\Http\Controllers;

use App\Models\Command;
use App\Models\WorkerJob;
use App\Support\EmbeddingIndexSnapshot;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmbeddingsController extends Controller
{
    public function index(Request $request, EmbeddingIndexSnapshot $snapshots): Response
    {
        $latestReindexJob = WorkerJob::query()
            ->whereIn('type', [
                WorkerJob::TYPE_REINDEX,
                WorkerJob::TYPE_REINDEX_OCR,
                WorkerJob::TYPE_REINDEX_EMBED,
            ])
            ->latest()
            ->first();

        $latestEmbeddingBuildCommand = Command::query()
            ->where('type', Command::TYPE_EMBEDDING_INDEX_BUILD)
            ->latest()
            ->first();

        return Inertia::render('processing/Embeddings', [
            'snapshot' => $snapshots->forRequest($request),
            'latestReindexJob' => $latestReindexJob ? [
                'id' => $latestReindexJob->id,
                'type' => $latestReindexJob->type,
                'status' => $latestReindexJob->status,
                'progress' => $latestReindexJob->progress ?? [],
                'result' => $latestReindexJob->result ?? [],
                'error' => $latestReindexJob->error,
                'created_at' => $latestReindexJob->created_at?->toISOString(),
                'started_at' => $latestReindexJob->started_at?->toISOString(),
                'finished_at' => $latestReindexJob->finished_at?->toISOString(),
            ] : null,
            'latestEmbeddingBuildCommand' => $latestEmbeddingBuildCommand ? [
                'id' => $latestEmbeddingBuildCommand->id,
                'status' => $latestEmbeddingBuildCommand->status,
                'error' => $latestEmbeddingBuildCommand->error,
                'created_at' => $latestEmbeddingBuildCommand->created_at?->toISOString(),
                'updated_at' => $latestEmbeddingBuildCommand->updated_at?->toISOString(),
            ] : null,
        ]);
    }
}
