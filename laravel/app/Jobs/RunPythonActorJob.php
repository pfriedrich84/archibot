<?php

namespace App\Jobs;

use App\Models\Command;
use App\Models\PipelineRun;
use App\Models\WebhookDelivery;
use App\Services\Actors\PythonActorRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;

class RunPythonActorJob implements ShouldQueue
{
    use Queueable;

    /**
     * Actor commands can run for a long time while local embedding/LLM work is
     * active. Durable command/pipeline state records progress and failure.
     */
    public int $timeout = 0;

    public int $tries = 1;

    public function __construct(
        public string $actorName,
        public int $commandId,
    ) {}

    public static function embeddingIndexBuild(int $commandId): self
    {
        return new self(PythonActorRunner::ACTOR_BUILD_EMBEDDING_INDEX, $commandId);
    }

    public static function documentPipeline(int $pipelineRunId): self
    {
        return new self(PythonActorRunner::ACTOR_HANDLE_DOCUMENT_PIPELINE, $pipelineRunId);
    }

    public static function reviewCommit(int $commandId): self
    {
        return new self(PythonActorRunner::ACTOR_COMMIT_REVIEW_SUGGESTION, $commandId);
    }

    public static function pollReconciliation(int $commandId): self
    {
        return new self(PythonActorRunner::ACTOR_POLL_RECONCILIATION, $commandId);
    }

    public static function reindex(int $commandId): self
    {
        return new self(PythonActorRunner::ACTOR_REINDEX, $commandId);
    }

    public static function reindexOcr(int $commandId): self
    {
        return new self(PythonActorRunner::ACTOR_REINDEX_OCR, $commandId);
    }

    public static function webhookDelivery(int $deliveryId): self
    {
        return new self(PythonActorRunner::ACTOR_HANDLE_PAPERLESS_WEBHOOK, $deliveryId);
    }

    public function handle(PythonActorRunner $runner): void
    {
        match ($this->actorName) {
            PythonActorRunner::ACTOR_BUILD_EMBEDDING_INDEX => $runner->runEmbeddingIndexBuild(
                Command::query()->findOrFail($this->commandId),
            ),
            PythonActorRunner::ACTOR_HANDLE_DOCUMENT_PIPELINE => $runner->runDocumentPipeline(
                PipelineRun::query()->findOrFail($this->commandId),
            ),
            PythonActorRunner::ACTOR_COMMIT_REVIEW_SUGGESTION => $runner->runReviewCommit(
                Command::query()->findOrFail($this->commandId),
            ),
            PythonActorRunner::ACTOR_POLL_RECONCILIATION => $runner->runPollReconciliation(
                Command::query()->findOrFail($this->commandId),
            ),
            PythonActorRunner::ACTOR_REINDEX => $runner->runReindex(
                Command::query()->findOrFail($this->commandId),
            ),
            PythonActorRunner::ACTOR_REINDEX_OCR => $runner->runReindexOcr(
                Command::query()->findOrFail($this->commandId),
            ),
            PythonActorRunner::ACTOR_HANDLE_PAPERLESS_WEBHOOK => $runner->runWebhookDelivery(
                WebhookDelivery::query()->findOrFail($this->commandId),
            ),
            default => throw new InvalidArgumentException("Unsupported Python actor {$this->actorName}."),
        };
    }
}
