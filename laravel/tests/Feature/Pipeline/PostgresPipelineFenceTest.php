<?php

namespace Tests\Feature\Pipeline;

use App\Models\EmbeddingIndexState;
use App\Models\PipelineRun;
use App\Services\Pipeline\DocumentPipelineStarter;
use App\Services\Pipeline\PipelineStartGate;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** PostgreSQL-only coverage for the cross-process actor/build fence. */
class PostgresPipelineFenceTest extends TestCase
{
    use DatabaseMigrations;

    public function test_embedding_mutation_waits_for_complete_document_actor_lease(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL session advisory locks.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires pcntl_fork for independent database sessions.');
        }

        $base = tempnam(storage_path('framework/testing'), 'pipeline-fence-');
        @unlink($base);
        $go = $base.'.go';
        $attempting = $base.'.attempting';
        $acquired = $base.'.acquired';
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'Unable to fork PostgreSQL fence test process.');

        if ($pid === 0) {
            try {
                DB::disconnect();
                DB::reconnect();
                $this->waitForFile($go);
                file_put_contents($attempting, 'attempting');
                app(PipelineStartGate::class)->embeddingMutation(function () use ($acquired): void {
                    file_put_contents($acquired, 'acquired');
                });
                exit(0);
            } catch (\Throwable $exception) {
                file_put_contents($acquired, 'error:'.$exception->getMessage());
                exit(1);
            }
        }

        try {
            // Model the dedicated session owned by the productive Python
            // child. It is independent of any Laravel parent connection.
            DB::selectOne('SELECT pg_advisory_lock_shared(?)', [4_701_142_607_001]);
            try {
                file_put_contents($go, 'go');
                $this->waitForFile($attempting);
                $this->waitForAdvisoryWaiters(1);
                $this->assertFileDoesNotExist(
                    $acquired,
                    'Exclusive embedding mutation entered while a Python child shared lease was held.',
                );
            } finally {
                DB::selectOne('SELECT pg_advisory_unlock_shared(?)', [4_701_142_607_001]);
            }

            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status), (string) @file_get_contents($acquired));
            $this->assertSame('acquired', file_get_contents($acquired));
        } finally {
            foreach ([$go, $attempting, $acquired] as $path) {
                @unlink($path);
            }
        }
    }

    public function test_parent_session_death_does_not_release_live_python_child_lease(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL session advisory locks.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires pcntl_fork for independent database sessions.');
        }

        $base = tempnam(storage_path('framework/testing'), 'pipeline-parent-death-');
        @unlink($base);
        $childReady = $base.'.child-ready';
        $releaseChild = $base.'.release-child';
        $exclusiveAcquired = $base.'.exclusive-acquired';

        $childPid = pcntl_fork();
        $this->assertNotSame(-1, $childPid);
        if ($childPid === 0) {
            DB::disconnect();
            DB::reconnect();
            DB::selectOne('SELECT pg_advisory_lock_shared(?)', [4_701_142_607_001]);
            file_put_contents($childReady, 'ready');
            $this->waitForFile($releaseChild);
            DB::selectOne('SELECT pg_advisory_unlock_shared(?)', [4_701_142_607_001]);
            exit(0);
        }

        try {
            $this->waitForFile($childReady);
            // Closing the Laravel parent session models queue-worker SIGKILL.
            // The independent child session must continue to own its lease.
            DB::disconnect();
            DB::reconnect();

            $exclusivePid = pcntl_fork();
            $this->assertNotSame(-1, $exclusivePid);
            if ($exclusivePid === 0) {
                DB::disconnect();
                DB::reconnect();
                app(PipelineStartGate::class)->embeddingMutation(function () use ($exclusiveAcquired): void {
                    file_put_contents($exclusiveAcquired, 'acquired');
                });
                exit(0);
            }

            $this->waitForAdvisoryWaiters(1);
            $this->assertFileDoesNotExist($exclusiveAcquired);
            file_put_contents($releaseChild, 'release');
            pcntl_waitpid($childPid, $childStatus);
            pcntl_waitpid($exclusivePid, $exclusiveStatus);
            $this->assertSame(0, pcntl_wexitstatus($childStatus));
            $this->assertSame(0, pcntl_wexitstatus($exclusiveStatus));
            $this->assertSame('acquired', file_get_contents($exclusiveAcquired));
        } finally {
            foreach ([$childReady, $releaseChild, $exclusiveAcquired] as $path) {
                @unlink($path);
            }
        }
    }

    public function test_exclusive_stale_transition_wins_before_waiting_pipeline_start(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL session advisory locks.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires pcntl_fork for independent database sessions.');
        }

        Queue::fake();
        EmbeddingIndexState::query()->create(['status' => EmbeddingIndexState::STATUS_COMPLETE]);
        $base = tempnam(storage_path('framework/testing'), 'pipeline-start-fence-');
        @unlink($base);
        $go = $base.'.go';
        $attempting = $base.'.attempting';
        $result = $base.'.result';
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'Unable to fork PostgreSQL start-fence process.');

        if ($pid === 0) {
            try {
                DB::disconnect();
                DB::reconnect();
                $this->waitForFile($go);
                file_put_contents($attempting, 'attempting');
                $started = app(DocumentPipelineStarter::class)->start(
                    'poll',
                    42,
                    '2026-05-08T12:00:00Z',
                );
                file_put_contents($result, json_encode([
                    'outcome' => $started->outcome,
                    'status' => $started->pipelineRun->status,
                ], JSON_THROW_ON_ERROR));
                exit(0);
            } catch (\Throwable $exception) {
                file_put_contents($result, 'error:'.$exception->getMessage());
                exit(1);
            }
        }

        try {
            app(PipelineStartGate::class)->embeddingMutation(function () use ($go, $attempting, $result): void {
                file_put_contents($go, 'go');
                $this->waitForFile($attempting);
                $this->waitForAdvisoryWaiters(1);
                EmbeddingIndexState::query()->create(['status' => EmbeddingIndexState::STATUS_STALE]);
                $this->assertFileDoesNotExist($result);
            });

            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status), (string) @file_get_contents($result));
            $payload = json_decode((string) file_get_contents($result), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('blocked', $payload['outcome']);
            $this->assertSame(PipelineRun::STATUS_BLOCKED, $payload['status']);
            $this->assertDatabaseCount('pipeline_runs', 1);
            $this->assertSame(PipelineRun::STATUS_BLOCKED, PipelineRun::query()->firstOrFail()->status);
        } finally {
            foreach ([$go, $attempting, $result] as $path) {
                @unlink($path);
            }
        }
    }

    private function waitForAdvisoryWaiters(int $expected): void
    {
        $deadline = microtime(true) + 10;
        do {
            $row = DB::selectOne(<<<'SQL'
                SELECT COUNT(*) AS aggregate
                FROM pg_locks
                WHERE locktype = 'advisory' AND granted = false
                SQL);
            if ((int) $row->aggregate >= $expected) {
                return;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        $this->fail("Timed out waiting for {$expected} PostgreSQL advisory-lock waiter(s).");
    }

    private function waitForFile(string $path): void
    {
        $deadline = microtime(true) + 10;
        while (! file_exists($path)) {
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException("Timed out waiting for process barrier {$path}.");
            }
            usleep(10_000);
        }
    }
}
