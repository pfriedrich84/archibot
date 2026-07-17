<?php

namespace Tests\Feature\Pipeline;

use App\Http\Middleware\ValidatePaperlessWebhookRequest;
use App\Models\Command;
use App\Models\EmbeddingIndexState;
use App\Models\PipelineRun;
use App\Models\PollCandidate;
use App\Models\WebhookDelivery;
use App\Services\Pipeline\PipelineStartGate;
use App\Services\Pipeline\PollCandidateConsumer;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** PostgreSQL-only process-concurrency coverage; SQLite cannot model it. */
class PostgresWebhookPollConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_simultaneous_webhook_and_poll_transactions_contend_and_coalesce(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL session advisory locks and process contention.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires pcntl_fork for independent Laravel database sessions.');
        }

        Queue::fake();
        Config::set('archibot.paperless_webhook_secret', 'postgres-concurrency-secret');
        Config::set('archibot.paperless_webhook_rate_limit_per_minute', 60);
        RateLimiter::clear(ValidatePaperlessWebhookRequest::rateLimitKey('127.0.0.1'));
        EmbeddingIndexState::query()->create(['status' => EmbeddingIndexState::STATUS_COMPLETE]);
        $command = Command::query()->create([
            'type' => Command::TYPE_POLL_RECONCILIATION,
            'status' => Command::STATUS_SUCCEEDED,
            'payload' => [],
        ]);
        PollCandidate::query()->create([
            'candidate_id' => '10000000-0000-4000-8000-000000000042',
            'protocol_version' => PollCandidate::PROTOCOL_VERSION,
            'command_id' => $command->id,
            'paperless_document_id' => 42,
            'discovered_modified' => '2026-05-08T12:00:00Z',
            'marker_disposition' => PollCandidate::MARKER_UNCLASSIFIED,
            'trigger_metadata' => ['trigger_source' => 'poll', 'force' => false, 'command_id' => $command->id],
            'idempotency_key' => hash('sha256', 'postgres-webhook-poll-contention'),
            'status' => PollCandidate::STATUS_READY,
        ]);

        $base = tempnam(storage_path('framework/testing'), 'webhook-poll-contention-');
        @unlink($base);
        $go = $base.'.go';
        $pids = [
            $this->forkWorker('webhook', $go, $base, $command->id),
            $this->forkWorker('poll', $go, $base, $command->id),
        ];

        try {
            app(PipelineStartGate::class)->embeddingMutation(function () use ($go, $base): void {
                $this->waitForFile($base.'.webhook.ready');
                $this->waitForFile($base.'.poll.ready');
                file_put_contents($go, 'go');
                $this->waitForFile($base.'.webhook.attempting');
                $this->waitForFile($base.'.poll.attempting');
                $this->waitForAdvisoryWaiters(2);
                $this->assertFileDoesNotExist($base.'.webhook.result');
                $this->assertFileDoesNotExist($base.'.poll.result');
            });

            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }

            DB::disconnect();
            DB::reconnect();
            $webhook = $this->result($base.'.webhook.result');
            $poll = $this->result($base.'.poll.result');
            $this->assertSame(200, $webhook['status']);
            $this->assertSame(['completed' => 1, 'skipped' => 0, 'failed' => 0], $poll);
            $this->assertDatabaseCount('pipeline_runs', 1);
            $run = PipelineRun::query()->firstOrFail();
            $sources = $run->coalesced_sources;
            sort($sources);
            $this->assertSame(['poll', 'webhook'], $sources);
            $candidate = PollCandidate::query()->firstOrFail();
            $this->assertSame($run->id, $candidate->pipeline_run_id);
            $this->assertSame(PollCandidate::STATUS_COMPLETED, $candidate->status);
            $this->assertSame(WebhookDelivery::STATUS_PROCESSED, WebhookDelivery::query()->firstOrFail()->status);
            $this->assertSame($run->id, $webhook['body']['pipeline_run_id']);
        } finally {
            foreach (glob($base.'.*') ?: [] as $path) {
                @unlink($path);
            }
        }
    }

    private function forkWorker(string $worker, string $go, string $base, int $commandId): int
    {
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, "Unable to fork {$worker} contention worker.");
        if ($pid !== 0) {
            return $pid;
        }

        try {
            DB::disconnect();
            DB::reconnect();
            file_put_contents($base.".{$worker}.ready", 'ready');
            $this->waitForFile($go);
            file_put_contents($base.".{$worker}.attempting", 'attempting');
            $result = $worker === 'webhook'
                ? $this->webhookResult()
                : app(PollCandidateConsumer::class)->consumeCommand($commandId);
            file_put_contents($base.".{$worker}.result", json_encode($result, JSON_THROW_ON_ERROR));
            exit(0);
        } catch (\Throwable $exception) {
            file_put_contents($base.".{$worker}.result", json_encode([
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR));
            exit(1);
        }
    }

    /** @return array{status: int, body: mixed} */
    private function webhookResult(): array
    {
        $response = $this->withHeader('X-Webhook-Secret', 'postgres-concurrency-secret')
            ->postJson(route('api.webhooks.paperless'), [
                'event' => 'document_created',
                'document' => ['modified' => '2026-05-08T12:00:00Z'],
                'object' => ['id' => 42],
            ]);

        return ['status' => $response->getStatusCode(), 'body' => $response->json()];
    }

    /** @return array<string, mixed> */
    private function result(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
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

        $this->fail("Timed out waiting for {$expected} PostgreSQL advisory-lock waiters.");
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
