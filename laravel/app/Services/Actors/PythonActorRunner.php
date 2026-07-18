<?php

namespace App\Services\Actors;

use App\Models\ActorExecution;
use App\Models\Command;
use App\Models\PipelineRun;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

class PythonActorRunner
{
    public const ACTOR_BUILD_EMBEDDING_INDEX = 'build_embedding_index';
    public const ACTOR_HANDLE_DOCUMENT_PIPELINE = 'handle_document_pipeline';
    public const ACTOR_COMMIT_REVIEW_SUGGESTION = 'commit_review_suggestion';
    public const ACTOR_POLL_RECONCILIATION = 'reconcile_inbox_documents';
    public const ACTOR_REINDEX = 'reindex';
    public const ACTOR_REINDEX_OCR = 'reindex_ocr';
    public const ACTOR_SYNC_ENTITY_APPROVAL = 'sync_entity_approval';
    public const ACTOR_HANDLE_PAPERLESS_WEBHOOK = 'handle_paperless_webhook';

    public function runEmbeddingIndexBuild(Command $command, ActorInvocationClaim $claim): void
    {
        $this->assertCommandType($command, Command::TYPE_EMBEDDING_INDEX_BUILD);
        $this->runCommandActor($command, $claim, self::ACTOR_BUILD_EMBEDDING_INDEX, ['build-embedding-index', '--command-id', (string) $command->id]);
    }

    public function runPollReconciliation(Command $command, ActorInvocationClaim $claim): void
    {
        $this->assertCommandType($command, Command::TYPE_POLL_RECONCILIATION);
        $this->runCommandActor($command, $claim, self::ACTOR_POLL_RECONCILIATION, ['reconcile-poll', '--command-id', (string) $command->id]);
    }

    public function runReindex(Command $command, ActorInvocationClaim $claim): void
    {
        $this->assertCommandType($command, Command::TYPE_REINDEX);
        $this->runCommandActor($command, $claim, self::ACTOR_REINDEX, ['reindex', '--command-id', (string) $command->id]);
    }

    public function runReindexOcr(Command $command, ActorInvocationClaim $claim): void
    {
        $this->assertCommandType($command, Command::TYPE_REINDEX_OCR);
        $this->runCommandActor($command, $claim, self::ACTOR_REINDEX_OCR, ['reindex-ocr', '--command-id', (string) $command->id]);
    }

    public function runSyncEntityApproval(Command $command, ActorInvocationClaim $claim): void
    {
        $this->assertCommandType($command, Command::TYPE_SYNC_ENTITY_APPROVAL);
        $this->runCommandActor($command, $claim, self::ACTOR_SYNC_ENTITY_APPROVAL, ['sync-entity-approval', '--command-id', (string) $command->id]);
    }

    public function runWebhookDelivery(WebhookDelivery $delivery, ActorInvocationClaim $claim): void
    {
        $this->runProcess(
            self::ACTOR_HANDLE_PAPERLESS_WEBHOOK,
            ['webhook_delivery_id' => $delivery->id, 'paperless_document_id' => $delivery->paperless_document_id],
            ['handle-webhook', '--delivery-id', (string) $delivery->id],
            $claim,
        );
    }

    public function runReviewCommit(Command $command, ActorInvocationClaim $claim): void
    {
        $this->assertCommandType($command, Command::TYPE_REVIEW_COMMIT);
        $this->runCommandActor($command, $claim, self::ACTOR_COMMIT_REVIEW_SUGGESTION, ['commit-review', '--command-id', (string) $command->id]);
    }

    /**
     * @param  array<int, string>  $arguments
     */
    private function runCommandActor(Command $command, ActorInvocationClaim $claim, string $actorName, array $arguments): void
    {
        $this->runProcess($actorName, ['command_id' => $command->id, 'command_type' => $command->type], $arguments, $claim);
    }

    public function runDocumentPipeline(PipelineRun $pipelineRun, ActorInvocationClaim $claim): void
    {
        $this->runProcess(
            self::ACTOR_HANDLE_DOCUMENT_PIPELINE,
            ['pipeline_run_id' => $pipelineRun->id, 'paperless_document_id' => $pipelineRun->paperless_document_id],
            ['process-document', '--pipeline-run-id', (string) $pipelineRun->id],
            $claim,
        );
    }

