<?php

namespace App\Services\Pipeline;

use App\Jobs\RunPythonActorJob;
use App\Models\AuditLog;
use App\Models\Command;
use App\Models\EmbeddingIndexState;
use App\Models\PipelineEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class MaintenanceCommandDispatcher
{
    public function queuePollReconciliation(Request $request, ?int $limit = null, array $metadata = []): Command
    {
        return Cache::lock('archibot:poll-command-dispatch', 120)->block(
            5,
            fn (): Command => $this->queuePollReconciliationUnlocked($request, $limit, $metadata),
        );
    }

    private function queuePollReconciliationUnlocked(Request $request, ?int $limit, array $metadata): Command
    {
        $limit = $this->normalizedLimit($limit);
        $payload = array_filter([
            'limit' => $limit,
            ...$metadata,
        ], fn ($value): bool => $value !== null);

        $command = $this->createCommand($request, Command::TYPE_POLL_RECONCILIATION, $payload);

        $this->recordEvent($request, $command, 'job_control.poll_reconciliation_requested', 'info', 'Polling reconciliation requested by admin.', [
            'action' => Command::TYPE_POLL_RECONCILIATION,
            'limit' => $limit,
            ...$metadata,
        ]);
        $this->audit($request, 'maintenance.poll_reconciliation_requested', $command, [
            'limit' => $limit,
            ...$metadata,
        ]);

        $this->enqueueCommand($command, RunPythonActorJob::pollReconciliation($command->id));
        $this->recordEvent($request, $command, 'job_control.poll_reconciliation_actor_queued', 'info', 'Polling reconciliation queued through Laravel actor transport.', [
            'action' => Command::TYPE_POLL_RECONCILIATION,
            'actor_name' => 'reconcile_inbox_documents',
            'limit' => $limit,
            ...$metadata,
        ]);

        return $command;
    }

    public function queueScheduledPollReconciliation(): ?Command
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

    private function queueScheduledPollReconciliationUnlocked(): ?Command
    {
        $interval = max(0, (int) config('archibot.poll_interval_seconds', 600));
        if ($interval === 0) {
            return null;
        }

        $activeExists = Command::query()
            ->where('type', Command::TYPE_POLL_RECONCILIATION)
            ->whereIn('status', Command::activeStatuses())
            ->exists();
        if ($activeExists) {
            return null;
        }

        $recentScheduledExists = Command::query()
            ->where('type', Command::TYPE_POLL_RECONCILIATION)
            ->whereIn('status', [
                Command::STATUS_SUCCEEDED,
                Command::STATUS_FAILED,
                Command::STATUS_FAILED_PERMANENT,
            ])
            ->where('payload->source', 'scheduler')
            ->whereNotNull('finished_at')
            ->where('finished_at', '>', now()->subSeconds($interval))
            ->exists();
        if ($recentScheduledExists) {
            return null;
        }

        $command = Command::query()->create([
            'type' => Command::TYPE_POLL_RECONCILIATION,
            'status' => Command::STATUS_PENDING,
            'payload' => [
                'source' => 'scheduler',
                'interval_seconds' => $interval,
            ],
            'created_by_user_id' => null,
        ]);

        $this->recordSystemEvent(
            $command,
            'scheduler.poll_reconciliation_requested',
            'info',
            'Automatic polling reconciliation requested by the Laravel scheduler.',
            ['interval_seconds' => $interval],
        );

        try {
            $this->enqueueCommand($command, RunPythonActorJob::pollReconciliation($command->id));
        } catch (Throwable $exception) {
            $command->forceFill([
                'status' => Command::STATUS_PENDING,
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
                'interval_seconds' => $interval,
            ],
        );

        return $command;
    }

    public function queueReindex(Request $request, ?int $limit = null, array $metadata = []): Command
    {
        $limit = $this->normalizedLimit($limit);
        $embeddingState = EmbeddingIndexState::query()->latest()->first();
        if ($embeddingState === null) {
            $embeddingState = EmbeddingIndexState::query()->create([
                'status' => EmbeddingIndexState::STATUS_STALE,
                'error' => 'Reindex requested by admin before an index existed.',
            ]);
        } else {
            $embeddingState->forceFill([
                'status' => EmbeddingIndexState::STATUS_STALE,
                'error' => 'Reindex requested by admin.',
            ])->save();
        }

        $payload = array_filter([
            'limit' => $limit,
            ...$metadata,
        ], fn ($value): bool => $value !== null);
        $command = $this->createCommand($request, Command::TYPE_REINDEX, $payload);

        $this->recordEvent($request, $command, 'job_control.reindex_requested', 'warning', 'Reindex requested by admin; embedding gate marked stale.', [
            'action' => Command::TYPE_REINDEX,
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
            'action' => Command::TYPE_REINDEX,
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
        $command = $this->createCommand($request, Command::TYPE_REINDEX_OCR, $payload);

        $this->recordEvent($request, $command, 'job_control.ocr_reindex_requested', 'info', 'OCR reindex requested by admin.', [
            'action' => Command::TYPE_REINDEX_OCR,
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
            'action' => Command::TYPE_REINDEX_OCR,
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
        $command = $this->createCommand($request, Command::TYPE_EMBEDDING_INDEX_BUILD, $payload);

        $this->recordEvent($request, $command, 'job_control.embedding_build_requested', 'info', 'Embedding index build requested by admin.', [
            'action' => Command::TYPE_EMBEDDING_INDEX_BUILD,
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
                'action' => Command::TYPE_EMBEDDING_INDEX_BUILD,
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
        return Command::query()->create([
            'type' => $type,
            'status' => Command::STATUS_PENDING,
            'payload' => $payload,
            'created_by_user_id' => $request->user()->id,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function recordEvent(Request $request, Command $command, string $eventType, string $level, string $message, array $payload): void
    {
        PipelineEvent::query()->create([
            'command_id' => $command->id,
            'event_type' => $eventType,
            'level' => $level,
            'message' => $message,
            'payload' => [
                'actor_user_id' => $request->user()->id,
                'actor_is_admin' => true,
                'command_id' => $command->id,
                ...$payload,
            ],
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function recordSystemEvent(Command $command, string $eventType, string $level, string $message, array $payload): void
    {
        PipelineEvent::query()->create([
            'command_id' => $command->id,
            'event_type' => $eventType,
            'level' => $level,
            'message' => $message,
            'payload' => [
                'actor' => 'laravel_scheduler',
                'command_id' => $command->id,
                ...$payload,
            ],
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function audit(Request $request, string $event, Command $command, array $metadata, string $targetType = 'command'): void
    {
        AuditLog::query()->create([
            'actor_user_id' => $request->user()->id,
            'event' => $event,
            'target_type' => $targetType,
            'target_id' => (string) $command->id,
            'metadata' => [
                'command_id' => $command->id,
                ...$metadata,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    private function enqueueCommand(Command $command, RunPythonActorJob $job): void
    {
        DB::transaction(function () use ($command, $job): void {
            $command = Command::query()->lockForUpdate()->findOrFail($command->id);
            if ($command->status !== Command::STATUS_PENDING) {
                return;
            }

            $command->forceFill([
                'status' => Command::STATUS_QUEUED,
                'error' => null,
            ])->save();
            dispatch($job);
        });
        $command->refresh();
    }

    private function normalizedLimit(?int $limit): ?int
    {
        return $limit !== null && $limit > 0 ? $limit : null;
    }
}
