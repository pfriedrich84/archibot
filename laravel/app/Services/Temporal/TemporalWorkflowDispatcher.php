<?php

namespace App\Services\Temporal;

use App\Models\Command;
use App\Models\PipelineRun;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class TemporalWorkflowDispatcher
{
    public const DRIVER = 'temporal';

    public const EMBEDDING_WORKFLOW = 'archibot.embedding_index';

    public const POLL_WORKFLOW = 'archibot.poll_reconciliation';

    public const DOCUMENT_WORKFLOW = 'archibot.document';

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

    public function startDocumentProcessing(PipelineRun $run): PipelineRun
    {
        return DB::transaction(function () use ($run): PipelineRun {
            $run = PipelineRun::query()->lockForUpdate()->findOrFail($run->id);
            $workflowId = "archibot/document/{$run->paperless_document_id}/{$run->pipeline_dedupe_key}";
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
                payload: ['pipeline_run_id' => $run->id],
            );

            return PipelineRun::query()->findOrFail($run->id);
        });
    }

    private function intentKey(string $identity): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "archibot:{$identity}")->toString();
    }
}
