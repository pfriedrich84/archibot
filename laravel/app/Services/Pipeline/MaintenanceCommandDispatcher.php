<?php

namespace App\Services\Pipeline;

use App\Data\Audit\AuditRecord;
use App\Data\Pipeline\PipelineEventRecord;
use App\Domain\Commands\CommandRecord;
use App\Jobs\RunPythonActorJob;
use App\Support\OperatorPrincipal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class MaintenanceCommandDispatcher
{
    public function __construct(
        private readonly PipelineStartGate $pipelineStartGate,
    ) {}

    public function queuePollReconciliation(Request $request, ?int $limit = null, array $metadata = []): CommandRecord
    {
        return Cache::lock('archibot:poll-command-dispatch', 120)->block(
            5,
            fn (): CommandRecord => $this->queuePollReconciliationUnlocked($request, $limit, $metadata),
        );
    }

    private function queuePollReconciliationUnlocked(Request $request, ?int $limit, array $metadata): CommandRecord
    {
        $limit = $this->normalizedLimit($limit);
        $payload = array_filter([
            'limit' => $limit,
            ...$metadata,
        ], fn ($value): bool => $value !== null);

        $command = $this->createCommand(
            $request,
            CommandRecord::TYPE_POLL_RECONCILIATION,
            $payload,
        );

        $this->recordEvent($request, $command, 'job_control.poll_reconciliation_requested', 'info', 'Polling reconciliation requested by admin.', [
            'action' => CommandRecord::TYPE_POLL_RECONCILIATION,
            'limit' => $limit,
            ...$metadata,
        ]);
        $this->audit($request, 'maintenance.poll_reconciliation_requested', $command, [
            'limit' => $limit,
            ...$metadata,
        ]);

        $this->enqueueCommand($command, RunPythonActorJob::pollReconciliation($command->id));
        $this->recordEvent($request, $command, 'job_control.poll_reconciliation_actor_queued', 'info', 'Polling reconciliation queued through Laravel actor transport.', [
            'action' => CommandRecord::TYPE_POLL_RECONCILIATION,
            'actor_name' => 'reconcile_inbox_documents',
            'limit' => $limit,
            ...$metadata,
        ]);

        return $command;
    }

    public function queueScheduledPollReconciliation(): ?CommandRecord
    {
        $lock = Cache::lock('archibot:poll-command-dispatch', 120);
        if (! $lock->get()) {
            return null;
        }

        try {
            return $this->queueScheduledPollReconciliationUnlocked();
        } finally {
            $lock->release();
        }
    }

    private function queueScheduledPollReconciliationUnlocked(): ?CommandRecord
    {
        $period = max(0, (int) config('archibot.poll_interval_seconds', 600));
        if ($period === 0) {
            return null;
        }

        $activeExists = CommandRecord::query()
            ->where('type', CommandRecord::TYPE_POLL_RECONCILIATION)
            ->whereIn('status', CommandRecord::activeStatuses())
            ->exists();
        if ($activeExists) {
            return null;
        }

        $recentScheduledExists = CommandRecord::query()
            ->where('type', CommandRecord::TYPE_POLL_RECONCILIATION)
            ->whereIn('status', [
                CommandRecord::STATUS_SUCCEEDED,
                CommandRecord::STATUS_FAILED,
                CommandRecord::STATUS_FAILED_PERMANENT,
            ])
            ->where('payload->source', 'scheduler')
            ->whereNotNull('finished_at')
            ->where('finished_at', '>', now()->subSeconds($period))
            ->exists();
        if ($recentScheduledExists) {
            return null;
        }

        $command = CommandRecord::query()->create([
            'type' => CommandRecord::TYPE_POLL_RECONCILIATION,
            'queue' => $this->queueNameFor(CommandRecord::TYPE_POLL_RECONCILIATION),
            'priority' => $this->priorityFor(CommandRecord::TYPE_POLL_RECONCILIATION),
            'status' => CommandRecord::STATUS_PENDING,
            'payload' => [
                'source' => 'scheduler',
                'cadence' => $period,
            ],
            'created_by_user_id' => null,
        ]);

        $this->recordSystemEvent(
            $command,
            'scheduler.poll_reconciliation_requested',
            'info',
            'Automatic polling reconciliation requested by the Laravel scheduler.',
            ['cadence' => $period],
        );

        try {
            $this->enqueueCommand($command, RunPythonActorJob::pollReconciliation($command->id));
        } catch (Throwable $exception) {
            $command->forceFill([
                'status' => CommandRecord::STATUS_PENDING,
                'error' => 'queue_dispatch_failed:'.$exception::class,
            ])->save();
            $this->recordSystemEvent(
                $command,
                'scheduler.poll_reconciliation_enqueue_failed',
                'warning',
                'Laravel scheduler could not enqueue polling reconciliation; durable recovery will retry.',
                ['error_type' => $exception::class],
            );

            throw $exception;
        }

        $this->recordSystemEvent(
            $command,
            'scheduler.poll_reconciliation_actor_queued',
            'info',
            'Automatic polling reconciliation queued through Laravel actor transport.',
            [
                'actor_name' => 'reconcile_inbox_documents',
                'cadence' => $period,
            ],
        );

        return $command;
    }

    public function queueReindex(Request $request, ?int $limit = null, array $metadata = []): Command
    {
        $limit = $this->normalizedLimit($limit);
        $embeddingState = $this->pipelineStartGate->markStale('Reindex requested by admin.');

        $payload = array_filter([
            'limit' => $limit,
            ...$metadata,
        ], fn ($value): bool => $value !== null);
        $command = $this->createCommand($request, CommandRecord::TYPE_REINDEX, $payload);

        $this->recordEvent($request, $command, 'job_control.reindex_requested', 'warning', 'Reindex requested by admin; embedding gate marked stale.', [
            'action' => CommandRecord::TYPE_REINDEX,
            'embedding_index_state_id' => $embeddingState->id,
            'limit' => $limit,
            ...$metadata,
        ]);
        $this->audit($request, 'maintenance.reindex_requested', $command, [
            'embedding_index_state_id' => $embeddingState->id,
            'limit' => $limit,
            ...$metadata,
        ]);

        $this->enqueueCommand($command, RunPythonActorJob::reindex($command->id));
        $this->recordEvent($request, $command, 'job_control.reindex_actor_queued', 'info', 'Reindex queued through Laravel actor transport.', [
            'action' => CommandRecord::TYPE_REINDEX,
            'actor_name' => 'reindex',
            'embedding_index_state_id' => $embeddingState->id,
            'limit' => $limit,
            ...$metadata,
        ]);

        return $command;
    }

    public function queueOcrReindex(Request $request, ?int $limit = null, bool $force = false, array $metadata = []): Command
    {
        $limit = $this->normalizedLimit($limit);
        $payload = array_filter([
            'limit' => $limit,
            'force' => $force,
            ...$metadata,
        ], fn ($value): bool => $value !== null);
        $command = $this->createCommand($request, CommandRecord::TYPE_REINDEX_OCR, $payload);

        $this->recordEvent($request, $command, 'job_control.ocr_reindex_requested', 'info', 'OCR reindex requested by admin.', [
            'action' => CommandRecord::TYPE_REINDEX_OCR,
            'limit' => $limit,
            'force' => $force,
            ...$metadata,
        ]);
        $this->audit($request, 'maintenance.ocr_reindex_requested', $command, [
            'limit' => $limit,
            'force' => $force,
            ...$metadata,
        ]);

        $this->enqueueCommand($command, RunPythonActorJob::reindexOcr($command->id));
        $this->recordEvent($request, $command, 'job_control.ocr_reindex_actor_queued', 'info', 'OCR reindex queued through Laravel actor transport.', [
            'action' => CommandRecord::TYPE_REINDEX_OCR,
            'actor_name' => 'reindex_ocr',
            'limit' => $limit,
            'force' => $force,
            ...$metadata,
        ]);

        return $command;
    }

    public function queueEmbeddingIndexBuild(Request $request, ?int $limit = null, array $metadata = []): Command
    {
        $limit = $this->normalizedLimit($limit);
        $payload = array_filter([
            'limit' => $limit,
            ...$metadata,
        ], fn ($value): bool => $value !== null);
        $command = $this->createCommand($request, CommandRecord::TYPE_EMBEDDING_INDEX_BUILD, $payload);

        $this->recordEvent($request, $command, 'job_control.embedding_build_requested', 'info', 'Embedding index build requested by admin.', [
            'action' => CommandRecord::TYPE_EMBEDDING_INDEX_BUILD,
            'limit' => $limit,
            ...$metadata,
        ]);
        $this->audit($request, 'embedding_index.build_requested', $command, [
            'limit' => $limit,
            ...$metadata,
        ], 'embedding_index');

        $this->enqueueCommand($command, RunPythonActorJob::embeddingIndexBuild($command->id));

        $this->recordEvent(
            $request,
            $command,
            'job_control.embedding_build_actor_queued',
            'info',
            'Embedding build queued through Laravel actor transport.',
            [
                'action' => CommandRecord::TYPE_EMBEDDING_INDEX_BUILD,
                'actor_name' => 'build_embedding_index',
                'limit' => $limit,
                ...$metadata,
            ],
        );

        return $command;
    }

    /** @param array<string, mixed> $payload */
    private function createCommand(Request $request, string $type, array $payload): Command
    {
        return CommandRecord::query()->create([
            'type' => $type,
            'queue' => $this->queueNameFor($type),
            'priority' => $this->priorityFor($type),
            'status' => CommandRecord::STATUS_PENDING,
            'payload' => $payload,
            'created_by_user_id' => OperatorPrincipal::userId($request),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function recordEvent(Request $request, Command $command, string $eventType, string $level, string $message, array $payload): void
    {
        PipelineEventRecord::query()->create([
            'command_id' => $command->id,
            'event_type' => $eventType,
            'level' => $level,
            'message' => $message,
            'payload' => [
                ...OperatorPrincipal::metadata($request),
                'actor_is_admin' => (bool) OperatorPrincipal::user($request)?->is_admin,
                'command_id' => $command->id,
                ...$payload,
            ],
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function recordSystemEvent(Command $command, string $eventType, string $level, string $message, array $payload): void
    {
        PipelineEventRecord::query()->create([
            'command_id' => $command->id,
            'event_type' => $eventType,
            'level' => $level,
            'message' => $message,
            'payload' => [
                'actor_principal' => OperatorPrincipal::SYSTEM_SCHEDULER,
                'actor_user_id' => null,
                'command_id' => $command->id,
                ...$payload,
            ],
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function audit(Request $request, string $event, Command $command, array $metadata, string $targetType = 'command'): void
    {
        AuditRecord::query()->create([
            'actor_user_id' => OperatorPrincipal::userId($request),
            'event' => $event,
            'target_type' => $targetType,
            'target_id' => (string) $command->id,
            'metadata' => [
                'command_id' => $command->id,
                ...OperatorPrincipal::metadata($request),
                ...$metadata,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    private function enqueueCommand(Command $command, RunPythonActorJob $job): void
    {
        DB::transaction(function () use ($command, $job): void {
            $command = CommandRecord::query()->lockForUpdate()->findOrFail($command->id);
            if ($command->status !== CommandRecord::STATUS_PENDING) {
                return;
            }

            $command->forceFill([
                'status' => CommandRecord::STATUS_QUEUED,
                'error' => null,
            ])->save();
            $job->onQueue($command->queue ?: $this->queueNameFor($command->type));
            dispatch($job);
        });
        $command->refresh();
    }

    private function normalizedLimit(?int $limit): ?int
    {
        return $limit !== null && $limit > 0 ? $limit : null;
    }

    private function queueNameFor(string $type): string
    {
        return match ($type) {
            CommandRecord::TYPE_EMBEDDING_INDEX_BUILD,
            CommandRecord::TYPE_PAPERLESS_SIMILARITY_INDEX => (string) config('archibot_workers.queues.embeddings', 'embeddings'),
            CommandRecord::TYPE_CLASSIFY_WITH_ARCHIBOT,
            CommandRecord::TYPE_REVIEW_COMMIT => (string) config('archibot_workers.queues.interactive', 'interactive'),
            default => (string) config('archibot_workers.queues.maintenance', 'maintenance'),
        };
    }

    private function priorityFor(string $type): int
    {
        return match ($type) {
            CommandRecord::TYPE_EMBEDDING_INDEX_BUILD,
            CommandRecord::TYPE_PAPERLESS_SIMILARITY_INDEX => (int) config('archibot_workers.priorities.embeddings', 30),
            CommandRecord::TYPE_CLASSIFY_WITH_ARCHIBOT,
            CommandRecord::TYPE_REVIEW_COMMIT => (int) config('archibot_workers.priorities.interactive', 80),
            default => (int) config('archibot_workers.priorities.maintenance', 40),
        };
    }
}
