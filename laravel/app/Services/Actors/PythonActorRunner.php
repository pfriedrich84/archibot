<?php

namespace App\Services\Actors;

use App\Models\Command;
use App\Models\PipelineRun;
use App\Models\ReviewSuggestion;
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

    /**
     * Run the fixed embedding-index actor command for a durable command row.
     */
    public function runEmbeddingIndexBuild(Command $command): void
    {
        $this->assertCommandType($command, Command::TYPE_EMBEDDING_INDEX_BUILD);

        if ($command->status === Command::STATUS_PENDING) {
            $command->forceFill(['status' => Command::STATUS_QUEUED])->save();
        }

        $this->runProcess(
            actorName: self::ACTOR_BUILD_EMBEDDING_INDEX,
            logContext: [
                'command_id' => $command->id,
                'command_type' => $command->type,
            ],
            arguments: [
                'build-embedding-index',
                '--command-id',
                (string) $command->id,
            ],
            onFailure: function (string $error) use ($command): void {
                $command->refresh();
                if (! in_array($command->status, [Command::STATUS_FAILED, Command::STATUS_FAILED_PERMANENT], true)) {
                    $command->forceFill([
                        'status' => Command::STATUS_FAILED,
                        'error' => $error,
                        'finished_at' => now(),
                    ])->save();
                }
            },
            onSuccess: function () use ($command): void {
                $command->refresh();
                if (in_array($command->status, [Command::STATUS_QUEUED, Command::STATUS_RUNNING], true)) {
                    $command->forceFill([
                        'status' => Command::STATUS_SUCCEEDED,
                        'error' => null,
                        'finished_at' => now(),
                    ])->save();
                }
            },
        );
    }

    /**
     * Run the fixed poll reconciliation actor command for a durable command row.
     */
    public function runPollReconciliation(Command $command): void
    {
        $this->assertCommandType($command, Command::TYPE_POLL_RECONCILIATION);
        $this->runCommandActor($command, self::ACTOR_POLL_RECONCILIATION, ['reconcile-poll', '--command-id', (string) $command->id]);
    }

    /**
     * Run the fixed reindex actor command for a durable command row.
     */
    public function runReindex(Command $command): void
    {
        $this->assertCommandType($command, Command::TYPE_REINDEX);
        $this->runCommandActor($command, self::ACTOR_REINDEX, ['reindex', '--command-id', (string) $command->id]);
    }

    /**
     * Run the fixed OCR reindex actor command for a durable command row.
     */
    public function runReindexOcr(Command $command): void
    {
        $this->assertCommandType($command, Command::TYPE_REINDEX_OCR);
        $this->runCommandActor($command, self::ACTOR_REINDEX_OCR, ['reindex-ocr', '--command-id', (string) $command->id]);
    }

    /**
     * Run the fixed entity approval sync actor command for a durable command row.
     */
    public function runSyncEntityApproval(Command $command): void
    {
        $this->assertCommandType($command, Command::TYPE_SYNC_ENTITY_APPROVAL);
        $this->runCommandActor($command, self::ACTOR_SYNC_ENTITY_APPROVAL, ['sync-entity-approval', '--command-id', (string) $command->id]);
    }

    /**
     * Run the fixed webhook actor command for a durable webhook delivery row.
     */
    public function runWebhookDelivery(WebhookDelivery $delivery): void
    {
        $this->runProcess(
            actorName: self::ACTOR_HANDLE_PAPERLESS_WEBHOOK,
            logContext: [
                'webhook_delivery_id' => $delivery->id,
                'paperless_document_id' => $delivery->paperless_document_id,
                'webhook_action' => $delivery->normalized_payload['webhook_action'] ?? null,
            ],
            arguments: [
                'handle-webhook',
                '--delivery-id',
                (string) $delivery->id,
            ],
            onFailure: function (string $error) use ($delivery): void {
                $delivery->refresh();
                if (! in_array($delivery->status, [WebhookDelivery::STATUS_FAILED, WebhookDelivery::STATUS_FAILED_PERMANENT], true)) {
                    $delivery->forceFill([
                        'status' => WebhookDelivery::STATUS_FAILED,
                        'error' => $error,
                    ])->save();
                }
            },
        );
    }

    /**
     * Run the fixed review commit actor command for a durable command row.
     */
    public function runReviewCommit(Command $command): void
    {
        $this->assertCommandType($command, Command::TYPE_REVIEW_COMMIT);

        if ($command->status === Command::STATUS_PENDING) {
            $command->forceFill(['status' => Command::STATUS_QUEUED])->save();
        }

        $this->runProcess(
            actorName: self::ACTOR_COMMIT_REVIEW_SUGGESTION,
            logContext: [
                'command_id' => $command->id,
                'command_type' => $command->type,
                'review_suggestion_id' => $command->payload['review_suggestion_id'] ?? null,
            ],
            arguments: [
                'commit-review',
                '--command-id',
                (string) $command->id,
            ],
            onFailure: function (string $error) use ($command): void {
                $command->refresh();
                if (! in_array($command->status, [Command::STATUS_FAILED, Command::STATUS_FAILED_PERMANENT], true)) {
                    $command->forceFill([
                        'status' => Command::STATUS_FAILED,
                        'error' => $error,
                        'finished_at' => now(),
                    ])->save();
                }

                $reviewSuggestionId = $command->payload['review_suggestion_id'] ?? null;
                if (is_int($reviewSuggestionId)) {
                    ReviewSuggestion::query()
                        ->whereKey($reviewSuggestionId)
                        ->where('commit_status', ReviewSuggestion::COMMIT_STATUS_QUEUED)
                        ->update(['commit_status' => ReviewSuggestion::COMMIT_STATUS_FAILED]);
                }
            },
        );
    }

    /**
     * @param  array<int, string>  $arguments
     */
    private function runCommandActor(Command $command, string $actorName, array $arguments): void
    {
        if ($command->status === Command::STATUS_PENDING) {
            $command->forceFill(['status' => Command::STATUS_QUEUED])->save();
        }

        $this->runProcess(
            actorName: $actorName,
            logContext: [
                'command_id' => $command->id,
                'command_type' => $command->type,
            ],
            arguments: $arguments,
            onFailure: function (string $error) use ($command): void {
                $command->refresh();
                if (! in_array($command->status, [Command::STATUS_FAILED, Command::STATUS_FAILED_PERMANENT], true)) {
                    $command->forceFill([
                        'status' => Command::STATUS_FAILED,
                        'error' => $error,
                        'finished_at' => now(),
                    ])->save();
                }
            },
            onSuccess: function () use ($command): void {
                $command->refresh();
                if (in_array($command->status, [Command::STATUS_QUEUED, Command::STATUS_RUNNING], true)) {
                    $command->forceFill([
                        'status' => Command::STATUS_SUCCEEDED,
                        'error' => null,
                        'finished_at' => now(),
                    ])->save();
                }
            },
        );
    }

    /**
     * Run the fixed document pipeline actor command for a durable pipeline run.
     */
    public function runDocumentPipeline(PipelineRun $pipelineRun): void
    {
        $this->runProcess(
            actorName: self::ACTOR_HANDLE_DOCUMENT_PIPELINE,
            logContext: [
                'pipeline_run_id' => $pipelineRun->id,
                'paperless_document_id' => $pipelineRun->paperless_document_id,
            ],
            arguments: [
                'process-document',
                '--pipeline-run-id',
                (string) $pipelineRun->id,
            ],
            onFailure: function (string $error) use ($pipelineRun): void {
                $pipelineRun->refresh();
                if (! in_array($pipelineRun->status, [
                    PipelineRun::STATUS_FAILED,
                    PipelineRun::STATUS_FAILED_PERMANENT,
                    PipelineRun::STATUS_RETRYING,
                    PipelineRun::STATUS_CANCELLED,
                    PipelineRun::STATUS_BLOCKED,
                ], true)) {
                    $pipelineRun->forceFill([
                        'status' => PipelineRun::STATUS_FAILED,
                        'error_type' => 'actor_process_failed',
                        'error' => $error,
                        'finished_at' => now(),
                    ])->save();
                }
            },
        );
    }

    /**
     * @param  array<string, mixed>  $logContext
     * @param  array<int, string>  $arguments
     */
    private function runProcess(
        string $actorName,
        array $logContext,
        array $arguments,
        ?callable $onFailure = null,
        ?callable $onSuccess = null,
    ): void {
        $process = new Process(
            [
                (string) config('archibot.python_binary', 'python3'),
                '-m',
                'app.actor_runner',
                ...$arguments,
            ],
            base_path('..'),
            timeout: null,
        );

        Log::info('python actor command starting', [
            'actor_name' => $actorName,
            ...$logContext,
        ]);

        $process->run();

        if (! $process->isSuccessful()) {
            $errorOutput = trim($process->getErrorOutput() ?: $process->getOutput() ?: 'Python actor command failed.');
            if ($onFailure !== null) {
                $onFailure($errorOutput);
            }

            Log::warning('python actor command failed', [
                'actor_name' => $actorName,
                'exit_code' => $process->getExitCode(),
                ...$logContext,
            ]);

            $suffix = $errorOutput !== '' ? " Output: {$errorOutput}" : '';

            throw new RuntimeException("Python actor command {$actorName} failed with exit code {$process->getExitCode()}.{$suffix}");
        }

        if ($onSuccess !== null) {
            $onSuccess();
        }

        Log::info('python actor command succeeded', [
            'actor_name' => $actorName,
            ...$logContext,
        ]);
    }

    private function assertCommandType(Command $command, string $expectedType): void
    {
        if ($command->type !== $expectedType) {
            throw new RuntimeException("Command {$command->id} has type {$command->type}; expected {$expectedType}.");
        }
    }
}
