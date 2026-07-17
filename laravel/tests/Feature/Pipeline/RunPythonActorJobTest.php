<?php

namespace Tests\Feature\Pipeline;

use App\Jobs\RunPythonActorJob;
use App\Models\Command;
use App\Models\EmbeddingIndexState;
use App\Models\PipelineRun;
use App\Models\WebhookDelivery;
use App\Services\Actors\PythonActorRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class RunPythonActorJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_embedding_actor_job_runs_fixed_python_actor_command(): void
    {
        $capturePath = tempnam(storage_path('framework/testing'), 'archibot-actor-args-');
        $capturePathLiteral = var_export($capturePath, true);
        $script = $this->writeActorStub(<<<PHP
file_put_contents({$capturePathLiteral}, json_encode(\$argv));
PHP);

        Config::set('archibot.python_binary', $script);

        $command = Command::query()->create([
            'type' => Command::TYPE_EMBEDDING_INDEX_BUILD,
            'status' => Command::STATUS_PENDING,
            'payload' => ['limit' => 10],
        ]);

        RunPythonActorJob::embeddingIndexBuild($command->id)->handle(app(PythonActorRunner::class));

        $argv = json_decode((string) file_get_contents($capturePath), true);
        $this->assertSame([
            $script,
            '-m',
            'app.actor_runner',
            'build-embedding-index',
            '--command-id',
            (string) $command->id,
        ], $argv);

        $command->refresh();
        $this->assertSame(Command::STATUS_SUCCEEDED, $command->status);

        @unlink($capturePath);
        @unlink($script);
    }

    public function test_document_pipeline_actor_job_runs_fixed_python_actor_command(): void
    {
        $capturePath = tempnam(storage_path('framework/testing'), 'archibot-actor-args-');
        $capturePathLiteral = var_export($capturePath, true);
        $script = $this->writeActorStub(<<<PHP
file_put_contents({$capturePathLiteral}, json_encode(\$argv));
PHP);

        Config::set('archibot.python_binary', $script);

        EmbeddingIndexState::query()->create(['status' => EmbeddingIndexState::STATUS_COMPLETE]);
        $pipelineRun = PipelineRun::query()->create([
            'type' => 'document',
            'status' => PipelineRun::STATUS_QUEUED,
            'scope' => 'single_document',
            'trigger_source' => 'webhook',
            'paperless_document_id' => 123,
            'pipeline_dedupe_key' => 'dedupe-123',
            'coalesced_sources' => ['webhook'],
        ]);

        RunPythonActorJob::documentPipeline($pipelineRun->id)->handle(app(PythonActorRunner::class));

        $argv = json_decode((string) file_get_contents($capturePath), true);
        $this->assertSame([
            $script,
            '-m',
            'app.actor_runner',
            'process-document',
            '--pipeline-run-id',
            (string) $pipelineRun->id,
        ], $argv);

        @unlink($capturePath);
        @unlink($script);
    }

    public function test_queued_before_close_is_delegated_without_a_parent_lease_or_parent_readiness_mutation(): void
    {
        $run = $this->documentRun(PipelineRun::STATUS_QUEUED, 'queued-before-close');
        EmbeddingIndexState::query()->create(['status' => EmbeddingIndexState::STATUS_BUILDING]);
        $runner = $this->mock(PythonActorRunner::class, function (MockInterface $mock) use ($run): void {
            $mock->shouldReceive('runDocumentPipeline')->once()->withArgs(
                fn (PipelineRun $argument): bool => $argument->is($run),
            );
        });

        RunPythonActorJob::documentPipeline($run->id)->handle($runner);

        $this->assertSame(PipelineRun::STATUS_QUEUED, $run->fresh()->status);
    }

    public function test_recovery_retry_is_delegated_to_the_child_owned_lease_protocol(): void
    {
        $run = $this->documentRun(PipelineRun::STATUS_RETRYING, 'recovery-retry');
        $runner = $this->mock(PythonActorRunner::class, function (MockInterface $mock): void {
            $mock->shouldReceive('runDocumentPipeline')->once();
        });

        RunPythonActorJob::documentPipeline($run->id)->handle($runner);

        $this->assertSame(PipelineRun::STATUS_RETRYING, $run->fresh()->status);
    }

    public function test_manual_retry_is_delegated_without_laravel_claiming_the_python_lease(): void
    {
        $run = $this->documentRun(PipelineRun::STATUS_QUEUED, 'manual-retry');
        $run->forceFill(['retry_mode' => 'manual', 'retry_reason' => 'manual_admin_retry'])->save();
        $runner = $this->mock(PythonActorRunner::class, function (MockInterface $mock): void {
            $mock->shouldReceive('runDocumentPipeline')->once();
        });

        RunPythonActorJob::documentPipeline($run->id)->handle($runner);

        $this->assertSame(PipelineRun::STATUS_QUEUED, $run->fresh()->status);
    }

    public function test_embedding_build_is_delegated_without_parent_exclusive_lease_transfer(): void
    {
        $command = Command::query()->create([
            'type' => Command::TYPE_EMBEDDING_INDEX_BUILD,
            'status' => Command::STATUS_QUEUED,
            'payload' => [],
        ]);
        $runner = $this->mock(PythonActorRunner::class, function (MockInterface $mock): void {
            $mock->shouldReceive('runEmbeddingIndexBuild')->once();
        });

        RunPythonActorJob::embeddingIndexBuild($command->id)->handle($runner);

        $this->assertSame(Command::STATUS_RUNNING, $command->fresh()->status);
    }

    public function test_poll_reconciliation_actor_job_runs_fixed_python_actor_command(): void
    {
        $capturePath = tempnam(storage_path('framework/testing'), 'archibot-actor-args-');
        $capturePathLiteral = var_export($capturePath, true);
        $script = $this->writeActorStub(<<<PHP
file_put_contents({$capturePathLiteral}, json_encode(\$argv));
PHP);

        Config::set('archibot.python_binary', $script);

        $command = Command::query()->create([
            'type' => Command::TYPE_POLL_RECONCILIATION,
            'status' => Command::STATUS_PENDING,
            'payload' => ['limit' => 5],
        ]);

        RunPythonActorJob::pollReconciliation($command->id)->handle(app(PythonActorRunner::class));

        $argv = json_decode((string) file_get_contents($capturePath), true);
        $this->assertSame([
            $script,
            '-m',
            'app.actor_runner',
            'reconcile-poll',
            '--command-id',
            (string) $command->id,
        ], $argv);

        @unlink($capturePath);
        @unlink($script);
    }

    public function test_reindex_actor_job_runs_fixed_python_actor_command(): void
    {
        $capturePath = tempnam(storage_path('framework/testing'), 'archibot-actor-args-');
        $capturePathLiteral = var_export($capturePath, true);
        $script = $this->writeActorStub(<<<PHP
file_put_contents({$capturePathLiteral}, json_encode(\$argv));
PHP);

        Config::set('archibot.python_binary', $script);

        $command = Command::query()->create([
            'type' => Command::TYPE_REINDEX,
            'status' => Command::STATUS_PENDING,
            'payload' => [],
        ]);

        RunPythonActorJob::reindex($command->id)->handle(app(PythonActorRunner::class));

        $argv = json_decode((string) file_get_contents($capturePath), true);
        $this->assertSame([
            $script,
            '-m',
            'app.actor_runner',
            'reindex',
            '--command-id',
            (string) $command->id,
        ], $argv);

        @unlink($capturePath);
        @unlink($script);
    }

    public function test_webhook_delivery_actor_job_runs_fixed_python_actor_command(): void
    {
        $capturePath = tempnam(storage_path('framework/testing'), 'archibot-actor-args-');
        $capturePathLiteral = var_export($capturePath, true);
        $script = $this->writeActorStub(<<<PHP
file_put_contents({$capturePathLiteral}, json_encode(\$argv));
PHP);

        Config::set('archibot.python_binary', $script);

        $delivery = WebhookDelivery::query()->create([
            'source' => 'paperless',
            'event_type' => 'document_updated',
            'paperless_document_id' => 123,
            'dedupe_key' => 'paperless:document_updated:123:test',
            'payload_hash' => hash('sha256', 'test'),
            'raw_payload' => ['document_id' => 123],
            'normalized_payload' => ['webhook_action' => 'refresh_embedding'],
            'headers' => [],
            'status' => WebhookDelivery::STATUS_QUEUED,
            'request_id' => 'test-request',
            'received_at' => now(),
        ]);

        RunPythonActorJob::webhookDelivery($delivery->id)->handle(app(PythonActorRunner::class));

        $argv = json_decode((string) file_get_contents($capturePath), true);
        $this->assertSame([
            $script,
            '-m',
            'app.actor_runner',
            'handle-webhook',
            '--delivery-id',
            (string) $delivery->id,
        ], $argv);

        @unlink($capturePath);
        @unlink($script);
    }

    public function test_review_commit_actor_job_runs_fixed_python_actor_command(): void
    {
        $capturePath = tempnam(storage_path('framework/testing'), 'archibot-actor-args-');
        $capturePathLiteral = var_export($capturePath, true);
        $script = $this->writeActorStub(<<<PHP
file_put_contents({$capturePathLiteral}, json_encode(\$argv));
PHP);

        Config::set('archibot.python_binary', $script);

        $command = Command::query()->create([
            'type' => Command::TYPE_REVIEW_COMMIT,
            'status' => Command::STATUS_PENDING,
            'payload' => ['review_suggestion_id' => 88, 'paperless_document_id' => 123],
        ]);

        RunPythonActorJob::reviewCommit($command->id)->handle(app(PythonActorRunner::class));

        $argv = json_decode((string) file_get_contents($capturePath), true);
        $this->assertSame([
            $script,
            '-m',
            'app.actor_runner',
            'commit-review',
            '--command-id',
            (string) $command->id,
        ], $argv);

        @unlink($capturePath);
        @unlink($script);
    }

    public function test_embedding_actor_job_rejects_wrong_command_type_without_running_process(): void
    {
        $capturePath = tempnam(storage_path('framework/testing'), 'archibot-actor-args-');
        $capturePathLiteral = var_export($capturePath, true);
        $script = $this->writeActorStub(<<<PHP
file_put_contents({$capturePathLiteral}, json_encode(\$argv));
PHP);

        Config::set('archibot.python_binary', $script);

        $command = Command::query()->create([
            'type' => Command::TYPE_POLL_RECONCILIATION,
            'status' => Command::STATUS_PENDING,
            'payload' => [],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expected embedding_index_build');

        try {
            RunPythonActorJob::embeddingIndexBuild($command->id)->handle(app(PythonActorRunner::class));
        } finally {
            $this->assertSame('', (string) file_get_contents($capturePath));
            @unlink($capturePath);
            @unlink($script);
        }
    }

    public function test_duplicate_queued_jobs_claim_command_only_once(): void
    {
        $capturePath = tempnam(storage_path('framework/testing'), 'archibot-actor-args-');
        $capturePathLiteral = var_export($capturePath, true);
        $script = $this->writeActorStub(<<<PHP
file_put_contents({$capturePathLiteral}, "run\n", FILE_APPEND);
PHP);
        Config::set('archibot.python_binary', $script);
        $command = Command::query()->create([
            'type' => Command::TYPE_REINDEX,
            'status' => Command::STATUS_QUEUED,
            'payload' => [],
        ]);
        $first = RunPythonActorJob::reindex($command->id);
        $duplicate = RunPythonActorJob::reindex($command->id);

        $first->handle(app(PythonActorRunner::class));
        $duplicate->handle(app(PythonActorRunner::class));

        $this->assertSame("run\n", file_get_contents($capturePath));
        $this->assertSame(Command::STATUS_SUCCEEDED, $command->fresh()->status);
        @unlink($capturePath);
        @unlink($script);
    }

    public function test_queued_job_does_not_replay_terminal_command(): void
    {
        $capturePath = tempnam(storage_path('framework/testing'), 'archibot-actor-args-');
        $capturePathLiteral = var_export($capturePath, true);
        $script = $this->writeActorStub(<<<PHP
file_put_contents({$capturePathLiteral}, json_encode(\$argv));
PHP);
        Config::set('archibot.python_binary', $script);
        $command = Command::query()->create([
            'type' => Command::TYPE_REVIEW_COMMIT,
            'status' => Command::STATUS_SUCCEEDED,
            'payload' => ['review_suggestion_id' => 88],
            'finished_at' => now(),
        ]);

        RunPythonActorJob::reviewCommit($command->id)->handle(app(PythonActorRunner::class));

        $this->assertSame('', (string) file_get_contents($capturePath));
        $this->assertSame(Command::STATUS_SUCCEEDED, $command->fresh()->status);
        @unlink($capturePath);
        @unlink($script);
    }

    public function test_document_pipeline_actor_job_marks_run_failed_when_process_fails_before_python_state_update(): void
    {
        $script = $this->writeActorStub(<<<'PHP'
fwrite(STDERR, 'document actor failed');
exit(7);
PHP);

        Config::set('archibot.python_binary', $script);

        EmbeddingIndexState::query()->create(['status' => EmbeddingIndexState::STATUS_COMPLETE]);
        $pipelineRun = PipelineRun::query()->create([
            'type' => 'document',
            'status' => PipelineRun::STATUS_QUEUED,
            'scope' => 'single_document',
            'trigger_source' => 'webhook',
            'paperless_document_id' => 123,
            'pipeline_dedupe_key' => 'dedupe-failed-123',
            'coalesced_sources' => ['webhook'],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('failed with exit code 7');

        try {
            RunPythonActorJob::documentPipeline($pipelineRun->id)->handle(app(PythonActorRunner::class));
        } finally {
            $pipelineRun->refresh();
            $this->assertSame(PipelineRun::STATUS_FAILED, $pipelineRun->status);
            $this->assertSame('actor_process_failed', $pipelineRun->error_type);
            $this->assertSame('document actor failed', $pipelineRun->error);
            @unlink($script);
        }
    }

    public function test_embedding_actor_job_marks_command_failed_when_process_fails(): void
    {
        $script = $this->writeActorStub(<<<'PHP'
fwrite(STDERR, 'actor failed');
exit(7);
PHP);

        Config::set('archibot.python_binary', $script);

        $command = Command::query()->create([
            'type' => Command::TYPE_EMBEDDING_INDEX_BUILD,
            'status' => Command::STATUS_PENDING,
            'payload' => [],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('failed with exit code 7');

        try {
            RunPythonActorJob::embeddingIndexBuild($command->id)->handle(app(PythonActorRunner::class));
        } finally {
            $command->refresh();
            $this->assertSame(Command::STATUS_FAILED, $command->status);
            $this->assertSame('actor failed', $command->error);
            @unlink($script);
        }
    }

    private function documentRun(string $status, string $dedupeSuffix): PipelineRun
    {
        return PipelineRun::query()->create([
            'type' => 'document',
            'status' => $status,
            'scope' => 'single_document',
            'trigger_source' => 'manual',
            'paperless_document_id' => 123,
            'pipeline_dedupe_key' => "dedupe-{$dedupeSuffix}",
            'coalesced_sources' => ['manual'],
        ]);
    }

    private function writeActorStub(string $body): string
    {
        $script = tempnam(storage_path('framework/testing'), 'archibot-actor-');
        file_put_contents($script, "#!/usr/bin/env php\n<?php\n".$body);
        chmod($script, 0755);

        return $script;
    }
}
