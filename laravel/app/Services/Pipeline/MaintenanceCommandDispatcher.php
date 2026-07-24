<?php

namespace App\Services\Pipeline;

use App\Data\Audit\AuditLogRecord;
use App\Data\Pipeline\EventRecord;
use App\Domain\Commands\CommandEntry;
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

    public function queuePollReconciliation(Request $request, ?int $limit = null, array $metadata = []): CommandEntry
    {
        return Cache::lock('archibot:poll-command-dispatch', 120)->block(
            5,
            fn (): CommandEntry => $this->queuePollReconciliationUnlocked($request, $limit, $metadata),
        );
    }

    private function queuePollReconciliationUnlocked(Request $request, ?int $limit, array $metadata): CommandEntry
    {
        $limit = $this->normalizedLimit($limit);
        $payload = array_filter([
            'limit' => $limit,
            ...$metadata,
        ], fn ($value): bool => $value !== null);

        $command = $this->createCommand(
            $request,
            CommandEntry::TYPE_POLL_RECONCILIATION,
            $payload,
        );

        $this->recordEvent($request, $command, 'job_control.poll_reconciliation_requested', 'info', 'Polling reconciliation requested by admin.', [
            'action' => CommandEntry::TYPE_POLL_RECONCILIATION,
            'limit' => $limit,
            ...$metadata,
        ]);
        $this->audit($request, 'maintenance.poll_reconciliation_requested', $command, [
            'limit' => $limit,
            ...$metadata,
        ]);

        $this->enqueueCommand($command, RunPythonActorJob::pollReconciliation($command->id));
        $this->recordEvent($request, $command, 'job_control.poll_reconciliation_actor_queued', 'info', 'Polling reconciliation queued through Laravel actor transport.', [
            'action' => CommandEntry::TYPE_POLL_RECONCILIATION,
            'actor_name' => 'reconcile_inbox_documents',
            'limit' => $limit,
            ...$metadata,
        ]);

        return $command;
    }

    public function queueScheduledPollReconciliation(): ?CommandEntry
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

    private function queueScheduledPollReconciliationUnlocked(): ?CommandEntry
    {
        $pollEvery = max(0, (int) config('archibot.poll_interval_seconds', 600));
        if ($pollEvery === 0) {
            return null;
        }

        $hasActiveCommand = CommandEntry::query()
            ->where('type', CommandEntry::TYPE_POLL_RECONCILIATION)
            ->whereIn('status', CommandEntry::activeStatuses())
            ->exists();
        if ($hasActiveCommand) {
            return null;
        }

        $hasRecentScheduledCommand = CommandEntry::query()
            ->where('type', CommandEntry::TYPE_POLL_RECONCILIATION)
            ->whereIn('status', [
                CommandEntry::STATUS_SUCCEEDED,
                CommandEntry::STATUS_FAILED,
                CommandEntry::STATUS_FAILED_PERMANENT,
            ])
            ->where('payload->source', 'scheduler')
            ->whereNotNull('finished_at')
            ->where('finished_at', '>', now()->subSeconds($pollEvery))
            ->exists();
        if ($hasRecentScheduledCommand) {
            return null;
        }

        $command = CommandEntry::query()->create([
            'type' => CommandEntry::TYPE_POLL_RECONCILIATION,
            'queue' => $this->queueNameFor(CommandEntry::TYPE_POLL_RECONCILIATION),
            'priority' => $this->priorityFor(CommandEntry::TYPE_POLL_RECONCILIATION),
            'status' => CommandEntry::STATUS_PENDING,
            'payload' => [
                'source' => 'scheduler',
                'poll_every_seconds' => $pollEvery,
            ],
            'created_by_user_id' => null,
        ]);

        $this->recordSystemEvent(
            $command,
            'scheduler.poll_reconciliation_requested',
            'info',
            'Automatic polling reconciliation requested by the Laravel scheduler.',
            ['poll_every_seconds' => $pollEvery],
        );

        try {
            $this->enqueueCommand($command, RunPythonActorJob::pollReconciliation($command->id));
        } catch (Throwable $exception) {
            $command->forceFill([
                'status' => CommandEntry::STATUS_PENDING,
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
                'poll_every_seconds' => $pollEvery,
            ],
        );

        return $command;
    }

    public function queueReindex(Request $request, ?int $limit = null, array $metadata = []): CommandEntry
    {
        $limit = $this->normalizedLimit($limit);
        $embeddingState = $this->pipelineStartGate->markStale('Reindex requested by admin.');

        $payload = array_filter([
            'limit' => $limit,
            ...$metadata,
        ], fn ($value): bool => $value !== null);
        $command = $this->createCommand($request, CommandEntry::TYPE_REINDEX, $payload);

        $this->recordEvent($request, $command, 'job_control.reindex_requested', 'warning', 'Reindex requested by admin; embedding gate marked stale.', [
            'action' => CommandEntry::TYPE_REINDEX,
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
            'action' => CommandEntry::TYPE_REINDEX,
            'actor_name' => 'reindex',
            'embedding_index_state_id' => $embeddingState->id,
            'limit' => $limit,
            ...$metadata,
        ]);

        return $command;
    }

    public function queueOcrReindex(Request $request, ?int $limit = null, bool $force = false, array $metadata = []): CommandEntry
    {
        $limit = $this->normalizedLimit($limit);
        $payload = array_filter([
            'limit' => $limit,
            'force' => $force,
            ...$metadata,
        ], fn ($value): bool => $value !== null);
        $command = $this->createCommand($request, CommandEntry::TYPE_REINDEX_OCR, $payload);

        $this->recordEvent($request, $command, 'job_control.ocr_reindex_requested', 'info', 'OCR reindex requested by admin.', [
            'action' => CommandEntry::TYPE_REINDEX_OCR,
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
            'action' => CommandEntry::TYPE_REINDEX_OCR,
            'actor_name' => 'reindex_ocr',
            'limit' => $limit,
            'force' => $force,
            ...$metadata,
        ]);

        return $command;
    }

    public function queueEmbeddingIndexBuild(Request $request, ?int $limit = null, array $metadata = []): CommandEntry
    {
        $limit = $this->normalizedLimit($limit);
        $payload = array_filter([
            'limit' => $limit,
            ...$metadata,
        ], fn ($value): bool => $value !== null);
        $command = $this->createCommand($request, CommandEntry::TYPE_EMBEDDING_INDEX_BUILD, $payload);

        $this->recordEvent($request, $command, 'job_control.embedding_build_requested', 'info', 'Embedding index build requested by admin.', [
            'action' => CommandEntry::TYPE_EMBEDDING_INDEX_BUILD,
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
                'action' => CommandEntry::TYPE_EMBEDDING_INDEX_BUILD,
                'actor_name' => 'build_embedding_index',
                'limit' => $limit,
                ...$metadata,
            ],
        );

        return $command;
    }

    /** @param array<string, mixed> $payload */
    private function createCommand(Request $request, string $type, array $payload): CommandEntry
    {
        return CommandEntry::query()->create([
            'type' => $type,
            'queue' => $this->queueNameFor($type),
            'priority' => $this->priorityFor($type),
            'status' => CommandEntry::STATUS_PENDING,
            'payload' => $payload,
            'created_by_user_id' => OperatorPrincipal::userId($request),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function recordEvent(Request $request, CommandEntry $command, string $eventType, string $level, string $message, array $payload): void
    {
        EventRecord::query()->create([
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
    private function recordSystemEvent(CommandEntry $command, string $eventType, string $level, string $message, array $payload): void
    {
        EventRecord::query()->create([
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
    private function audit(Request $request, string $event, CommandEntry $command, array $metadata, string $targetType = 'command'): void
    {
        AuditLogRecord::query()->create([
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

    private function enqueueCommand(CommandEntry $command, RunPythonActorJob $job): void
    {
        DB::transaction(function () use ($command, $job): void {
            $command = CommandEntry::query()->lockForUpdate()->findOrFail($command->id);
            if ($command->status !== CommandEntry::STATUS_PENDING) {
                return;
            }

            $command->forceFill([
                'status' => CommandEntry::STATUS_QUEUED,
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
            CommandEntry::TYPE_EMBEDDING_INDEX_BUILD,
            CommandEntry::TYPE_PAPERLESS_SIMILARITY_INDEX => (string) config('archibot_workers.queues.embeddings', 'embeddings'),
            CommandEntry::TYPE_CLASSIFY_WITH_ARCHIBOT,
            CommandEntry::TYPE_REVIEW_COMMIT => (string) config('archibot_workers.queues.interactive', 'interactive'),
            default => (string) config('archibot_workers.queues.maintenance', 'maintenance'),
        };
    }

    private function priorityFor(string $type): int
    {
        return match ($type) {
            CommandEntry::TYPE_EMBEDDING_INDEX_BUILD,
            CommandEntry::TYPE_PAPERLESS_SIMILARITY_INDEX => (int) config('archibot_workers.priorities.embeddings', 30),
            CommandEntry::TYPE_CLASSIFY_WITH_ARCHIBOT,
            CommandEntry::TYPE_REVIEW_COMMIT => (int) config('archibot_workers.priorities.interactive', 80),
            default => (int) config('archibot_workers.priorities.maintenance', 40),
        };
    }
}
