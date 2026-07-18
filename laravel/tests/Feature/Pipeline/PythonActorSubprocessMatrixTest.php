<?php

namespace Tests\Feature\Pipeline;

use App\Jobs\RunPythonActorJob;
use App\Models\ActorExecution;
use App\Models\Command;
use App\Models\PipelineRun;
use App\Models\WebhookDelivery;
use App\Services\Actors\PythonActorRunner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

class PythonActorSubprocessMatrixTest extends TestCase
{
    private string $databasePath;

    private string|false $originalDatabaseConnection;

    private string|false $originalDatabasePath;

    protected function setUp(): void
    {
        parent::setUp();

        // The fixture is a genuine child process, so an in-memory SQLite
        // connection would give it a different database. Give each matrix case
        // a committed file-backed database shared by parent and child.
        $path = tempnam(sys_get_temp_dir(), 'archibot-actor-matrix-');
        if ($path === false) {
            $this->fail('Unable to create the actor matrix database fixture.');
        }
        $this->databasePath = $path;
        $this->originalDatabaseConnection = getenv('DB_CONNECTION');
        $this->originalDatabasePath = getenv('DB_DATABASE');
        $this->setEnvironment('DB_CONNECTION', 'sqlite');
        $this->setEnvironment('DB_DATABASE', $this->databasePath);
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', $this->databasePath);
        DB::purge('sqlite');
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        if (isset($this->databasePath) && is_file($this->databasePath)) {
            unlink($this->databasePath);
        }
        $this->restoreEnvironment('DB_DATABASE', $this->originalDatabasePath ?? false);
        $this->restoreEnvironment('DB_CONNECTION', $this->originalDatabaseConnection ?? false);

        parent::tearDown();
    }

    private function restoreEnvironment(string $key, string|false $value): void
    {
        if ($value === false) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        $this->setEnvironment($key, $value);
    }

