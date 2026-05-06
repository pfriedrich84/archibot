<?php

namespace App\Http\Controllers;

use App\Models\WorkerJob;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use PDO;
use Throwable;

class EmbeddingsController extends Controller
{
    public function index(Request $request): Response
    {
        $limit = min(500, max(1, (int) $request->integer('limit', 100)));
        $snapshot = $this->embeddingSnapshot($limit);

        $latestReindexJob = WorkerJob::query()
            ->where('type', WorkerJob::TYPE_REINDEX)
            ->latest()
            ->first();

        return Inertia::render('processing/Embeddings', [
            'snapshot' => $snapshot,
            'latestReindexJob' => $latestReindexJob ? [
                'id' => $latestReindexJob->id,
                'status' => $latestReindexJob->status,
                'progress' => $latestReindexJob->progress ?? [],
                'result' => $latestReindexJob->result ?? [],
                'error' => $latestReindexJob->error,
                'created_at' => $latestReindexJob->created_at?->toISOString(),
                'started_at' => $latestReindexJob->started_at?->toISOString(),
                'finished_at' => $latestReindexJob->finished_at?->toISOString(),
            ] : null,
        ]);
    }

    /**
     * @return array{db_path: string, error: string|null, total_embedded: int, items: array<int, array<string, mixed>>}
     */
    private function embeddingSnapshot(int $limit): array
    {
        $dbPath = $this->pythonDatabasePath();

        if (! is_file($dbPath)) {
            return [
                'db_path' => $dbPath,
                'error' => 'Python worker database not found yet. Run a reindex job after setup.',
                'total_embedded' => 0,
                'items' => [],
            ];
        }

        try {
            $pdo = new PDO('sqlite:'.$dbPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            $total = (int) $pdo->query('SELECT COUNT(*) AS c FROM doc_embedding_meta')->fetch()['c'];
            $statement = $pdo->prepare(
                'SELECT document_id, title, correspondent, doctype, storage_path, tags_json, created_date, indexed_at
                 FROM doc_embedding_meta
                 ORDER BY indexed_at DESC, document_id DESC
                 LIMIT :limit'
            );
            $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
            $statement->execute();

            $items = array_map(function (array $row): array {
                $tags = json_decode((string) ($row['tags_json'] ?? '[]'), true);
                unset($row['tags_json']);

                $row['tags'] = is_array($tags) ? $tags : [];

                return $row;
            }, $statement->fetchAll());

            return [
                'db_path' => $dbPath,
                'error' => null,
                'total_embedded' => $total,
                'items' => $items,
            ];
        } catch (Throwable $exception) {
            return [
                'db_path' => $dbPath,
                'error' => $exception->getMessage(),
                'total_embedded' => 0,
                'items' => [],
            ];
        }
    }

    private function pythonDatabasePath(): string
    {
        $configured = env('ARCHIBOT_PYTHON_DB_PATH');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $dataDir = env('ARCHIBOT_DATA_DIR', env('DATA_DIR', '/data'));

        return rtrim((string) $dataDir, '/').'/classifier.db';
    }
}