    /**
     * Process exit and the Python-owned domain result are independent signals.
     * Protocol/launch/timeout/signal failures throw without mutating domain rows.
     *
     * @param  array<string, mixed>  $logContext
     * @param  array<int, string>  $arguments
     */
    private function runProcess(string $actorName, array $logContext, array $arguments, ActorInvocationClaim $claim): void
    {
        $process = new Process(
            [(string) config('archibot.python_binary', 'python3'), '-m', 'app.actor_runner', ...$arguments, '--execution-token', $claim->token, '--source-version', (string) $claim->sourceVersion, '--actor-execution-id', (string) $claim->actorExecutionId, '--attempt', (string) $claim->attempt],
            base_path('..'),
            timeout: max(1, (int) config('archibot_workers.queue_worker_timeout', 21600)),
        );

        Log::info('python actor command starting', ['actor_name' => $actorName, ...$logContext]);
        $process->run();

        $outcome = PythonActorOutcome::fromProcessOutput($process->getOutput());
        [$sourceKind, $sourceId] = $this->durableSourceIdentity($logContext);
        $outcome->assertInvocation($actorName, $sourceKind, $sourceId);

        if ($outcome->status === 'protocol-failure') {
            throw new RuntimeException('Python actor reported a protocol failure.');
        }
        $this->assertDurableExecution($outcome, $claim, $actorName, $sourceKind, $sourceId);
        if (! $process->isSuccessful() && $outcome->status === 'succeeded') {
            throw new RuntimeException('Python actor process failed after reporting a successful domain outcome.');
        }

        Log::log($process->isSuccessful() ? 'info' : 'warning', 'python actor command completed with durable domain outcome', [
            'actor_name' => $actorName,
            'domain_status' => $outcome->status,
            'actor_execution_id' => $outcome->actorExecutionId,
            'attempt' => $outcome->attempt,
            'exit_code' => $process->getExitCode(),
            ...$logContext,
        ]);
    }

    /**
     * @param  array<string, mixed>  $logContext
     * @return array{string, int}
     */
    private function durableSourceIdentity(array $logContext): array
    {
        if (isset($logContext['pipeline_run_id']) && is_int($logContext['pipeline_run_id'])) {
            return ['pipeline_run', $logContext['pipeline_run_id']];
        }
        if (isset($logContext['command_id']) && is_int($logContext['command_id'])) {
            return ['command', $logContext['command_id']];
        }
        if (isset($logContext['webhook_delivery_id']) && is_int($logContext['webhook_delivery_id'])) {
            return ['webhook_delivery', $logContext['webhook_delivery_id']];
        }
        throw new RuntimeException('Python actor invocation has no durable source identity.');
    }

    private function assertDurableExecution(
        PythonActorOutcome $outcome,
        ActorInvocationClaim $claim,
        string $actorName,
        string $sourceKind,
        int $sourceId,
    ): void {
        $sourceColumn = match ($sourceKind) {
            'pipeline_run' => 'pipeline_run_id',
            'command' => 'command_id',
            'webhook_delivery' => 'webhook_delivery_id',
            default => throw new RuntimeException('Unsupported durable actor source kind.'),
        };
        $storedStatus = str_replace('-', '_', $outcome->status);
        $exists = ActorExecution::query()
            ->whereKey($outcome->actorExecutionId)
            ->where('execution_token', $claim->token)
            ->where('source_version', $claim->sourceVersion)
            ->where('actor_name', $actorName)
            ->where($sourceColumn, $sourceId)
            ->where('attempt', $outcome->attempt)
            ->where('status', $storedStatus)
            ->exists();
        if (! $exists) {
            throw new RuntimeException('Python actor outcome does not match its durable fenced execution.');
        }
    }

    private function assertCommandType(Command $command, string $expectedType): void
    {
        if ($command->type !== $expectedType) {
            throw new RuntimeException("Command {$command->id} has type {$command->type}; expected {$expectedType}.");
        }
    }
}
