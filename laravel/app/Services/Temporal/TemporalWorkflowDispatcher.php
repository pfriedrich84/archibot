<?php

namespace App\Services\Temporal;

use App\Models\Command;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class TemporalWorkflowDispatcher
{
    public const DRIVER = 'temporal';

    public const EMBEDDING_WORKFLOW = 'archibot.embedding_index';

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
            $command->forceFill([
                'status' => Command::STATUS_QUEUED,
                'payload' => $payload,
                'error' => null,
            ])->save();

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

            return $command->fresh();
        });
    }
}
