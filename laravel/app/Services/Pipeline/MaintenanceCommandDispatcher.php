<?php

namespace App\Services\Pipeline;

use App\Models\AuditLog;
use App\Models\Command;
use App\Models\EmbeddingIndexState;
use App\Models\PipelineEvent;
use App\Models\WorkerJob;
use App\Services\Workers\WorkerJobDispatcher;
use Illuminate\Http\Request;

class MaintenanceCommandDispatcher
{
    public function queuePollReconciliation(Request $request, ?int $limit = null, array $metadata = []): Command
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

        $this->dispatchEmbeddingFallbackWhenAbsurdIsUnavailable($request, $command, $limit, $metadata);

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

    /** @param array<string, mixed> $metadata */
    private function dispatchEmbeddingFallbackWhenAbsurdIsUnavailable(
        Request $request,
        Command $command,
        ?int $limit,
        array $metadata,
    ): void {
        if (trim((string) config('archibot.absurd_database_url', '')) !== '') {
            return;
        }

        $workerJob = app(WorkerJobDispatcher::class)->dispatch(
            type: WorkerJob::TYPE_REINDEX_EMBED,
            payload: array_filter([
                'command_id' => $command->id,
                'limit' => $limit,
                'mode' => 'embedding_index_build',
                ...$metadata,
            ], fn ($value): bool => $value !== null),
            user: $request->user(),
            request: $request,
            dedupeKey: WorkerJobDispatcher::dispatchKey(WorkerJob::TYPE_REINDEX_EMBED, [
                'mode' => 'embedding_index_build',
            ]),
            auditEvent: 'embedding_index.legacy_fallback_worker_job_queued',
            auditMetadata: ['command_id' => $command->id],
        );

        $command->forceFill([
            'status' => Command::STATUS_QUEUED,
            'payload' => [
                ...($command->payload ?? []),
                'legacy_fallback_worker_job_id' => $workerJob->id,
            ],
        ])->save();

        $this->recordEvent(
            $request,
            $command,
            'job_control.embedding_build_legacy_fallback_queued',
            'warning',
            'Embedding build queued through temporary worker job fallback because Absurd is not configured.',
            [
                'action' => Command::TYPE_EMBEDDING_INDEX_BUILD,
                'worker_job_id' => $workerJob->id,
                'limit' => $limit,
                ...$metadata,
            ],
        );
    }

    private function normalizedLimit(?int $limit): ?int
    {
        return $limit !== null && $limit > 0 ? $limit : null;
    }
}