    private function setEnvironment(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    /** @return array<string, array{string, string}> */
    public static function actorFamilies(): array
    {
        return [
            'document' => ['document', 'document'],
            'review' => ['command', Command::TYPE_REVIEW_COMMIT],
            'webhook' => ['webhook', 'webhook'],
            'embedding' => ['command', Command::TYPE_EMBEDDING_INDEX_BUILD],
            'poll' => ['command', Command::TYPE_POLL_RECONCILIATION],
            'reindex' => ['command', Command::TYPE_REINDEX],
            'ocr-reindex' => ['command', Command::TYPE_REINDEX_OCR],
        ];
    }

    /** @return array<string, array{string, string, string}> */
    public static function matrix(): array
    {
        $cases = [];
        foreach (self::actorFamilies() as $family => [$kind, $type]) {
            foreach (['success', 'skipped', 'blocked', 'cancelled', 'retrying', 'failed-permanent', 'malformed', 'missing', 'version-mismatch', 'timeout', 'signal', 'crash'] as $scenario) {
                $cases["{$family}:{$scenario}"] = [$kind, $type, $scenario];
            }
        }

        return $cases;
    }

    #[DataProvider('matrix')]
    public function test_every_actor_family_uses_the_real_process_contract(
        string $kind,
        string $type,
        string $scenario,
    ): void {
        $fixture = base_path('tests/Fixtures/production_actor_process.py');
        chmod($fixture, 0755);
        Config::set('archibot.python_binary', $fixture);
        Config::set('archibot_workers.queue_worker_timeout', 1);
        putenv("ARCHIBOT_ACTOR_FIXTURE_SCENARIO={$scenario}");
        [$job, $source] = $this->sourceAndJob($kind, $type);

        $thrown = null;
        try {
            $job->handle(app(PythonActorRunner::class));
        } catch (Throwable $exception) {
            $thrown = $exception;
        } finally {
            putenv('ARCHIBOT_ACTOR_FIXTURE_SCENARIO');
        }

        // Reopen the parent PDO after the child exits so assertions observe
        // only committed durable state from the file-backed SQLite fixture.
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        $source->refresh();
        $execution = ActorExecution::query()->latest('id')->firstOrFail();
        $eventSourceColumn = match ($kind) {
            'document' => 'pipeline_run_id',
            'webhook' => 'webhook_delivery_id',
            default => 'command_id',
        };
        // This event can only be committed by production ExecutionLifecycle.start
        // in the Python child. The Laravel claim creates only the queued row.
        $this->assertDatabaseHas('pipeline_events', [
            $eventSourceColumn => $source->id,
            'event_type' => 'actor.started',
        ]);
        if ($scenario === 'success') {
            $this->assertNull($thrown);
            $this->assertSame($kind === 'webhook' ? 'processed' : 'succeeded', $source->status);
            $this->assertSame(ActorExecution::STATUS_SUCCEEDED, $execution->status);
        } elseif (in_array($scenario, ['skipped', 'blocked', 'cancelled', 'retrying', 'failed-permanent'], true)) {
            $this->assertNull($thrown);
            $expectedSourceStatus = match ([$kind, $scenario]) {
                ['webhook', 'skipped'] => 'dismissed',
                ['webhook', 'cancelled'], ['webhook', 'failed-permanent'] => 'failed_permanent',
                ['webhook', 'retrying'] => 'failed',
                ['document', 'retrying'] => 'retrying',
                ['command', 'retrying'] => 'pending',
                default => str_replace('-', '_', $scenario),
            };
            $this->assertSame($expectedSourceStatus, $source->status);
            $this->assertSame(str_replace('-', '_', $scenario), $execution->status);
            if ($scenario === 'retrying') {
                $this->assertNotNull($source->next_retry_at);
                $this->assertSame('transient_network', $execution->error_type);
                $this->assertNotNull($execution->next_retry_at);
                $this->assertNull($source->active_actor_token);
                $this->assertSame($claimVersion = $execution->source_version, $source->lifecycle_version);
                $this->assertGreaterThan(0, $claimVersion);
                $this->assertDatabaseHas('pipeline_events', [
                    $eventSourceColumn => $source->id,
                    'event_type' => 'actor.retry_scheduled',
                ]);
            }
        } else {
            $this->assertNotNull($thrown, "{$scenario} must be rejected as transport/protocol failure");
            if (in_array($scenario, ['malformed', 'missing', 'version-mismatch'], true)) {
                // Production Python already atomically committed the durable
                // result. Laravel rejects the transport record but must not
                // overwrite Python-owned persistence.
                $this->assertSame($kind === 'webhook' ? 'processed' : 'succeeded', $source->status);
                $this->assertSame(ActorExecution::STATUS_SUCCEEDED, $execution->status);
            } else {
                // Timeout/signal/crash happen after production lifecycle start
                // and before finish; recovery sees the genuinely running row.
                $this->assertSame('running', $source->status);
                $this->assertSame(ActorExecution::STATUS_RUNNING, $execution->status);
            }
        }
    }

    /** @return array{RunPythonActorJob, Command|PipelineRun|WebhookDelivery} */
    private function sourceAndJob(string $kind, string $type): array
    {
        if ($kind === 'document') {
            $source = PipelineRun::query()->create([
                'type' => 'document', 'status' => PipelineRun::STATUS_QUEUED,
                'scope' => 'single_document', 'trigger_source' => 'manual',
                'paperless_document_id' => 123,
                'pipeline_dedupe_key' => 'matrix-'.uniqid('', true),
                'coalesced_sources' => ['manual'],
            ]);

            return [RunPythonActorJob::documentPipeline($source->id), $source];
        }
        if ($kind === 'webhook') {
            $source = WebhookDelivery::query()->create([
                'source' => 'paperless', 'event_type' => 'document_updated',
                'paperless_document_id' => 123, 'dedupe_key' => 'matrix-'.uniqid('', true),
                'payload_hash' => hash('sha256', uniqid('', true)), 'raw_payload' => [],
                'normalized_payload' => ['webhook_action' => 'refresh_embedding'], 'headers' => [],
                'status' => WebhookDelivery::STATUS_QUEUED, 'request_id' => uniqid('matrix-', true),
                'received_at' => now(),
            ]);

            return [RunPythonActorJob::webhookDelivery($source->id), $source];
        }

        $payload = match ($type) {
            Command::TYPE_REVIEW_COMMIT => ['review_suggestion_id' => 88],
            default => [],
        };
        $source = Command::query()->create([
            'type' => $type, 'status' => Command::STATUS_QUEUED, 'payload' => $payload,
        ]);
        $job = match ($type) {
            Command::TYPE_REVIEW_COMMIT => RunPythonActorJob::reviewCommit($source->id),
            Command::TYPE_EMBEDDING_INDEX_BUILD => RunPythonActorJob::embeddingIndexBuild($source->id),
            Command::TYPE_POLL_RECONCILIATION => RunPythonActorJob::pollReconciliation($source->id),
            Command::TYPE_REINDEX => RunPythonActorJob::reindex($source->id),
            Command::TYPE_REINDEX_OCR => RunPythonActorJob::reindexOcr($source->id),
        };

        return [$job, $source];
    }
}
