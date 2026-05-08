<?php

namespace App\Services;

use PDO;
use Throwable;

class LegacyPythonState
{
    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? rtrim((string) config('archibot.data_dir', '/data'), '/').'/classifier.db';
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        return $this->withConnection(function (PDO $pdo): array {
            $statusCounts = $this->keyedCounts($pdo, 'suggestions', 'status');
            $judgeCounts = $this->keyedCounts($pdo, 'suggestions', 'judge_verdict', 'judge_verdict IS NOT NULL');

            return [
                'available' => true,
                'totals' => [
                    'processed_documents' => $this->count($pdo, 'processed_documents'),
                    'embedded_documents' => $this->count($pdo, 'doc_embedding_meta'),
                    'total_errors' => $this->count($pdo, 'errors'),
                    'total_commits' => $this->commitCount($pdo),
                    'auto_commits' => $this->commitCount($pdo, "actor = 'auto'"),
                ],
                'status_counts' => $statusCounts,
                'judge_counts' => $judgeCounts,
                'confidence_distribution' => $this->confidenceDistribution($pdo),
                'phase_health' => $this->phaseHealth($pdo),
            ];
        }) ?? ['available' => false];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentErrors(int $limit = 10): array
    {
        return $this->withConnection(function (PDO $pdo) use ($limit): array {
            if (! $this->tableExists($pdo, 'errors')) {
                return [];
            }

            $statement = $pdo->prepare(
                'SELECT id, occurred_at, stage, document_id, message, details FROM errors ORDER BY occurred_at DESC, id DESC LIMIT :limit'
            );
            $statement->bindValue('limit', max(1, min($limit, 100)), PDO::PARAM_INT);
            $statement->execute();

            return array_map(fn (array $row): array => [
                'id' => (int) $row['id'],
                'occurred_at' => $row['occurred_at'],
                'stage' => $row['stage'],
                'document_reference' => is_numeric($row['document_id']) ? (int) $row['document_id'] : null,
                'message' => $row['message'],
                'details' => $this->decodeJson($row['details'] ?? null),
            ], $statement->fetchAll(PDO::FETCH_ASSOC) ?: []);
        }) ?? [];
    }

    /**
     * @template T
     * @param callable(PDO): T $callback
     * @return T|null
     */
    private function withConnection(callable $callback): mixed
    {
        if (! is_file($this->path)) {
            return null;
        }

        try {
            $pdo = new PDO('sqlite:'.$this->path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            return $callback($pdo);
        } catch (Throwable) {
            return null;
        }
    }

    private function count(PDO $pdo, string $table, ?string $where = null): int
    {
        if (! $this->tableExists($pdo, $table)) {
            return 0;
        }

        $sql = "SELECT COUNT(*) AS c FROM {$table}".($where ? " WHERE {$where}" : '');

        return (int) ($pdo->query($sql)->fetchColumn() ?: 0);
    }

    private function commitCount(PDO $pdo, ?string $extraWhere = null): int
    {
        if (! $this->tableExists($pdo, 'audit_log')) {
            return 0;
        }

        $where = "action = 'commit'".($extraWhere ? " AND {$extraWhere}" : '');

        return $this->count($pdo, 'audit_log', $where);
    }

    /**
     * @return array<string, int>
     */
    private function keyedCounts(PDO $pdo, string $table, string $column, ?string $where = null): array
    {
        if (! $this->tableExists($pdo, $table)) {
            return [];
        }

        $sql = "SELECT {$column} AS k, COUNT(*) AS c FROM {$table}".($where ? " WHERE {$where}" : '')." GROUP BY {$column} ORDER BY {$column}";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $counts = [];
        foreach ($rows as $row) {
            if ($row['k'] !== null && $row['k'] !== '') {
                $counts[(string) $row['k']] = (int) $row['c'];
            }
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private function confidenceDistribution(PDO $pdo): array
    {
        if (! $this->tableExists($pdo, 'suggestions')) {
            return [];
        }

        $rows = $pdo->query(<<<'SQL'
            SELECT
                CASE
                    WHEN confidence IS NULL THEN 'unscored'
                    WHEN confidence < 20 THEN '0-19'
                    WHEN confidence < 40 THEN '20-39'
                    WHEN confidence < 60 THEN '40-59'
                    WHEN confidence < 80 THEN '60-79'
                    ELSE '80-100'
                END AS bucket,
                COUNT(*) AS c
            FROM suggestions
            GROUP BY bucket
            ORDER BY bucket
        SQL)->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['bucket']] = (int) $row['c'];
        }

        return $counts;
    }

    /**
     * @return array<string, array<string, int|float>>
     */
    private function phaseHealth(PDO $pdo): array
    {
        if (! $this->tableExists($pdo, 'phase_timing')) {
            return [];
        }

        $rows = $pdo->query(<<<'SQL'
            SELECT phase,
                   COUNT(*) AS total,
                   SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) AS errors,
                   ROUND(AVG(duration_ms)) AS avg_ms
            FROM phase_timing
            GROUP BY phase
            ORDER BY phase
        SQL)->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $health = [];
        foreach ($rows as $row) {
            $total = (int) ($row['total'] ?? 0);
            $errors = (int) ($row['errors'] ?? 0);
            $health[(string) $row['phase']] = [
                'total' => $total,
                'errors' => $errors,
                'avg_ms' => (int) ($row['avg_ms'] ?? 0),
                'error_rate_pct' => $total > 0 ? round($errors / $total * 100, 1) : 0.0,
            ];
        }

        return $health;
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name");
        $statement->execute(['name' => $table]);

        return (bool) $statement->fetchColumn();
    }

    private function decodeJson(mixed $value): mixed
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }
}
