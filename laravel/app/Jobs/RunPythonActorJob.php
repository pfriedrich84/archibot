<?php

namespace App\Jobs;

use App\Models\Command;
use App\Models\PipelineRun;
use App\Models\WebhookDelivery;
use App\Services\Actors\PythonActorRunner;
use App\Services\Pipeline\PollCandidateConsumer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RunPythonActorJob implements ShouldQueue
{
    use Queueable;

    /**
     * Actor commands can run for a long time while local embedding/LLM work is
     * active. Durable command/pipeline state records progress and failure.
     */
    public int $tries = 1;

    public int $timeout;

    public function __construct(
        public string $actorName,
        public int $commandId,
    ) {
        $this->timeout = max(1, (int) config('archibot_workers.queue_worker_timeout', 21600));
    }

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

    public static function syncEntityApproval(int $commandId): self
    {
        return new self(PythonActorRunner::ACTOR_SYNC_ENTITY_APPROVAL, $commandId);
    }

    public static function webhookDelivery(int $deliveryId): self
    {
        return new self(PythonActorRunner::ACTOR_HANDLE_PAPERLESS_WEBHOOK, $deliveryId);
    }

    public function handle(
        PythonActorRunner $runner,
        ?PollCandidateConsumer $pollCandidates = null,
    ): void {
        $pollCandidates ??= app(PollCandidateConsumer::class);

        match ($this->actorName) {
            PythonActorRunner::ACTOR_BUILD_EMBEDDING_INDEX,
            PythonActorRunner::ACTOR_COMMIT_REVIEW_SUGGESTION,
            PythonActorRunner::ACTOR_REINDEX,
            PythonActorRunner::ACTOR_REINDEX_OCR,
            PythonActorRunner::ACTOR_SYNC_ENTITY_APPROVAL => $this->runCommandIfEligible($runner),
            PythonActorRunner::ACTOR_HANDLE_DOCUMENT_PIPELINE => $this->runPipelineIfEligible($runner),
            PythonActorRunner::ACTOR_POLL_RECONCILIATION => $this->runPollReconciliation($runner, $pollCandidates),
            PythonActorRunner::ACTOR_HANDLE_PAPERLESS_WEBHOOK => $this->runWebhookIfEligible($runner),
            default => throw new InvalidArgumentException("Unsupported Python actor {$this->actorName}."),
        };
    }

    private function runPollReconciliation(PythonActorRunner $runner, PollCandidateConsumer $pollCandidates): void
    {
        $this->runCommandIfEligible($runner);
        $pollCandidates->consumeCommand($this->commandId);
    }

    private function runCommandIfEligible(PythonActorRunner $runner): void
    {
        $command = DB::transaction(function (): ?Command {
            $command = Command::query()->lockForUpdate()->findOrFail($this->commandId);
            if (! in_array($command->status, [Command::STATUS_PENDING, Command::STATUS_QUEUED], true)) {
                return null;
            }

            $command->update([
                'status' => Command::STATUS_RUNNING,
                'started_at' => $command->started_at ?? now(),
            ]);

            return $command;
        });
        if ($command === null) {
            return;
        }

        match ($this->actorName) {
            PythonActorRunner::ACTOR_BUILD_EMBEDDING_INDEX => $runner->runEmbeddingIndexBuild($command),
            PythonActorRunner::ACTOR_COMMIT_REVIEW_SUGGESTION => $runner->runReviewCommit($command),
            PythonActorRunner::ACTOR_POLL_RECONCILIATION => $runner->runPollReconciliation($command),
            PythonActorRunner::ACTOR_REINDEX => $runner->runReindex($command),
            PythonActorRunner::ACTOR_REINDEX_OCR => $runner->runReindexOcr($command),
            PythonActorRunner::ACTOR_SYNC_ENTITY_APPROVAL => $runner->runSyncEntityApproval($command),
            default => throw new InvalidArgumentException("Unsupported command actor {$this->actorName}."),
        };
    }

    private function runPipelineIfEligible(PythonActorRunner $runner): void
    {
        $run = DB::transaction(function (): ?PipelineRun {
            $run = PipelineRun::query()->lockForUpdate()->findOrFail($this->commandId);

            return in_array($run->status, [
                PipelineRun::STATUS_PENDING,
                PipelineRun::STATUS_QUEUED,
                PipelineRun::STATUS_RETRYING,
            ], true) ? $run : null;
        });
        if ($run !== null) {
            // The child acquires and owns its PostgreSQL shared lease before
            // readiness revalidation or any productive pipeline mutation.
            // Laravel must not hold a lease while waiting for the child: that
            // would deadlock exclusive actors and parent death would otherwise
            // release the wrong process's protection.
            $runner->runDocumentPipeline($run);
        }
    }

    private function runWebhookIfEligible(PythonActorRunner $runner): void
    {
        $delivery = DB::transaction(function (): ?WebhookDelivery {
            $delivery = WebhookDelivery::query()->lockForUpdate()->findOrFail($this->commandId);
            if (! in_array($delivery->status, [
                WebhookDelivery::STATUS_RECEIVED,
                WebhookDelivery::STATUS_QUEUED,
            ], true)) {
                return null;
            }

            $delivery->update(['status' => WebhookDelivery::STATUS_RUNNING]);

            return $delivery;
        });
        if ($delivery !== null) {
            $runner->runWebhookDelivery($delivery);
        }
    }
}
