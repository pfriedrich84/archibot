<?php

namespace App\Services\Pipeline;

use App\Jobs\RunPythonActorJob;
use App\Models\ActorExecution;
use App\Models\Command;
use App\Models\EmbeddingIndexState;
use App\Models\PipelineEvent;
use App\Models\PipelineRun;
use App\Models\WebhookDelivery;

class PipelineRecoveryDispatcher
{
    public function recoverPendingCommands(int $limit = 100): int
    {
        $recovered = 0;

        Command::query()
            ->where('status', Command::STATUS_PENDING)
            ->whereIn('type', $this->recoverableCommandTypes())
            ->oldest('updated_at')
            ->oldest('id')
            ->limit($limit)
            ->get()
            ->each(function (Command $command) use (&$recovered): void {
                if ($this->redispatchCommand(
                    $command,
                    'recovery.command_actor_redispatched',
                    'Pending command redispatched through Laravel actor transport by recovery scan.',
                )) {
                    $recovered++;
                }
            });

        $remaining = max(0, $limit - $recovered);
        if ($remaining <= 0) {
            return $recovered;
        }

        Command::query()
            ->where('status', Command::STATUS_QUEUED)
            ->whereIn('type', $this->recoverableCommandTypes())
            ->where('updated_at', '<=', $this->staleQueuedCutoff())
            ->oldest('updated_at')
            ->oldest('id')
            ->limit($remaining)
            ->get()
            ->each(function (Command $command) use (&$recovered): void {
                $actorName = $this->commandActorName($command->type);
                if ($actorName !== null && $this->hasActiveCommandActor($actorName)) {
                    return;
                }

                if ($this->redispatchCommand(
                    $command,
                    'recovery.stale_queued_command_actor_redispatched',
                    'Stale queued command redispatched through Laravel actor transport by recovery scan.',
                )) {
                    $recovered++;
                }
            });

        return $recovered;
    }

    public function recoverDocumentPipelineRuns(int $limit = 100): int
    {
        $this->releaseEmbeddingBlockedRuns($limit);

        $recovered = 0;

        PipelineRun::query()
            ->where('type', 'document')
            ->where(function ($query): void {
                $query->where('status', PipelineRun::STATUS_PENDING)
                    ->orWhere(function ($query): void {
                        $query->where('status', PipelineRun::STATUS_RETRYING)
                            ->where(function ($query): void {
                                $query->whereNull('next_retry_at')
                                    ->orWhere('next_retry_at', '<=', now());
                            });
                    });
            })
            ->oldest('updated_at')
            ->oldest('id')
            ->limit($limit)
            ->get()
            ->each(function (PipelineRun $run) use (&$recovered): void {
                $this->redispatchDocumentRun(
                    $run,
                    'recovery.document_actor_redispatched',
                    'Document pipeline run redispatched through Laravel actor transport by recovery scan.',
                    'Document actor redispatched through Laravel recovery.',
                );

                $recovered++;
            });

        $remaining = max(0, $limit - $recovered);
        if ($remaining <= 0) {
            return $recovered;
        }

        PipelineRun::query()
            ->where('type', 'document')
            ->where('status', PipelineRun::STATUS_QUEUED)
            ->whereRaw('COALESCE(progress_updated_at, updated_at) <= ?', [$this->staleQueuedCutoff()->toDateTimeString()])
            ->whereDoesntHave('events', function ($query): void {
                $query->where('event_type', 'recovery.document_actor_redispatched')
                    ->where('created_at', '>', $this->staleQueuedCutoff());
            })
            ->oldest('updated_at')
            ->oldest('id')
            ->limit($remaining)
            ->get()
            ->each(function (PipelineRun $run) use (&$recovered): void {
                if ($this->hasActivePipelineActor($run)) {
                    return;
                }

                $this->redispatchDocumentRun(
                    $run,
                    'recovery.stale_queued_document_actor_redispatched',
                    'Stale queued document pipeline run redispatched through Laravel actor transport by recovery scan.',
                    'Document actor redispatched from stale queued state by Laravel recovery.',
                );

                $recovered++;
            });

        return $recovered;
    }

