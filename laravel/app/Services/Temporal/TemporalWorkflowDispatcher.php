<?php

namespace App\Services\Temporal;

use App\Models\Command;
use App\Models\PipelineRun;
use App\Models\ReviewSuggestion;
use Illuminate\Support\Facades\DB;
use LogicException;
use Ramsey\Uuid\Uuid;

class TemporalWorkflowDispatcher
{
    public const DRIVER = 'temporal';

    public const EMBEDDING_WORKFLOW = 'archibot.embedding_index';

    public const POLL_WORKFLOW = 'archibot.poll_reconciliation';

    public const DOCUMENT_WORKFLOW = 'archibot.document';

    public const REVIEW_COMMIT_WORKFLOW = 'archibot.review_commit';

    public function __construct(private readonly TemporalOutbox $outbox) {}

    public function startEmbeddingGeneration(Command $command): Command
    {
        return DB::transaction(function () use ($command): Command {
            $command = Command::query()->lockForUpdate()->findOrFail($command->id);
            $workflowId = "archibot/embedding-index/{$command->id}";
            $payload = [
                ...($command->payload ?? []),
                'orchestration_driver' => self::DRIVER,
                'temporal_workflow_id' => $workflowId,
            ];
            Command::query()->whereKey($command->id)->update([
                'status' => Command::STATUS_QUEUED,
                'payload' => $payload,
                'error' => null,
            ]);

            $intentKey = Uuid::uuid5(
                Uuid::NAMESPACE_URL,
                "archibot:embedding-index:{$command->id}",
            )->toString();
            $this->outbox->startWorkflow(
                intentKey: $intentKey,
                workflowId: $workflowId,
                workflowType: self::EMBEDDING_WORKFLOW,
                payload: ['command_id' => $command->id],
            );

            return Command::query()->findOrFail($command->id);
        });
    }

    public function startPollReconciliation(Command $command): Command
    {
        return DB::transaction(function () use ($command): Command {
            $command = Command::query()->lockForUpdate()->findOrFail($command->id);
            $workflowId = "archibot/poll-reconciliation/{$command->id}";
            Command::query()->whereKey($command->id)->update([
                'status' => Command::STATUS_QUEUED,
                'payload' => [
                    ...($command->payload ?? []),
                    'orchestration_driver' => self::DRIVER,
                    'temporal_workflow_id' => $workflowId,
                ],
                'error' => null,
            ]);
            $this->outbox->startWorkflow(
                intentKey: $this->intentKey("poll-reconciliation:{$command->id}"),
                workflowId: $workflowId,
                workflowType: self::POLL_WORKFLOW,
                payload: ['command_id' => $command->id],
            );

            return Command::query()->findOrFail($command->id);
        });
    }

    public function startDocumentProcessing(PipelineRun $run, bool $forceNewRun = false): PipelineRun
    {
        return DB::transaction(function () use ($run, $forceNewRun): PipelineRun {
            $run = PipelineRun::query()->lockForUpdate()->findOrFail($run->id);
            $workflowId = filled($run->temporal_workflow_id)
                ? (string) $run->temporal_workflow_id
                : $this->documentWorkflowId($run, $forceNewRun);
            $attributes = [
                'orchestration_driver' => self::DRIVER,
                'temporal_workflow_id' => $workflowId,
            ];
            if ($run->status === PipelineRun::STATUS_PENDING) {
                $attributes = [
                    ...$attributes,
                    'status' => PipelineRun::STATUS_QUEUED,
                    'progress_current_phase' => 'document_workflow',
                    'progress_message' => 'Document workflow queued through Temporal outbox.',
                    'progress_updated_at' => now(),
                ];
            }
            PipelineRun::query()->whereKey($run->id)->update($attributes);
            $this->outbox->startWorkflow(
                intentKey: $this->intentKey("document:{$run->id}"),
                workflowId: $workflowId,
                workflowType: self::DOCUMENT_WORKFLOW,
                payload: [
                    'pipeline_run_id' => $run->id,
                    'workflow_id' => $workflowId,
                    'paperless_document_id' => $run->paperless_document_id,
                ],
            );

            return PipelineRun::query()->findOrFail($run->id);
        });
    }

    public function reserveDocumentProcessing(PipelineRun $run, bool $forceNewRun = false): PipelineRun
    {
        $workflowId = $this->documentWorkflowId($run, $forceNewRun);
        PipelineRun::query()->whereKey($run->id)->update([
            'orchestration_driver' => self::DRIVER,
            'temporal_workflow_id' => $workflowId,
        ]);

        return PipelineRun::query()->findOrFail($run->id);
    }

