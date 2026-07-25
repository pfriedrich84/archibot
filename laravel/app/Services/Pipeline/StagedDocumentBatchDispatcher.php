<?php

namespace App\Services\Pipeline;

use App\Jobs\RunPythonActorJob;
use App\Models\Command;
use App\Models\PipelineEvent;
use App\Models\PipelineRun;
use App\Models\PollCandidate;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StagedDocumentBatchDispatcher
{
    public function __construct(
        private readonly DocumentPipelineStarter $pipelineStarter,
    ) {}

    public function dispatchReadyPollBatches(int $limit = 100): int
    {
        $dispatched = 0;
        PipelineRun::query()
            ->where('type', 'document')
            ->where(function ($query): void {
                $query->where('progress_current_phase', 'staged_batch_wait')
                    ->orWhere(function ($query): void {
                        $query->where('error_type', DocumentPipelineStarter::BLOCKED_REASON_EMBEDDING_INDEX_NOT_READY)
                            ->where('progress_current_phase', 'staged_batch_wait');
                    });
            })
            ->whereNull('batch_command_id')
            ->whereNotNull('command_id')
            ->distinct()
            ->limit($limit)
            ->pluck('command_id')
            ->each(function (int $commandId) use (&$dispatched): void {
                if ($this->dispatchForPollCommand($commandId) !== null) {
                    $dispatched++;
                }
            });

        return $dispatched;
    }

    public function dispatchForPollCommand(int $sourceCommandId): ?Command
    {
        return DB::transaction(function () use ($sourceCommandId): ?Command {
            $source = Command::query()->lockForUpdate()->find($sourceCommandId);
            if ($source === null
                || $source->type !== Command::TYPE_POLL_RECONCILIATION
                || $source->status !== Command::STATUS_SUCCEEDED) {
                return null;
            }

            $targetSetUnsealed = PollCandidate::query()
                ->where('command_id', $sourceCommandId)
                ->whereIn('status', [PollCandidate::STATUS_READY, PollCandidate::STATUS_CLAIMED])
                ->exists();
            if ($targetSetUnsealed) {
                return null;
            }

            $idempotencyKey = "staged_document_batch:poll:{$sourceCommandId}";
            $existing = Command::query()->where('idempotency_key', $idempotencyKey)->first();
            $targetRuns = PipelineRun::query()
                ->where('command_id', $sourceCommandId)
                ->where('type', 'document')
                ->whereNull('batch_command_id')
                ->where(function ($query): void {
                    $query->where(function ($query): void {
                        $query->where('status', PipelineRun::STATUS_PENDING)
                            ->where('progress_current_phase', 'staged_batch_wait');
                    })->orWhere(function ($query): void {
                        $query->where('status', PipelineRun::STATUS_BLOCKED)
                            ->where('error_type', DocumentPipelineStarter::BLOCKED_REASON_EMBEDDING_INDEX_NOT_READY)
                            ->where('progress_current_phase', 'staged_batch_wait');
                    });
                })
                ->get(['id', 'status']);
            $documentCount = $targetRuns->count();
            if ($documentCount === 0) {
                return $existing;
            }
            $hasBlockedTargets = $targetRuns->contains(
                fn (PipelineRun $run): bool => $run->status === PipelineRun::STATUS_BLOCKED,
            );

            $command = $existing ?? Command::query()->create([
                'idempotency_key' => $idempotencyKey,
                'type' => Command::TYPE_STAGED_DOCUMENT_BATCH,
                'queue' => (string) config('archibot_workers.queues.default', 'default'),
                'priority' => (int) config('archibot_workers.priorities.default', 50),
                'status' => Command::STATUS_PENDING,
                'payload' => [
                    'source_command_id' => $sourceCommandId,
                    'document_count' => $documentCount,
                    'phase_order' => ['embedding', 'ocr', 'classification', 'judge'],
                ],
            ]);
            if ($command->status !== Command::STATUS_PENDING) {
                return $command;
            }

            $attached = $this->pipelineStarter->attachStagedBatch($sourceCommandId, $command->id);
            if ($attached !== $documentCount) {
                throw new RuntimeException('Staged batch target attachment did not match the sealed target count.');
            }

            $status = $hasBlockedTargets ? Command::STATUS_BLOCKED : Command::STATUS_QUEUED;
            $command->forceFill([
                'status' => $status,
                'error' => $hasBlockedTargets ? DocumentPipelineStarter::BLOCKED_REASON_EMBEDDING_INDEX_NOT_READY : null,
            ])->save();
            if (! $hasBlockedTargets) {
                $job = RunPythonActorJob::stagedDocumentBatch($command->id)
                    ->onQueue($command->queue ?: (string) config('archibot_workers.queues.default', 'default'));
                dispatch($job);
            }

            PipelineEvent::query()->create([
                'command_id' => $command->id,
                'event_type' => $hasBlockedTargets ? 'pipeline.batch.blocked.embedding_index_not_ready' : 'pipeline.batch.queued',
                'level' => $hasBlockedTargets ? 'warning' : 'info',
                'message' => $hasBlockedTargets
                    ? 'Globally staged document batch is waiting for embedding readiness.'
                    : 'Globally staged document batch queued through Laravel actor transport.',
                'payload' => [
                    'source_command_id' => $sourceCommandId,
                    'document_count' => $documentCount,
                    'phase_order' => ['embedding', 'ocr', 'classification', 'judge'],
                ],
            ]);

            return $command->fresh();
        });
    }
}