    public function recoverQueuedWebhookDeliveries(int $limit = 100): int
    {
        $this->releaseEmbeddingBlockedWebhookDeliveries($limit);

        $recovered = 0;

        WebhookDelivery::query()
            ->where('status', WebhookDelivery::STATUS_QUEUED)
            ->oldest('received_at')
            ->oldest('id')
            ->limit($limit)
            ->get()
            ->each(function (WebhookDelivery $delivery) use (&$recovered): void {
                if (($delivery->normalized_payload['webhook_action'] ?? null) === 'process_document') {
                    return;
                }

                dispatch(RunPythonActorJob::webhookDelivery($delivery->id));

                PipelineEvent::query()->create([
                    'webhook_delivery_id' => $delivery->id,
                    'event_type' => 'recovery.webhook_actor_redispatched',
                    'paperless_document_id' => $delivery->paperless_document_id,
                    'level' => 'info',
                    'message' => 'Queued webhook delivery redispatched through Laravel actor transport by recovery scan.',
                    'payload' => [
                        'actor_name' => 'handle_paperless_webhook',
                        'transport' => 'laravel_database_queue',
                        'webhook_action' => $delivery->normalized_payload['webhook_action'] ?? null,
                    ],
                ]);

                $recovered++;
            });

        return $recovered;
    }

    /**
     * @return array<int, string>
     */
    private function recoverableCommandTypes(): array
    {
        return [
            Command::TYPE_EMBEDDING_INDEX_BUILD,
            Command::TYPE_POLL_RECONCILIATION,
            Command::TYPE_REINDEX,
            Command::TYPE_REINDEX_OCR,
            Command::TYPE_REVIEW_COMMIT,
        ];
    }

    private function redispatchCommand(Command $command, string $eventType, string $message): bool
    {
        $job = match ($command->type) {
            Command::TYPE_EMBEDDING_INDEX_BUILD => RunPythonActorJob::embeddingIndexBuild($command->id),
            Command::TYPE_POLL_RECONCILIATION => RunPythonActorJob::pollReconciliation($command->id),
            Command::TYPE_REINDEX => RunPythonActorJob::reindex($command->id),
            Command::TYPE_REINDEX_OCR => RunPythonActorJob::reindexOcr($command->id),
            Command::TYPE_REVIEW_COMMIT => $this->reviewCommitJobOrFail($command),
            default => null,
        };

        if ($job === null) {
            return false;
        }

        dispatch($job);

        $command->forceFill([
            'status' => Command::STATUS_QUEUED,
            'error' => null,
        ])->save();

        PipelineEvent::query()->create([
            'command_id' => $command->id,
            'event_type' => $eventType,
            'paperless_document_id' => $command->payload['paperless_document_id'] ?? null,
            'level' => 'info',
            'message' => $message,
            'payload' => [
                'command_type' => $command->type,
                'transport' => 'laravel_database_queue',
                'stale_queued_minutes' => $this->staleQueuedMinutes(),
            ],
        ]);

        return true;
    }

    private function redispatchDocumentRun(
        PipelineRun $run,
        string $eventType,
        string $eventMessage,
        string $progressMessage,
    ): void {
        dispatch(RunPythonActorJob::documentPipeline($run->id));

        $run->forceFill([
            'status' => PipelineRun::STATUS_QUEUED,
            'progress_current_phase' => 'document_actor',
            'progress_message' => $progressMessage,
            'progress_updated_at' => now(),
            'error_type' => null,
            'error' => null,
        ])->save();

        PipelineEvent::query()->create([
            'pipeline_run_id' => $run->id,
            'webhook_delivery_id' => $run->webhook_delivery_id,
            'command_id' => $run->command_id,
            'event_type' => $eventType,
            'paperless_document_id' => $run->paperless_document_id,
            'level' => 'info',
            'message' => $eventMessage,
            'payload' => [
                'actor_name' => 'handle_document_pipeline',
                'transport' => 'laravel_database_queue',
                'stale_queued_minutes' => $this->staleQueuedMinutes(),
            ],
        ]);
    }

    private function hasActivePipelineActor(PipelineRun $run): bool
    {
        return ActorExecution::query()
            ->where('pipeline_run_id', $run->id)
            ->whereIn('status', $this->activeActorStatuses())
            ->exists();
    }

    private function hasActiveCommandActor(string $actorName): bool
    {
        return ActorExecution::query()
            ->whereNull('pipeline_run_id')
            ->where('actor_name', $actorName)
            ->whereIn('status', $this->activeActorStatuses())
            ->exists();
    }

    /**
     * @return array<int, string>
     */
    private function activeActorStatuses(): array
    {
        return [
            ActorExecution::STATUS_QUEUED,
            ActorExecution::STATUS_RUNNING,
            ActorExecution::STATUS_RETRYING,
        ];
    }

    private function commandActorName(string $type): ?string
    {
        return match ($type) {
            Command::TYPE_POLL_RECONCILIATION => 'reconcile_inbox_documents',
            Command::TYPE_REINDEX => 'reindex',
            Command::TYPE_REINDEX_OCR => 'reindex_ocr',
            Command::TYPE_EMBEDDING_INDEX_BUILD => 'build_embedding_index',
            Command::TYPE_REVIEW_COMMIT => 'commit_review_suggestion',
            default => null,
        };
    }

