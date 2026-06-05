<?php

namespace App\Services\Pipeline;

use App\Jobs\RunPythonActorJob;
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
            ->whereIn('type', [
                Command::TYPE_EMBEDDING_INDEX_BUILD,
                Command::TYPE_POLL_RECONCILIATION,
                Command::TYPE_REINDEX,
                Command::TYPE_REVIEW_COMMIT,
            ])
            ->oldest('updated_at')
            ->oldest('id')
            ->limit($limit)
            ->get()
            ->each(function (Command $command) use (&$recovered): void {
                $job = match ($command->type) {
                    Command::TYPE_EMBEDDING_INDEX_BUILD => RunPythonActorJob::embeddingIndexBuild($command->id),
                    Command::TYPE_POLL_RECONCILIATION => RunPythonActorJob::pollReconciliation($command->id),
                    Command::TYPE_REINDEX => RunPythonActorJob::reindex($command->id),
                    Command::TYPE_REVIEW_COMMIT => $this->reviewCommitJobOrFail($command),
                    default => null,
                };

                if ($job === null) {
                    return;
                }

                dispatch($job);

                $command->forceFill([
                    'status' => Command::STATUS_QUEUED,
                    'error' => null,
                ])->save();

                PipelineEvent::query()->create([
                    'command_id' => $command->id,
                    'event_type' => 'recovery.command_actor_redispatched',
                    'paperless_document_id' => $command->payload['paperless_document_id'] ?? null,
                    'level' => 'info',
                    'message' => 'Pending command redispatched through Laravel actor transport by recovery scan.',
                    'payload' => [
                        'command_type' => $command->type,
                        'transport' => 'laravel_database_queue',
                    ],
                ]);

                $recovered++;
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
                dispatch(RunPythonActorJob::documentPipeline($run->id));

                $run->forceFill([
                    'status' => PipelineRun::STATUS_QUEUED,
                    'progress_current_phase' => 'document_actor',
                    'progress_message' => 'Document actor redispatched through Laravel recovery.',
                    'progress_updated_at' => now(),
                    'error_type' => null,
                    'error' => null,
                ])->save();

                PipelineEvent::query()->create([
                    'pipeline_run_id' => $run->id,
                    'webhook_delivery_id' => $run->webhook_delivery_id,
                    'command_id' => $run->command_id,
                    'event_type' => 'recovery.document_actor_redispatched',
                    'paperless_document_id' => $run->paperless_document_id,
                    'level' => 'info',
                    'message' => 'Document pipeline run redispatched through Laravel actor transport by recovery scan.',
                    'payload' => [
                        'actor_name' => 'handle_document_pipeline',
                        'transport' => 'laravel_database_queue',
                    ],
                ]);

                $recovered++;
            });

        return $recovered;
    }

    public function recoverQueuedWebhookDeliveries(int $limit = 100): int
    {
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
