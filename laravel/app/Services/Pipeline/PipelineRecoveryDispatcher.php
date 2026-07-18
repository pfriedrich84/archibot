<?php

namespace App\Services\Pipeline;

use App\Jobs\ApplyEntityApprovalCommand;
use App\Jobs\RunPythonActorJob;
use App\Models\ActorExecution;
use App\Models\Command;
use App\Models\EmbeddingIndexState;
use App\Models\PipelineRun;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PipelineRecoveryDispatcher
{
    public function __construct(
        private readonly DocumentPipelineStarter $pipelineStarter,
        private readonly PollCandidateConsumer $pollCandidates,
    ) {}

    /**
     * @return array<string, int>
     */
    public function runRecoveryScan(int $limit = 100): array
    {
        $lock = Cache::lock('archibot:pipeline-recovery-scan', 900);
        if (! $lock->get()) {
            return ['scan_skipped_locked' => 1];
        }

        try {
            $actors = $this->recoverActorExecutions($limit);
            $pollCandidates = $this->pollCandidates->replayPending($limit);
            $cancelled = $this->finalizeCancelRequestedRuns($limit);
            $documentRuns = $this->recoverDocumentPipelineRuns($limit);
            $webhookDeliveries = $this->recoverQueuedWebhookDeliveries($limit);
            $commands = $this->recoverPendingCommands($limit);

            return [
                'actor_executions_stale' => $actors['stale'],
                'actor_executions_redispatched' => $actors['redispatched'],
                'actor_executions_failed_permanent' => $actors['failed_permanent'],
                'poll_candidates_completed' => $pollCandidates['completed'],
                'poll_candidates_skipped' => $pollCandidates['skipped'],
                'poll_candidates_failed' => $pollCandidates['failed'],
                'pipeline_runs_cancelled' => $cancelled,
                'webhook_deliveries_redispatched' => $webhookDeliveries,
                'document_pipeline_runs_redispatched' => $documentRuns,
                'commands_redispatched' => $commands,
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{stale: int, redispatched: int, failed_permanent: int}
     */
    public function recoverActorExecutions(int $limit = 100): array
    {
        $stale = 0;
        $redispatched = 0;
        $failedPermanent = 0;

        ActorExecution::query()
            ->whereIn('status', [ActorExecution::STATUS_QUEUED, ActorExecution::STATUS_RUNNING])
            ->whereRaw('COALESCE(progress_updated_at, started_at, updated_at) <= ?', [$this->staleRunningCutoff()])
            ->oldest('started_at')
            ->oldest('id')
            ->limit($limit)
            ->get()
            ->each(function (ActorExecution $execution) use (&$stale, &$failedPermanent): void {
                $outcome = $this->recoverStaleActorExecution($execution);
                if ($outcome === 'retrying') {
                    $stale++;
                } elseif ($outcome === 'failed_permanent') {
                    $failedPermanent++;
                }
            });

        $remaining = max(0, $limit - $failedPermanent);
        if ($remaining <= 0) {
            return [
                'stale' => $stale,
                'redispatched' => $redispatched,
                'failed_permanent' => $failedPermanent,
            ];
        }

        ActorExecution::query()
            ->where('status', ActorExecution::STATUS_RETRYING)
            ->where(function ($query): void {
                $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now());
            })
            ->oldest('next_retry_at')
            ->oldest('id')
            ->limit($remaining)
            ->get()
            ->each(function (ActorExecution $execution) use (&$redispatched, &$failedPermanent): void {
                $outcome = $this->recoverRetryingActorExecution($execution);
                if ($outcome === 'redispatched') {
                    $redispatched++;
                } elseif ($outcome === 'failed_permanent') {
                    $failedPermanent++;
                }
            });

        return [
            'stale' => $stale,
            'redispatched' => $redispatched,
            'failed_permanent' => $failedPermanent,
        ];
    }

    private function recoverStaleActorExecution(ActorExecution $selected): string
    {
        return DB::transaction(function () use ($selected): string {
            $execution = ActorExecution::query()->lockForUpdate()->find($selected->id);
            if ($execution === null
                || ! in_array($execution->status, [ActorExecution::STATUS_QUEUED, ActorExecution::STATUS_RUNNING], true)
                || $this->reconcileActorExecutionToTerminalSource($execution)
                || $this->isActorProcessAlive($execution)) {
                return 'ignored';
            }
            if ($execution->attempt >= $execution->max_attempts) {
                $this->markActorExecutionPermanentFailure($execution, 'stale_actor_attempts_exhausted');

                return 'failed_permanent';
            }

            $execution->update([
                'status' => ActorExecution::STATUS_RETRYING,
                'finished_at' => now(),
                'retry_reason' => 'worker_recovery_stale_actor',
                'retry_mode' => 'recovery',
                'last_retry_at' => now(),
                'next_retry_at' => now(),
                'error_type' => 'worker_recovery_stale_actor',
                'error_message' => 'Actor execution was left running and recovered by Laravel recovery.',
            ]);
            $this->markActorSourceRetryable($execution);
            $this->recordActorRecoveryEvent(
                $execution,
                'recovery.actor_execution_marked_retrying',
                'Stale actor execution marked retrying by Laravel recovery.',
            );

            return 'retrying';
        });
    }

    private function markActorSourceRetryable(ActorExecution $execution): void
    {
        if ($execution->pipeline_run_id !== null) {
            PipelineRun::query()
                ->whereKey($execution->pipeline_run_id)
                ->where('status', PipelineRun::STATUS_RUNNING)
                ->where('lifecycle_version', $execution->source_version)
                ->where('active_actor_token', $execution->execution_token)
                ->update([
                    'status' => PipelineRun::STATUS_RETRYING,
                    'active_actor_token' => null,
                    'next_retry_at' => now(),
                    'retry_reason' => 'worker_recovery_stale_actor',
                    'error_type' => 'worker_recovery_stale_actor',
                    'error' => 'Stale actor execution scheduled for Laravel recovery.',
                    'updated_at' => now(),
                ]);
        } elseif ($execution->command_id !== null) {
            Command::query()
                ->whereKey($execution->command_id)
                ->where('status', Command::STATUS_RUNNING)
                ->where('lifecycle_version', $execution->source_version)
                ->where('active_actor_token', $execution->execution_token)
                ->update([
                    'status' => Command::STATUS_PENDING,
                    'active_actor_token' => null,
                    'error' => 'worker_recovery_stale_actor',
                    'finished_at' => null,
                    'updated_at' => now(),
                ]);
        } elseif ($execution->webhook_delivery_id !== null) {
            WebhookDelivery::query()
                ->whereKey($execution->webhook_delivery_id)
                ->where('status', WebhookDelivery::STATUS_RUNNING)
                ->where('lifecycle_version', $execution->source_version)
                ->where('active_actor_token', $execution->execution_token)
                ->update([
                    'status' => WebhookDelivery::STATUS_FAILED,
                    'active_actor_token' => null,
                    'error' => 'recoverable_processing',
                    'updated_at' => now(),
                ]);
        }
    }

    private function recoverRetryingActorExecution(ActorExecution $selected): string
    {
        return DB::transaction(function () use ($selected): string {
            $execution = ActorExecution::query()->lockForUpdate()->find($selected->id);
            if ($execution === null || $execution->status !== ActorExecution::STATUS_RETRYING) {
                return 'ignored';
            }
            if ($this->reconcileActorExecutionToTerminalSource($execution)) {
                return 'ignored';
            }
            $run = $execution->pipeline_run_id === null
                ? null
                : PipelineRun::query()->find($execution->pipeline_run_id);
            if ($run?->status === PipelineRun::STATUS_RETRYING
                && $run->next_retry_at !== null
                && $run->next_retry_at->isFuture()) {
                return 'ignored';
            }
            if ($run?->status === PipelineRun::STATUS_CANCEL_REQUESTED) {
                $execution->update([
                    'status' => ActorExecution::STATUS_CANCELLED,
                    'finished_at' => $execution->finished_at ?? now(),
                    'error_type' => 'cancel_requested',
                    'error_message' => 'Retry suppressed because pipeline cancellation was requested.',
                ]);

                return 'ignored';
            }
            if ($this->hasNewerActiveActorExecution($execution)
                || $this->hasNewerActorSourceDispatch($execution)
                || $this->actorSourceHasQueuedOrRunningClaim($execution)) {
                $this->markActorExecutionSuperseded($execution);

                return 'ignored';
            }
            if ($execution->attempt >= $execution->max_attempts) {
                $this->markActorExecutionPermanentFailure($execution, 'actor_retry_attempts_exhausted');

                return 'failed_permanent';
            }

            if ($this->redispatchActorSource($execution)) {
                $execution->update([
                    'status' => ActorExecution::STATUS_FAILED,
                    'finished_at' => $execution->finished_at ?? now(),
                    'error_type' => $execution->error_type ?? 'actor_retry_redispatched',
                    'error_message' => $execution->error_message ?? 'A new actor attempt was dispatched by Laravel recovery.',
                ]);
                $this->recordActorRecoveryEvent(
                    $execution,
                    'recovery.actor_execution_redispatched',
                    'Retrying actor execution redispatched through Laravel actor transport.',
                );

                return 'redispatched';
            }

            if ($this->reconcileActorExecutionToTerminalSource($execution)) {
                return 'ignored';
            }
            if ($this->hasNewerActiveActorExecution($execution)
                || $this->actorSourceIsInFlight($execution)) {
                $this->markActorExecutionSuperseded($execution);

                return 'ignored';
            }
            if ($this->actorSourceExists($execution)) {
                $this->recordActorRecoveryEvent(
                    $execution,
                    'recovery.actor_execution_claim_lost',
                    'Retrying actor execution source changed during recovery; a later scan will reconcile it.',
                );

                return 'ignored';
            }

            $this->markActorExecutionPermanentFailure($execution, 'actor_retry_source_missing');

            return 'failed_permanent';
        });
    }

    private function hasNewerActiveActorExecution(ActorExecution $execution): bool
    {
        return ActorExecution::query()
            ->where('id', '>', $execution->id)
            ->where('actor_name', $execution->actor_name)
            ->whereIn('status', $this->activeActorStatuses())
            ->where(function ($query) use ($execution): void {
                if ($execution->pipeline_run_id !== null) {
                    $query->where('pipeline_run_id', $execution->pipeline_run_id);
                } elseif ($execution->command_id !== null) {
                    $query->where('command_id', $execution->command_id);
                } elseif ($execution->webhook_delivery_id !== null) {
                    $query->where('webhook_delivery_id', $execution->webhook_delivery_id);
                } else {
                    $query->whereRaw('1 = 0');
                }
            })
            ->exists();
    }

    private function hasNewerActorSourceDispatch(ActorExecution $execution): bool
    {
        $query = PipelineEvent::query()
            ->whereIn('event_type', [
                'recovery.actor_source_pipeline_redispatched',
                'recovery.actor_source_command_redispatched',
                'recovery.actor_source_webhook_redispatched',
            ])
            ->where('created_at', '>=', $execution->last_retry_at ?? $execution->updated_at);
        if ($execution->pipeline_run_id !== null) {
            $query->where('pipeline_run_id', $execution->pipeline_run_id);
        } elseif ($execution->command_id !== null) {
            $query->where('command_id', $execution->command_id);
        } elseif ($execution->webhook_delivery_id !== null) {
            $query->where('webhook_delivery_id', $execution->webhook_delivery_id);
        } else {
            return false;
        }

        return $query->exists();
    }

    private function actorSourceExists(ActorExecution $execution): bool
    {
        return match (true) {
            $execution->pipeline_run_id !== null => PipelineRun::query()->whereKey($execution->pipeline_run_id)->exists(),
            $execution->command_id !== null => Command::query()->whereKey($execution->command_id)->exists(),
            $execution->webhook_delivery_id !== null => WebhookDelivery::query()->whereKey($execution->webhook_delivery_id)->exists(),
            default => false,
        };
    }

    private function actorSourceHasQueuedOrRunningClaim(ActorExecution $execution): bool
    {
        return match (true) {
            $execution->pipeline_run_id !== null => PipelineRun::query()
                ->whereKey($execution->pipeline_run_id)
                ->whereIn('status', [PipelineRun::STATUS_QUEUED, PipelineRun::STATUS_RUNNING])
                ->exists(),
            $execution->command_id !== null => Command::query()
                ->whereKey($execution->command_id)
                ->whereIn('status', [Command::STATUS_QUEUED, Command::STATUS_RUNNING])
                ->exists(),
            $execution->webhook_delivery_id !== null => WebhookDelivery::query()
                ->whereKey($execution->webhook_delivery_id)
                ->whereIn('status', [WebhookDelivery::STATUS_QUEUED, WebhookDelivery::STATUS_RUNNING])
                ->exists(),
            default => false,
        };
    }

    private function actorSourceIsInFlight(ActorExecution $execution): bool
    {
        return match (true) {
            $execution->pipeline_run_id !== null => PipelineRun::query()
                ->whereKey($execution->pipeline_run_id)
                ->whereIn('status', [
                    PipelineRun::STATUS_PENDING,
                    PipelineRun::STATUS_QUEUED,
                    PipelineRun::STATUS_RUNNING,
                    PipelineRun::STATUS_RETRYING,
                ])->exists(),
            $execution->command_id !== null => Command::query()
                ->whereKey($execution->command_id)
                ->whereIn('status', Command::activeStatuses())->exists(),
            $execution->webhook_delivery_id !== null => WebhookDelivery::query()
                ->whereKey($execution->webhook_delivery_id)
                ->whereIn('status', [
                    WebhookDelivery::STATUS_RECEIVED,
                    WebhookDelivery::STATUS_QUEUED,
                    WebhookDelivery::STATUS_RUNNING,
                ])->exists(),
            default => false,
        };
    }

    private function markActorExecutionSuperseded(ActorExecution $execution): void
    {
        $execution->update([
            'status' => ActorExecution::STATUS_SKIPPED,
            'finished_at' => $execution->finished_at ?? now(),
            'next_retry_at' => null,
            'error_type' => 'superseded_by_newer_attempt',
            'error_message' => 'Retry suppressed because a newer source dispatch or actor attempt is active.',
        ]);
        $this->recordActorRecoveryEvent(
            $execution,
            'recovery.actor_execution_superseded',
            'Retrying actor execution superseded by a newer dispatch or active attempt.',
        );
    }

    public function finalizeCancelRequestedRuns(int $limit = 100): int
    {
        $cancelled = 0;

        PipelineRun::query()
            ->where('status', PipelineRun::STATUS_CANCEL_REQUESTED)
            ->oldest('updated_at')
            ->oldest('id')
            ->limit($limit)
            ->get()
            ->each(function (PipelineRun $run) use (&$cancelled): void {
                if ($this->hasInFlightPipelineActor($run)
                    || $run->progress_updated_at?->isAfter($this->staleRunningCutoff())) {
                    return;
                }

                ActorExecution::query()
                    ->where('pipeline_run_id', $run->id)
                    ->where('status', ActorExecution::STATUS_RETRYING)
                    ->update([
                        'status' => ActorExecution::STATUS_CANCELLED,
                        'finished_at' => now(),
                        'error_type' => 'cancel_requested',
                        'error_message' => 'Pending retry cancelled by Laravel recovery.',
                        'updated_at' => now(),
                    ]);

                $run->update([
                    'status' => PipelineRun::STATUS_CANCELLED,
                    'finished_at' => now(),
                    'progress_current_phase' => 'cancelled',
                    'progress_message' => 'Pipeline run cancelled by Laravel recovery.',
                    'progress_updated_at' => now(),
                    'next_retry_at' => null,
                ]);

                PipelineLifecycleRecorder::event([
                    'pipeline_run_id' => $run->id,
                    'webhook_delivery_id' => $run->webhook_delivery_id,
                    'command_id' => $run->command_id,
                    'event_type' => 'pipeline.cancelled',
                    'paperless_document_id' => $run->paperless_document_id,
                    'level' => 'warning',
                    'message' => 'Pipeline run cancellation finalized by Laravel recovery.',
                    'payload' => ['transport' => 'laravel_database_queue'],
                ]);
                $cancelled++;
            });

        return $cancelled;
    }

    public function recoverPendingCommands(int $limit = 100): int
    {
        $recovered = 0;

        Command::query()
            ->where('status', Command::STATUS_PENDING)
            ->where(function ($query): void {
                $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now());
            })
            ->whereIn('type', $this->recoverableCommandTypes())
            ->oldest('updated_at')
            ->oldest('id')
            ->limit($limit)
            ->get()
            ->each(function (Command $command) use (&$recovered): void {
                if ($this->hasActiveCommandActor($command)) {
                    return;
                }
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
                if ($this->hasActiveCommandActor($command)) {
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

        $remaining = max(0, $limit - $recovered);
        if ($remaining <= 0) {
            return $recovered;
        }

        Command::query()
            ->where('status', Command::STATUS_RUNNING)
            ->whereIn('type', $this->recoverableCommandTypes())
            ->where('updated_at', '<=', $this->staleRunningCutoff())
            ->oldest('updated_at')
            ->oldest('id')
            ->limit($remaining)
            ->get()
            ->each(function (Command $command) use (&$recovered): void {
                if ($this->hasActiveCommandActor($command)) {
                    return;
                }

                if ($this->redispatchCommand(
                    $command,
                    'recovery.stale_running_command_actor_redispatched',
                    'Stale running command redispatched through Laravel actor transport by recovery scan.',
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
                if ($this->hasActivePipelineActor($run)) {
                    return;
                }
                if ($this->redispatchDocumentRun(
                    $run,
                    'recovery.document_actor_redispatched',
                    'Document pipeline run redispatched through Laravel actor transport by recovery scan.',
                    'Document actor redispatched through Laravel recovery.',
                )) {
                    $recovered++;
                }
            });

        $remaining = max(0, $limit - $recovered);
        if ($remaining <= 0) {
            return $recovered;
        }

        PipelineRun::query()
            ->where('type', 'document')
            ->where('status', PipelineRun::STATUS_QUEUED)
            ->whereRaw('COALESCE(progress_updated_at, updated_at) <= ?', [$this->staleQueuedCutoff()])
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

                if ($this->redispatchDocumentRun(
                    $run,
                    'recovery.stale_queued_document_actor_redispatched',
                    'Stale queued document pipeline run redispatched through Laravel actor transport by recovery scan.',
                    'Document actor redispatched from stale queued state by Laravel recovery.',
                )) {
                    $recovered++;
                }
            });

        $remaining = max(0, $limit - $recovered);
        if ($remaining <= 0) {
            return $recovered;
        }

        PipelineRun::query()
            ->where('type', 'document')
            ->where('status', PipelineRun::STATUS_RUNNING)
            ->whereRaw('COALESCE(progress_updated_at, started_at, updated_at) <= ?', [$this->staleRunningCutoff()])
            ->oldest('updated_at')
            ->oldest('id')
            ->limit($remaining)
            ->get()
            ->each(function (PipelineRun $run) use (&$recovered): void {
                if ($this->hasActivePipelineActor($run)) {
                    return;
                }

                if ($this->redispatchDocumentRun(
                    $run,
                    'recovery.stale_running_document_actor_redispatched',
                    'Stale running document pipeline run redispatched through Laravel actor transport by recovery scan.',
                    'Document actor redispatched from stale running state by Laravel recovery.',
                )) {
                    $recovered++;
                }
            });

        return $recovered;
    }

    public function recoverQueuedWebhookDeliveries(int $limit = 100): int
    {
        $this->releaseEmbeddingBlockedWebhookDeliveries($limit);

        $recovered = $this->recoverProcessWebhookDeliveries($limit);
        $remaining = max(0, $limit - $recovered);
        if ($remaining <= 0) {
            return $recovered;
        }

        WebhookDelivery::query()
            ->where(function ($query): void {
                $query->whereIn('status', [WebhookDelivery::STATUS_RECEIVED, WebhookDelivery::STATUS_QUEUED])
                    ->orWhere(function ($query): void {
                        $query->where('status', WebhookDelivery::STATUS_RUNNING)
                            ->where('updated_at', '<=', $this->staleRunningCutoff());
                    });
            })
            ->whereDoesntHave('events', function ($query): void {
                $query->whereIn('event_type', [
                    'webhook.enqueue_requested',
                    'recovery.webhook_actor_redispatched',
                    'recovery.actor_source_webhook_redispatched',
                    'recovery.failed_webhook_actor_redispatched',
                ])->where('created_at', '>', $this->staleQueuedCutoff());
            })
            ->oldest('received_at')
            ->oldest('id')
            ->limit($remaining)
            ->get()
            ->each(function (WebhookDelivery $delivery) use (&$recovered): void {
                if (($delivery->normalized_payload['webhook_action'] ?? null) === 'process_document'
                    || $this->hasActiveWebhookActor($delivery)) {
                    return;
                }

                if ($this->redispatchWebhookDelivery(
                    $delivery,
                    'recovery.webhook_actor_redispatched',
                    'Queued webhook delivery redispatched through Laravel actor transport by recovery scan.',
                )) {
                    $recovered++;
                }
            });

        $remaining = max(0, $limit - $recovered);
        if ($remaining <= 0) {
            return $recovered;
        }

        WebhookDelivery::query()
            ->where('status', WebhookDelivery::STATUS_FAILED)
            ->where(function ($query): void {
                $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now());
            })
            ->whereIn('error', $this->retryableWebhookErrors())
            ->whereDoesntHave('events', function ($query): void {
                $query->where('event_type', 'recovery.failed_webhook_actor_redispatched')
                    ->where('created_at', '>', $this->staleQueuedCutoff());
            })
            ->oldest('updated_at')
            ->oldest('id')
            ->limit($remaining)
            ->get()
            ->each(function (WebhookDelivery $delivery) use (&$recovered): void {
                if ($this->hasActiveWebhookActor($delivery)) {
                    return;
                }

                if ($this->redispatchWebhookDelivery(
                    $delivery,
                    'recovery.failed_webhook_actor_redispatched',
                    'Retryable failed webhook delivery redispatched through Laravel actor transport.',
                )) {
                    $recovered++;
                }
            });

        return $recovered;
    }

    private function recoverProcessWebhookDeliveries(int $limit): int
    {
        $recovered = 0;

        WebhookDelivery::query()
            ->whereIn('status', [
                WebhookDelivery::STATUS_RECEIVED,
                WebhookDelivery::STATUS_QUEUED,
                WebhookDelivery::STATUS_FAILED,
                WebhookDelivery::STATUS_BLOCKED,
            ])
            ->where('normalized_payload->webhook_action', 'process_document')
            ->where('updated_at', '<=', $this->staleQueuedCutoff())
            ->oldest('updated_at')
            ->oldest('id')
            ->limit($limit)
            ->get()
            ->each(function (WebhookDelivery $delivery) use (&$recovered): void {
                if ($this->recoverProcessWebhookDelivery($delivery)) {
                    $recovered++;
                }
            });

        return $recovered;
    }

    private function recoverProcessWebhookDelivery(WebhookDelivery $selected): bool
    {
        // Do not call Pipeline Start from this selection transaction. Its run
        // must commit before queue dispatch can fail, including on recovery.
        $delivery = DB::transaction(function () use ($selected): ?WebhookDelivery {
            $delivery = WebhookDelivery::query()->lockForUpdate()->find($selected->id);
            if ($delivery === null
                || ! in_array($delivery->status, [
                    WebhookDelivery::STATUS_RECEIVED,
                    WebhookDelivery::STATUS_QUEUED,
                    WebhookDelivery::STATUS_FAILED,
                    WebhookDelivery::STATUS_BLOCKED,
                ], true)
                || ($delivery->normalized_payload['webhook_action'] ?? null) !== 'process_document'
                || $delivery->updated_at->isAfter($this->staleQueuedCutoff())) {
                return null;
            }

            return $delivery;
        });
        if ($delivery === null) {
            return false;
        }

        $run = PipelineRun::query()
            ->where('webhook_delivery_id', $delivery->id)
            ->latest('id')
            ->first();

        try {
            if ($run === null) {
                $modified = $delivery->normalized_payload['paperless_modified'] ?? null;
                $result = $this->pipelineStarter->start(
                    triggerSource: 'webhook',
                    paperlessDocumentId: (int) $delivery->paperless_document_id,
                    paperlessModified: is_string($modified) ? $modified : null,
                    webhookDeliveryId: $delivery->id,
                );
                $run = $result->pipelineRun;
            }
        } catch (\Throwable $exception) {
            DB::transaction(function () use ($delivery, $exception): void {
                $current = WebhookDelivery::query()->lockForUpdate()->find($delivery->id);
                $current?->touch();
                PipelineLifecycleRecorder::event([
                    'webhook_delivery_id' => $delivery->id,
                    'event_type' => 'recovery.process_webhook_reconciliation_failed',
                    'paperless_document_id' => $delivery->paperless_document_id,
                    'level' => 'warning',
                    'message' => 'Process-document webhook recovery could not start a durable pipeline run.',
                    'payload' => ['error_type' => $exception::class],
                ]);
            });

            return false;
        }

        return DB::transaction(function () use ($delivery, $run): bool {
            $current = WebhookDelivery::query()->lockForUpdate()->find($delivery->id);
            if ($current === null) {
                return false;
            }
            $status = $run->status === PipelineRun::STATUS_BLOCKED
                ? WebhookDelivery::STATUS_BLOCKED
                : WebhookDelivery::STATUS_PROCESSED;
            $error = $status === WebhookDelivery::STATUS_BLOCKED ? $run->error_type : null;
            if ($current->status === $status && $current->error === $error) {
                return false;
            }

            $current->update([
                'status' => $status,
                'processed_at' => now(),
                'error' => $error,
            ]);
            PipelineLifecycleRecorder::event([
                'pipeline_run_id' => $run->id,
                'webhook_delivery_id' => $current->id,
                'event_type' => 'recovery.process_webhook_reconciled',
                'paperless_document_id' => $current->paperless_document_id,
                'level' => 'info',
                'message' => 'Process-document webhook delivery reconciled to its durable pipeline run.',
                'payload' => [
                    'pipeline_run_id' => $run->id,
                    'pipeline_run_status' => $run->status,
                    'delivery_status' => $status,
                ],
            ]);

            return true;
        });
    }

    /**
     * @return array<int, string>
     */
    private function retryableWebhookErrors(): array
    {
        return [
            'transient_network',
            'transient_provider',
            'transient_paperless',
            'rate_limited',
            'recoverable_processing',
            'bug_unexpected',
        ];
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
            Command::TYPE_SYNC_ENTITY_APPROVAL,
        ];
    }

    private function redispatchCommand(Command $command, string $eventType, string $message): bool
    {
        $expectedStatus = $command->status;
        $expectedUpdatedAt = $command->updated_at;

        return DB::transaction(function () use ($command, $expectedStatus, $expectedUpdatedAt, $eventType, $message): bool {
            $command = Command::query()->lockForUpdate()->find($command->id);
            if ($command === null
                || $command->status !== $expectedStatus
                || ! $command->updated_at?->equalTo($expectedUpdatedAt)
                || in_array($command->status, [
                    Command::STATUS_SUCCEEDED,
                    Command::STATUS_FAILED_PERMANENT,
                ], true)) {
                return false;
            }

            $job = match ($command->type) {
                Command::TYPE_EMBEDDING_INDEX_BUILD => RunPythonActorJob::embeddingIndexBuild($command->id),
                Command::TYPE_POLL_RECONCILIATION => RunPythonActorJob::pollReconciliation($command->id),
                Command::TYPE_REINDEX => RunPythonActorJob::reindex($command->id),
                Command::TYPE_REINDEX_OCR => RunPythonActorJob::reindexOcr($command->id),
                Command::TYPE_REVIEW_COMMIT => $this->reviewCommitJobOrFail($command),
                Command::TYPE_SYNC_ENTITY_APPROVAL => new ApplyEntityApprovalCommand($command->id),
                default => null,
            };

            if ($job === null) {
                return false;
            }

            $command->update([
                'status' => Command::STATUS_QUEUED,
                'error' => null,
            ]);
            dispatch($job);

            PipelineLifecycleRecorder::event([
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
        });
    }

    private function redispatchWebhookDelivery(WebhookDelivery $delivery, string $eventType, string $message): bool
    {
        $expectedStatus = $delivery->status;
        $expectedUpdatedAt = $delivery->updated_at;

        return DB::transaction(function () use ($delivery, $expectedStatus, $expectedUpdatedAt, $eventType, $message): bool {
            $delivery = WebhookDelivery::query()->lockForUpdate()->find($delivery->id);
            if ($delivery === null
                || $delivery->status !== $expectedStatus
                || ! $delivery->updated_at?->equalTo($expectedUpdatedAt)
                || in_array($delivery->status, [
                    WebhookDelivery::STATUS_DUPLICATE,
                    WebhookDelivery::STATUS_PROCESSED,
                    WebhookDelivery::STATUS_FAILED_PERMANENT,
                    WebhookDelivery::STATUS_DISMISSED,
                ], true)) {
                return false;
            }

            $delivery->update([
                'status' => WebhookDelivery::STATUS_QUEUED,
                'error' => null,
            ]);
            dispatch(RunPythonActorJob::webhookDelivery($delivery->id));

            PipelineLifecycleRecorder::event([
                'webhook_delivery_id' => $delivery->id,
                'event_type' => $eventType,
                'paperless_document_id' => $delivery->paperless_document_id,
                'level' => 'info',
                'message' => $message,
                'payload' => [
                    'actor_name' => 'handle_paperless_webhook',
                    'transport' => 'laravel_database_queue',
                    'webhook_action' => $delivery->normalized_payload['webhook_action'] ?? null,
                ],
            ]);

            return true;
        });
    }

    private function redispatchActorSource(ActorExecution $execution): bool
    {
        if ($execution->pipeline_run_id !== null) {
            $run = PipelineRun::query()->find($execution->pipeline_run_id);
            if ($run === null || $run->type !== 'document') {
                return false;
            }

            return $this->redispatchDocumentRun(
                $run,
                'recovery.actor_source_pipeline_redispatched',
                'Pipeline actor source redispatched from retrying actor execution.',
                'Document actor retry redispatched through Laravel recovery.',
            );
        }

        if ($execution->command_id !== null) {
            $command = Command::query()->find($execution->command_id);
            if ($command === null) {
                return false;
            }

            return $this->redispatchCommand(
                $command,
                'recovery.actor_source_command_redispatched',
                'Command actor source redispatched from retrying actor execution.',
            );
        }

        if ($execution->webhook_delivery_id !== null) {
            $delivery = WebhookDelivery::query()->find($execution->webhook_delivery_id);
            if ($delivery === null) {
                return false;
            }

            return $this->redispatchWebhookDelivery(
                $delivery,
                'recovery.actor_source_webhook_redispatched',
                'Webhook actor source redispatched from retrying actor execution.',
            );
        }

        return false;
    }

    private function reconcileActorExecutionToTerminalSource(ActorExecution $execution): bool
    {
        $terminal = false;
        if ($execution->pipeline_run_id !== null) {
            $sourceStatus = PipelineRun::query()->whereKey($execution->pipeline_run_id)->value('status');
            $terminal = in_array($sourceStatus, [
                PipelineRun::STATUS_SUCCEEDED,
                PipelineRun::STATUS_CANCELLED,
                PipelineRun::STATUS_FAILED_PERMANENT,
            ], true);
        } elseif ($execution->command_id !== null) {
            $sourceStatus = Command::query()->whereKey($execution->command_id)->value('status');
            $terminal = in_array($sourceStatus, [
                Command::STATUS_SUCCEEDED,
                Command::STATUS_FAILED_PERMANENT,
            ], true);
        } elseif ($execution->webhook_delivery_id !== null) {
            $sourceStatus = WebhookDelivery::query()->whereKey($execution->webhook_delivery_id)->value('status');
            $terminal = in_array($sourceStatus, [
                WebhookDelivery::STATUS_PROCESSED,
                WebhookDelivery::STATUS_DISMISSED,
                WebhookDelivery::STATUS_FAILED_PERMANENT,
            ], true);
        }

        if (! $terminal) {
            return false;
        }

        // A transport row with no Python final record must never be promoted
        // to success from source state. It is only suppressed as stale work.
        $execution->update([
            'status' => ActorExecution::STATUS_SKIPPED,
            'finished_at' => $execution->finished_at ?? now(),
            'next_retry_at' => null,
            'error_type' => 'superseded_by_terminal_source',
            'error_message' => 'Stale actor transport was suppressed because its source is already terminal.',
        ]);
        $this->recordActorRecoveryEvent(
            $execution,
            'recovery.actor_execution_reconciled_terminal_source',
            'Actor execution reconciled to its already-terminal durable source without redispatch.',
        );

        return true;
    }

    private function isActorProcessAlive(ActorExecution $execution): bool
    {
        $workerId = trim((string) $execution->worker_id);
        if ($workerId === '') {
            return false;
        }

        $parts = explode(':', $workerId);
        if (! in_array(count($parts), [2, 3], true)) {
            return $this->withinConservativeLivenessWindow($execution);
        }

        [$host, $pidValue] = $parts;
        $pid = filter_var($pidValue, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($pid === false || $host !== gethostname()) {
            return $this->withinConservativeLivenessWindow($execution);
        }
        if (! is_dir("/proc/{$pid}")) {
            return false;
        }

        if (count($parts) === 3) {
            $stat = @file_get_contents("/proc/{$pid}/stat");
            $separator = $stat === false ? false : strrpos($stat, ') ');
            if ($separator === false) {
                return false;
            }
            $fields = preg_split('/\s+/', trim(substr($stat, $separator + 2)));

            return isset($fields[19]) && hash_equals($parts[2], $fields[19]);
        }

        $commandLine = @file_get_contents("/proc/{$pid}/cmdline");

        return $commandLine !== false && str_contains($commandLine, 'app.actor_runner');
    }

    private function withinConservativeLivenessWindow(ActorExecution $execution): bool
    {
        $lastProgress = $execution->progress_updated_at ?? $execution->updated_at ?? $execution->started_at;

        return $lastProgress !== null && $lastProgress->isAfter(now()->subHour());
    }

    private function markActorExecutionPermanentFailure(ActorExecution $execution, string $errorType): void
    {
        $execution->update([
            'status' => ActorExecution::STATUS_FAILED_PERMANENT,
            'finished_at' => $execution->finished_at ?? now(),
            'next_retry_at' => null,
            'retry_reason' => $errorType,
            'retry_mode' => 'recovery',
            'error_type' => $errorType,
            'error_message' => 'Laravel recovery could not safely redispatch this actor execution.',
        ]);

        if ($execution->pipeline_run_id !== null) {
            PipelineRun::query()
                ->whereKey($execution->pipeline_run_id)
                ->where('lifecycle_version', $execution->source_version)
                ->where(function ($query) use ($execution): void {
                    $query->where('active_actor_token', $execution->execution_token)
                        ->orWhereNull('active_actor_token');
                })
                ->whereNotIn('status', [
                    PipelineRun::STATUS_SUCCEEDED,
                    PipelineRun::STATUS_CANCELLED,
                    PipelineRun::STATUS_FAILED_PERMANENT,
                    PipelineRun::STATUS_CANCEL_REQUESTED,
                ])
                ->update([
                    'status' => PipelineRun::STATUS_FAILED_PERMANENT,
                    'finished_at' => now(),
                    'next_retry_at' => null,
                    'error_type' => $errorType,
                    'error' => 'Actor recovery attempts exhausted or source was unavailable.',
                    'updated_at' => now(),
                ]);
        } elseif ($execution->command_id !== null) {
            Command::query()
                ->whereKey($execution->command_id)
                ->where('lifecycle_version', $execution->source_version)
                ->where(function ($query) use ($execution): void {
                    $query->where('active_actor_token', $execution->execution_token)
                        ->orWhereNull('active_actor_token');
                })
                ->whereNotIn('status', [Command::STATUS_SUCCEEDED, Command::STATUS_FAILED_PERMANENT])
                ->update([
                    'status' => Command::STATUS_FAILED_PERMANENT,
                    'finished_at' => now(),
                    'error' => $errorType,
                    'updated_at' => now(),
                ]);
        } elseif ($execution->webhook_delivery_id !== null) {
            WebhookDelivery::query()
                ->whereKey($execution->webhook_delivery_id)
                ->where('lifecycle_version', $execution->source_version)
                ->where(function ($query) use ($execution): void {
                    $query->where('active_actor_token', $execution->execution_token)
                        ->orWhereNull('active_actor_token');
                })
                ->whereNotIn('status', [
                    WebhookDelivery::STATUS_PROCESSED,
                    WebhookDelivery::STATUS_DISMISSED,
                    WebhookDelivery::STATUS_FAILED_PERMANENT,
                ])
                ->update([
                    'status' => WebhookDelivery::STATUS_FAILED_PERMANENT,
                    'error' => $errorType,
                    'updated_at' => now(),
                ]);
        }

        $this->recordActorRecoveryEvent(
            $execution,
            'recovery.actor_execution_failed_permanent',
            'Actor execution could not be recovered safely and was marked permanently failed.',
        );
    }

    private function recordActorRecoveryEvent(ActorExecution $execution, string $eventType, string $message): void
    {
        PipelineLifecycleRecorder::event([
            'pipeline_run_id' => $execution->pipeline_run_id,
            'webhook_delivery_id' => $execution->webhook_delivery_id,
            'command_id' => $execution->command_id,
            'event_type' => $eventType,
            'paperless_document_id' => $execution->paperless_document_id,
            'level' => 'warning',
            'message' => $message,
            'payload' => [
                'actor_execution_id' => $execution->id,
                'actor_name' => $execution->actor_name,
                'attempt' => $execution->attempt,
                'max_attempts' => $execution->max_attempts,
                'transport' => 'laravel_database_queue',
            ],
        ]);
    }

    private function redispatchDocumentRun(
        PipelineRun $run,
        string $eventType,
        string $eventMessage,
        string $progressMessage,
    ): bool {
        $expectedStatus = $run->status;
        $expectedUpdatedAt = $run->updated_at;

        return DB::transaction(function () use ($run, $expectedStatus, $expectedUpdatedAt, $eventType, $eventMessage, $progressMessage): bool {
            $run = PipelineRun::query()->lockForUpdate()->find($run->id);
            if ($run === null
                || $run->status !== $expectedStatus
                || ! $run->updated_at?->equalTo($expectedUpdatedAt)
                || in_array($run->status, [
                    PipelineRun::STATUS_SUCCEEDED,
                    PipelineRun::STATUS_PARTIALLY_FAILED,
                    PipelineRun::STATUS_FAILED_PERMANENT,
                    PipelineRun::STATUS_CANCEL_REQUESTED,
                    PipelineRun::STATUS_CANCELLED,
                ], true)) {
                return false;
            }

            $run->update([
                'status' => PipelineRun::STATUS_QUEUED,
                'progress_current_phase' => 'document_actor',
                'progress_message' => $progressMessage,
                'progress_updated_at' => now(),
                'error_type' => null,
                'error' => null,
            ]);
            dispatch(RunPythonActorJob::documentPipeline($run->id));

            PipelineLifecycleRecorder::event([
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

            return true;
        });
    }

    private function hasActivePipelineActor(PipelineRun $run): bool
    {
        return ActorExecution::query()
            ->where('pipeline_run_id', $run->id)
            ->whereIn('status', $this->activeActorStatuses())
            ->exists();
    }

    private function hasInFlightPipelineActor(PipelineRun $run): bool
    {
        return ActorExecution::query()
            ->where('pipeline_run_id', $run->id)
            ->whereIn('status', [ActorExecution::STATUS_QUEUED, ActorExecution::STATUS_RUNNING])
            ->exists();
    }

    private function hasActiveCommandActor(Command $command): bool
    {
        return ActorExecution::query()
            ->where('command_id', $command->id)
            ->whereIn('status', $this->activeActorStatuses())
            ->exists();
    }

    private function hasActiveWebhookActor(WebhookDelivery $delivery): bool
    {
        return ActorExecution::query()
            ->where('webhook_delivery_id', $delivery->id)
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

    private function staleRunningCutoff(): string
    {
        return now()->subMinutes($this->staleRunningMinutes())->toDateTimeString();
    }

    private function staleRunningMinutes(): int
    {
        return max(1, (int) config('archibot_workers.stale_running_minutes', 10));
    }

    private function staleQueuedCutoff(): string
    {
        return now()->subMinutes($this->staleQueuedMinutes())->toDateTimeString();
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
                if ($this->releaseEmbeddingBlockedWebhookDelivery($delivery)) {
                    $released++;
                }
            });

        return $released;
    }

    private function releaseEmbeddingBlockedWebhookDelivery(WebhookDelivery $selected): bool
    {
        return DB::transaction(function () use ($selected): bool {
            $delivery = WebhookDelivery::query()->lockForUpdate()->find($selected->id);
            if ($delivery === null
                || $delivery->status !== WebhookDelivery::STATUS_BLOCKED
                || $delivery->error !== DocumentPipelineStarter::BLOCKED_REASON_EMBEDDING_INDEX_NOT_READY
                || ($delivery->normalized_payload['webhook_action'] ?? null) === 'process_document') {
                return false;
            }

            $delivery->update([
                'status' => WebhookDelivery::STATUS_QUEUED,
                'error' => null,
                'processed_at' => null,
            ]);

            PipelineLifecycleRecorder::event([
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

            return true;
        });
    }

    private function reviewCommitJobOrFail(Command $command): ?RunPythonActorJob
    {
        $reviewSuggestionId = $command->payload['review_suggestion_id'] ?? null;
        if (! is_int($reviewSuggestionId) || $reviewSuggestionId <= 0) {
            $command->update([
                'status' => Command::STATUS_FAILED_PERMANENT,
                'error' => 'missing_review_suggestion_id',
                'finished_at' => now(),
            ]);

            PipelineLifecycleRecorder::event([
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
                if ($this->releaseEmbeddingBlockedRun($run)) {
                    $released++;
                }
            });

        return $released;
    }

    private function releaseEmbeddingBlockedRun(PipelineRun $selected): bool
    {
        return DB::transaction(function () use ($selected): bool {
            $run = PipelineRun::query()->lockForUpdate()->find($selected->id);
            if ($run === null
                || $run->status !== PipelineRun::STATUS_BLOCKED
                || $run->error_type !== DocumentPipelineStarter::BLOCKED_REASON_EMBEDDING_INDEX_NOT_READY) {
                return false;
            }

            $run->update([
                'status' => PipelineRun::STATUS_PENDING,
                'progress_current_phase' => 'queued',
                'progress_message' => 'Released by Laravel recovery because the embedding index is complete.',
                'progress_updated_at' => now(),
                'error_type' => null,
                'error' => null,
            ]);

            PipelineLifecycleRecorder::event([
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

            return true;
        });
    }
}
