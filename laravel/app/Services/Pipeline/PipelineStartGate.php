<?php

namespace App\Services\Pipeline;

use App\Models\EmbeddingIndexState;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Cross-process PostgreSQL fence between document actors and embedding mutation.
 *
 * Laravel uses short session advisory locks for Pipeline Start and stale
 * transitions. Productive Python children independently own the corresponding
 * shared/exclusive lease for their full mutation lifecycle; Laravel never holds
 * a lease while waiting for a child. PostgreSQL releases only the crashed
 * process's session locks.
 */
class PipelineStartGate
{
    /** Stable, application-owned signed bigint advisory-lock key. */
    private const ADVISORY_LOCK_KEY = 4_701_142_607_001;

    /**
     * Linearize Pipeline Start with embedding transitions without serializing
     * unrelated document actors. The callback owns its transaction boundary so
     * durable run creation can commit before fallible queue dispatch while the
     * shared lock remains held through both operations.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function pipelineStart(Closure $callback): mixed
    {
        return $this->withSessionLock(true, $callback);
    }

    /**
     * Laravel-only exclusive transition helper. Productive build/reindex work
     * obtains its own exclusive lease in the Python child; Laravel never holds
     * this lease while waiting for that child.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function embeddingMutation(Closure $callback): mixed
    {
        return $this->withSessionLock(false, $callback);
    }

    /** Must be called while the caller owns the appropriate fence. */
    public function isOpen(): bool
    {
        return EmbeddingIndexState::query()->latest()->value('status') === EmbeddingIndexState::STATUS_COMPLETE;
    }

    public function markStale(string $reason): EmbeddingIndexState
    {
        return $this->withSessionLock(false, function () use ($reason): EmbeddingIndexState {
            return DB::transaction(function () use ($reason): EmbeddingIndexState {
                $state = EmbeddingIndexState::query()->latest()->lockForUpdate()->first();
                if ($state === null) {
                    return EmbeddingIndexState::query()->create([
                        'status' => EmbeddingIndexState::STATUS_STALE,
                        'error' => $reason,
                    ]);
                }

                $state->forceFill([
                    'status' => EmbeddingIndexState::STATUS_STALE,
                    'error' => $reason,
                ])->save();

                return $state;
            });
        });
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function withSessionLock(bool $shared, Closure $callback): mixed
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'pgsql') {
            // SQLite is used only by the unit suite and cannot model cross-process
            // advisory locking. Production is PostgreSQL-only under ADR-0017.
            return $callback();
        }

        $lockFunction = $shared ? 'pg_advisory_lock_shared' : 'pg_advisory_lock';
        $unlockFunction = $shared ? 'pg_advisory_unlock_shared' : 'pg_advisory_unlock';
        $this->executeAdvisoryFunction($connection, $lockFunction);

        $callbackFailure = null;
        try {
            return $callback();
        } catch (Throwable $exception) {
            $callbackFailure = $exception;
            throw $exception;
        } finally {
            try {
                $released = $this->executeAdvisoryFunction($connection, $unlockFunction);
                if (! in_array($released, [true, 1, '1', 't'], true)) {
                    throw new RuntimeException('PostgreSQL pipeline actor fence was not owned at unlock.');
                }
            } catch (Throwable $unlockFailure) {
                // Never replace the productive actor exception. A lost database
                // session already releases its PostgreSQL advisory locks.
                if ($callbackFailure === null) {
                    throw $unlockFailure;
                }
            }
        }
    }

    private function executeAdvisoryFunction(ConnectionInterface $connection, string $function): mixed
    {
        $row = $connection->selectOne(
            "SELECT {$function}(?) AS fence_result",
            [self::ADVISORY_LOCK_KEY],
        );

        return $row?->fence_result;
    }
}