    /**
     * Persist an authorized review decision as a Temporal signal or legacy
     * review-commit workflow start in the caller's database transaction.
     *
     * @param  array{actor_principal: string, actor_user_id: int|null, actor_is_admin: bool}  $actor
     */
    /** @return array{operation: string, temporal_workflow_id: string}|null */
    public function dispatchReviewDecision(
        ReviewSuggestion $suggestion,
        ?Command $command,
        string $decision,
        array $actor,
    ): ?array {
        if (! in_array($decision, [ReviewSuggestion::STATUS_ACCEPTED, ReviewSuggestion::STATUS_REJECTED], true)) {
            throw new LogicException('Temporal review decision is invalid.');
        }
        if (($decision === ReviewSuggestion::STATUS_ACCEPTED) !== ($command instanceof Command)) {
            throw new LogicException('Accepted Temporal review decisions require a commit command.');
        }

        $run = $suggestion->pipeline_run_id === null
            ? null
            : PipelineRun::query()->find($suggestion->pipeline_run_id);
        $documentWorkflowId = $run?->orchestration_driver === self::DRIVER
            && filled($run->temporal_workflow_id)
                ? (string) $run->temporal_workflow_id
                : null;

        if ($decision === ReviewSuggestion::STATUS_REJECTED && $documentWorkflowId === null) {
            return null;
        }

        $workflowId = $documentWorkflowId
            ?? "archibot/review-commit/{$suggestion->id}";
        $payload = [
            'review_suggestion_id' => $suggestion->id,
            'command_id' => $command?->id,
            'decision' => $decision,
            'temporal_workflow_id' => $workflowId,
            ...$actor,
        ];

        if ($command instanceof Command) {
            Command::query()->whereKey($command->id)->update([
                'status' => Command::STATUS_QUEUED,
                'payload' => [
                    ...($command->payload ?? []),
                    'orchestration_driver' => self::DRIVER,
                    'temporal_workflow_id' => $workflowId,
                ],
                'error' => null,
                'finished_at' => null,
            ]);
        }

        if ($documentWorkflowId !== null) {
            $this->outbox->signalWorkflow(
                intentKey: $this->intentKey("review-decision:{$suggestion->id}:{$decision}"),
                workflowId: $workflowId,
                signalName: 'review_decision_v2',
                payload: $payload,
            );

            return [
                'operation' => 'signal_workflow',
                'temporal_workflow_id' => $workflowId,
            ];
        }

        $this->outbox->startWorkflow(
            intentKey: $this->intentKey("review-commit:{$suggestion->id}"),
            workflowId: $workflowId,
            workflowType: self::REVIEW_COMMIT_WORKFLOW,
            payload: [
                'review_suggestion_id' => $suggestion->id,
                'command_id' => $command?->id,
                'workflow_id' => $workflowId,
            ],
        );

        return [
            'operation' => 'start_workflow',
            'temporal_workflow_id' => $workflowId,
        ];
    }

    /**
     * End the document workflow waiting on this review when an administrator
     * explicitly starts a replacement generation.
     *
     * @param  array{actor_principal: string, actor_user_id: int|null, actor_is_admin: bool}  $actor
     * @return array{operation: string, temporal_workflow_id: string}|null
     */
    public function dispatchForceReprocess(
        ReviewSuggestion $suggestion,
        PipelineRun $replacement,
        array $actor,
    ): ?array {
        $source = $suggestion->pipeline_run_id === null
            ? null
            : PipelineRun::query()->find($suggestion->pipeline_run_id);
        if (
            $suggestion->status !== ReviewSuggestion::STATUS_PENDING
            || $source?->orchestration_driver !== self::DRIVER
            || blank($source->temporal_workflow_id)
            || ! in_array($source->progress_current_phase, ['awaiting_review', 'review_suggestion'], true)
        ) {
            return null;
        }

        $workflowId = (string) $source->temporal_workflow_id;
        $this->outbox->signalWorkflow(
            intentKey: $this->intentKey("force-reprocess:{$suggestion->id}:{$replacement->id}"),
            workflowId: $workflowId,
            signalName: 'force_reprocess_v2',
            payload: [
                'review_suggestion_id' => $suggestion->id,
                'replacement_pipeline_run_id' => $replacement->id,
                'replacement_temporal_workflow_id' => $replacement->temporal_workflow_id,
                ...$actor,
            ],
        );

        return [
            'operation' => 'signal_workflow',
            'temporal_workflow_id' => $workflowId,
        ];
    }

    private function intentKey(string $identity): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "archibot:{$identity}")->toString();
    }

    private function documentWorkflowId(PipelineRun $run, bool $forceNewRun): string
    {
        return $forceNewRun
            ? "archibot/document/{$run->paperless_document_id}/reprocess/{$run->id}"
            : "archibot/document/{$run->paperless_document_id}";
    }
}
