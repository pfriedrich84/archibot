<?php

namespace App\Jobs;

use App\Models\Command;
use App\Models\PipelineRun;
use App\Models\WebhookDelivery;
use App\Services\Actors\ActorInvocationClaimer;
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
        ?ActorInvocationClaimer $claimer = null,
    ): void {
        $pollCandidates ??= app(PollCandidateConsumer::class);
        $claimer ??= app(ActorInvocationClaimer::class);

        match ($this->actorName) {
            PythonActorRunner::ACTOR_BUILD_EMBEDDING_INDEX,
            PythonActorRunner::ACTOR_COMMIT_REVIEW_SUGGESTION,
            PythonActorRunner::ACTOR_REINDEX,
            PythonActorRunner::ACTOR_REINDEX_OCR,
            PythonActorRunner::ACTOR_SYNC_ENTITY_APPROVAL => $this->runCommandIfEligible($runner, $claimer),
            PythonActorRunner::ACTOR_HANDLE_DOCUMENT_PIPELINE => $this->runPipelineIfEligible($runner, $claimer),
            PythonActorRunner::ACTOR_POLL_RECONCILIATION => $this->runPollReconciliation($runner, $pollCandidates, $claimer),
            PythonActorRunner::ACTOR_HANDLE_PAPERLESS_WEBHOOK => $this->runWebhookIfEligible($runner, $claimer),
            default => throw new InvalidArgumentException("Unsupported Python actor {$this->actorName}."),
        };
    }

    private function runPollReconciliation(PythonActorRunner $runner, PollCandidateConsumer $pollCandidates, ActorInvocationClaimer $claimer): void
    {
        if ($this->runCommandIfEligible($runner, $claimer)) {
            $pollCandidates->consumeCommand($this->commandId);
        }
    }

    private function runCommandIfEligible(PythonActorRunner $runner, ActorInvocationClaimer $claimer): bool
    {
        $claimed = DB::transaction(function () use ($claimer): ?array {
            $command = Command::query()->lockForUpdate()->findOrFail($this->commandId);
            if (! in_array($command->status, [Command::STATUS_PENDING, Command::STATUS_QUEUED], true)
                || $command->next_retry_at?->isFuture()
                || $claimer->suppresses($this->actorName, 'command_id', $command->id)) {
                return null;
            }
            $claim = $claimer->issue($this->actorName, (int) $command->lifecycle_version, 'command_id', $command->id, $command->payload['paperless_document_id'] ?? null);
            $command->update([
                'status' => Command::STATUS_RUNNING,
                'started_at' => $command->started_at ?? now(),
                'finished_at' => null,
                'active_actor_token' => $claim->token,
                'lifecycle_version' => $claim->sourceVersion,
            ]);

            return [$command->fresh(), $claim];
        });
        if ($claimed === null) {
            return false;
        }
        [$command, $claim] = $claimed;

        match ($this->actorName) {
            PythonActorRunner::ACTOR_BUILD_EMBEDDING_INDEX => $runner->runEmbeddingIndexBuild($command, $claim),
            PythonActorRunner::ACTOR_COMMIT_REVIEW_SUGGESTION => $runner->runReviewCommit($command, $claim),
            PythonActorRunner::ACTOR_POLL_RECONCILIATION => $runner->runPollReconciliation($command, $claim),
            PythonActorRunner::ACTOR_REINDEX => $runner->runReindex($command, $claim),
            PythonActorRunner::ACTOR_REINDEX_OCR => $runner->runReindexOcr($command, $claim),
            PythonActorRunner::ACTOR_SYNC_ENTITY_APPROVAL => $runner->runSyncEntityApproval($command, $claim),
            default => throw new InvalidArgumentException("Unsupported command actor {$this->actorName}."),
        };

        return true;
    }

    private function runPipelineIfEligible(PythonActorRunner $runner, ActorInvocationClaimer $claimer): void
    {
        $claimed = DB::transaction(function () use ($claimer): ?array {
            $run = PipelineRun::query()->lockForUpdate()->findOrFail($this->commandId);
            if (! in_array($run->status, [PipelineRun::STATUS_PENDING, PipelineRun::STATUS_QUEUED, PipelineRun::STATUS_RETRYING], true)
                || $run->next_retry_at?->isFuture()
                || $claimer->suppresses($this->actorName, 'pipeline_run_id', $run->id)) {
                return null;
            }
            $claim = $claimer->issue($this->actorName, (int) $run->lifecycle_version, 'pipeline_run_id', $run->id, $run->paperless_document_id);
            $run->update([
                'status' => PipelineRun::STATUS_RUNNING,
                'started_at' => $run->started_at ?? now(),
                'finished_at' => null,
                'active_actor_token' => $claim->token,
                'lifecycle_version' => $claim->sourceVersion,
            ]);

            return [$run->fresh(), $claim];
        });
        if ($claimed !== null) {
            [$run, $claim] = $claimed;
            // The child acquires and owns its PostgreSQL shared lease before
            // readiness revalidation or any productive pipeline mutation.
            // Laravel must not hold a lease while waiting for the child: that
            // would deadlock exclusive actors and parent death would otherwise
            // release the wrong process's protection.
            $runner->runDocumentPipeline($run, $claim);
        }
    }

    private function runWebhookIfEligible(PythonActorRunner $runner, ActorInvocationClaimer $claimer): void
    {
        $claimed = DB::transaction(function () use ($claimer): ?array {
            $delivery = WebhookDelivery::query()->lockForUpdate()->findOrFail($this->commandId);
            if (! in_array($delivery->status, [WebhookDelivery::STATUS_RECEIVED, WebhookDelivery::STATUS_QUEUED, WebhookDelivery::STATUS_FAILED], true)
                || $delivery->next_retry_at?->isFuture()
                || $claimer->suppresses($this->actorName, 'webhook_delivery_id', $delivery->id)) {
                return null;
            }
            $claim = $claimer->issue($this->actorName, (int) $delivery->lifecycle_version, 'webhook_delivery_id', $delivery->id, $delivery->paperless_document_id);
            $delivery->update([
                'status' => WebhookDelivery::STATUS_RUNNING,
                'active_actor_token' => $claim->token,
                'lifecycle_version' => $claim->sourceVersion,
            ]);

            return [$delivery->fresh(), $claim];
        });
        if ($claimed !== null) {
            [$delivery, $claim] = $claimed;
            $runner->runWebhookDelivery($delivery, $claim);
        }
    }

}