    private function staleQueuedCutoff()
    {
        return now()->subMinutes($this->staleQueuedMinutes());
    }

    private function staleQueuedMinutes(): int
    {
        return max(1, (int) config('archibot_workers.stale_queued_minutes', 5));
    }

    private function releaseEmbeddingBlockedWebhookDeliveries(int $limit): int
    {
        if (EmbeddingIndexState::query()->latest()->value('status') !== EmbeddingIndexState::STATUS_COMPLETE) {
            return 0;
        }

        $released = 0;
        WebhookDelivery::query()
            ->where('status', WebhookDelivery::STATUS_BLOCKED)
            ->where('error', DocumentPipelineStarter::BLOCKED_REASON_EMBEDDING_INDEX_NOT_READY)
            ->oldest('received_at')
            ->oldest('id')
            ->limit($limit)
            ->get()
            ->each(function (WebhookDelivery $delivery) use (&$released): void {
                if (($delivery->normalized_payload['webhook_action'] ?? null) === 'process_document') {
                    return;
                }

                $delivery->forceFill([
                    'status' => WebhookDelivery::STATUS_QUEUED,
                    'error' => null,
                    'processed_at' => null,
                ])->save();

                PipelineEvent::query()->create([
                    'webhook_delivery_id' => $delivery->id,
                    'event_type' => 'recovery.webhook_embedding_gate_released',
                    'paperless_document_id' => $delivery->paperless_document_id,
                    'level' => 'info',
                    'message' => 'Webhook delivery released by Laravel recovery because the embedding index is complete.',
                    'payload' => [
                        'webhook_action' => $delivery->normalized_payload['webhook_action'] ?? null,
                        'blocked_reason' => DocumentPipelineStarter::BLOCKED_REASON_EMBEDDING_INDEX_NOT_READY,
                    ],
                ]);

                $released++;
            });

        return $released;
    }

    private function reviewCommitJobOrFail(Command $command): ?RunPythonActorJob
    {
        $reviewSuggestionId = $command->payload['review_suggestion_id'] ?? null;
        if (! is_int($reviewSuggestionId) || $reviewSuggestionId <= 0) {
            $command->forceFill([
                'status' => Command::STATUS_FAILED_PERMANENT,
                'error' => 'missing_review_suggestion_id',
                'finished_at' => now(),
            ])->save();

            PipelineEvent::query()->create([
                'command_id' => $command->id,
                'event_type' => 'recovery.command_failed_permanent',
                'paperless_document_id' => $command->payload['paperless_document_id'] ?? null,
                'level' => 'error',
                'message' => 'Review commit command could not be redispatched because payload.review_suggestion_id is missing.',
                'payload' => [
                    'command_type' => $command->type,
                    'error_type' => 'missing_review_suggestion_id',
                ],
            ]);

            return null;
        }

        return RunPythonActorJob::reviewCommit($command->id);
    }

    private function releaseEmbeddingBlockedRuns(int $limit): int
    {
        if (EmbeddingIndexState::query()->latest()->value('status') !== EmbeddingIndexState::STATUS_COMPLETE) {
            return 0;
        }

        $released = 0;
        PipelineRun::query()
            ->where('type', 'document')
            ->where('status', PipelineRun::STATUS_BLOCKED)
            ->where('error_type', DocumentPipelineStarter::BLOCKED_REASON_EMBEDDING_INDEX_NOT_READY)
            ->oldest('updated_at')
            ->oldest('id')
            ->limit($limit)
            ->get()
            ->each(function (PipelineRun $run) use (&$released): void {
                $run->forceFill([
                    'status' => PipelineRun::STATUS_PENDING,
                    'progress_current_phase' => 'queued',
                    'progress_message' => 'Released by Laravel recovery because the embedding index is complete.',
                    'progress_updated_at' => now(),
                    'error_type' => null,
                    'error' => null,
                ])->save();

                PipelineEvent::query()->create([
                    'pipeline_run_id' => $run->id,
                    'webhook_delivery_id' => $run->webhook_delivery_id,
                    'command_id' => $run->command_id,
                    'event_type' => 'recovery.embedding_gate_released',
                    'paperless_document_id' => $run->paperless_document_id,
                    'level' => 'info',
                    'message' => 'Pipeline run released by Laravel recovery because the embedding index is complete.',
                    'payload' => [
                        'blocked_reason' => DocumentPipelineStarter::BLOCKED_REASON_EMBEDDING_INDEX_NOT_READY,
                    ],
                ]);

                $released++;
            });

        return $released;
    }
}
