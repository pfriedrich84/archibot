<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\WorkerJob;
use App\Services\Paperless\PaperlessClient;
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
        $snapshot = $this->embeddingSnapshot($request, $limit);

        $latestReindexJob = WorkerJob::query()
            ->whereIn('type', [
                WorkerJob::TYPE_REINDEX,
                WorkerJob::TYPE_REINDEX_OCR,
                WorkerJob::TYPE_REINDEX_EMBED,
            ])
            ->latest()
            ->first();

        return Inertia::render('processing/Embeddings', [
            'snapshot' => $snapshot,
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
        ]);
    }

    /**
     * @return array{db_path: string, error: string|null, total_embedded: int, items: array<int, array<string, mixed>>}
     */
    private function embeddingSnapshot(Request $request, int $limit): array
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

            $entityMaps = $this->entityMaps($request);

            $items = array_map(function (array $row) use ($entityMaps): array {
                $correspondentId = is_numeric($row['correspondent'] ?? null) ? (int) $row['correspondent'] : null;
                $doctypeId = is_numeric($row['doctype'] ?? null) ? (int) $row['doctype'] : null;
                $storagePathId = is_numeric($row['storage_path'] ?? null) ? (int) $row['storage_path'] : null;
                $row['correspondent_name'] = $correspondentId ? ($entityMaps['correspondents'][$correspondentId] ?? null) : null;
                $row['doctype_name'] = $doctypeId ? ($entityMaps['documentTypes'][$doctypeId] ?? null) : null;
                $row['storage_path_name'] = $storagePathId ? ($entityMaps['storagePaths'][$storagePathId] ?? null) : null;
                $tags = json_decode((string) ($row['tags_json'] ?? '[]'), true);
                unset($row['tags_json']);

                $row['tags'] = collect(is_array($tags) ? $tags : [])
                    ->filter(fn ($tag) => is_numeric($tag) || is_string($tag))
                    ->map(function ($tag) use ($entityMaps): array {
                        $id = is_numeric($tag) ? (int) $tag : null;

                        return [
                            'id' => $id,
                            'name' => $id ? ($entityMaps['tags'][$id] ?? null) : (string) $tag,
                        ];
                    })
                    ->values()
                    ->all();

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

    /**
     * @return array{correspondents: array<int, string>, documentTypes: array<int, string>, storagePaths: array<int, string>, tags: array<int, string>}
     */
    private function entityMaps(Request $request): array
    {
        $empty = [
            'correspondents' => [],
            'documentTypes' => [],
            'storagePaths' => [],
            'tags' => [],
        ];

        $paperlessUrl = AppSetting::getValue('paperless.url');
        $token = $request->user()?->paperless_token;

        if (! $paperlessUrl || ! $token) {
            return $empty;
        }

        try {
            $client = app(PaperlessClient::class, ['baseUrl' => $paperlessUrl]);

            return [
                'correspondents' => collect($client->correspondents($token))->pluck('name', 'id')->all(),
                'documentTypes' => collect($client->documentTypes($token))->pluck('name', 'id')->all(),
                'storagePaths' => collect($client->storagePaths($token))->pluck('name', 'id')->all(),
                'tags' => collect($client->tags($token))->pluck('name', 'id')->all(),
            ];
        } catch (Throwable) {
            return $empty;
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
