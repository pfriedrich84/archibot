<?php

namespace App\Services\Pipeline;

use App\Jobs\RunPythonActorJob;
use App\Models\EmbeddingIndexState;
use App\Models\PipelineEvent;
use App\Models\PipelineRun;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DocumentPipelineStarter
{
    public const BLOCKED_REASON_EMBEDDING_INDEX_NOT_READY = 'embedding_index_not_ready';

    public function start(
        string $triggerSource,
        int $paperlessDocumentId,
        ?string $paperlessModified = null,
        ?string $contentHash = null,
        bool $reprocessRequested = false,
        ?string $reprocessReason = null,
        ?string $reprocessMode = null,
        bool $forceNewRun = false,
        ?string $forceToken = null,
        ?int $requestedByUserId = null,
        ?int $webhookDeliveryId = null,
        ?int $commandId = null,
    ): PipelineStartResult {
        $dedupeKey = $forceNewRun
            ? $this->forceDedupeKey($paperlessDocumentId, $paperlessModified, $contentHash, $forceToken ?? (string) Str::uuid())
            : $this->dedupeKey($paperlessDocumentId, $paperlessModified, $contentHash);
        $gateOpen = $this->embeddingGateOpen();
        $gate = $this->gateAttributes($gateOpen, 'queued', 'Waiting for document actor.');

        $attributes = [
            'type' => 'document',
            'status' => $gate['status'],
            'scope' => 'single_document',
            'trigger_source' => $triggerSource,
            'paperless_document_id' => $paperlessDocumentId,
            'paperless_modified' => $paperlessModified,
            'content_hash' => $contentHash,
            'pipeline_dedupe_key' => $dedupeKey,
            'coalesced_sources' => [$triggerSource],
            'progress_current_phase' => $gate['progress_current_phase'],
            'progress_message' => $gate['progress_message'],
            'progress_updated_at' => now(),
            'reprocess_requested' => $reprocessRequested,
            'reprocess_reason' => $reprocessReason,
            'reprocess_mode' => $reprocessMode,
            'requested_by_user_id' => $requestedByUserId,
            'webhook_delivery_id' => $webhookDeliveryId,
            'command_id' => $commandId,
            'error_type' => $gate['error_type'],
            'error' => $gate['error'],
        ];

        /** @var array{run: PipelineRun, created: bool} $result */
        $result = DB::transaction(function () use ($attributes, $paperlessDocumentId, $dedupeKey, $triggerSource, $reprocessRequested, $reprocessReason, $reprocessMode, $requestedByUserId, $webhookDeliveryId, $commandId): array {
            $existing = PipelineRun::query()
                ->where('paperless_document_id', $paperlessDocumentId)
                ->where('pipeline_dedupe_key', $dedupeKey)
                ->first();

            if ($existing !== null) {
                return [
                    'run' => $this->coalesceExistingRun($existing, $triggerSource, $reprocessRequested, $reprocessReason, $reprocessMode, $requestedByUserId, $webhookDeliveryId, $commandId),
                    'created' => false,
                ];
            }

            try {
                return ['run' => PipelineRun::query()->create($attributes), 'created' => true];
            } catch (QueryException) {
                $run = PipelineRun::query()
                    ->where('paperless_document_id', $paperlessDocumentId)
                    ->where('pipeline_dedupe_key', $dedupeKey)
                    ->firstOrFail();

                return [
                    'run' => $this->coalesceExistingRun($run, $triggerSource, $reprocessRequested, $reprocessReason, $reprocessMode, $requestedByUserId, $webhookDeliveryId, $commandId),
                    'created' => false,
                ];
            }
        });

        $run = $result['run'];
        $created = $result['created'];
        $outcome = $this->outcome($created, $forceNewRun, $gateOpen);
        $blockedReason = $gateOpen ? null : self::BLOCKED_REASON_EMBEDDING_INDEX_NOT_READY;

        $this->recordStartEvent($run, $outcome, $triggerSource, $dedupeKey, $paperlessModified, $contentHash, $forceNewRun, $blockedReason);

        if ($created && $gateOpen) {
            dispatch(RunPythonActorJob::documentPipeline($run->id));
            $run->forceFill([
                'status' => PipelineRun::STATUS_QUEUED,
                'progress_current_phase' => 'document_actor',
                'progress_message' => 'Document actor queued through Laravel actor transport.',
                'progress_updated_at' => now(),
            ])->save();
            $this->recordActorQueuedEvent($run);
        }

        return new PipelineStartResult($run->refresh(), $outcome, $dedupeKey, $blockedReason, $created);
    }

    /**
     * @return array{status: string, progress_current_phase: string, progress_message: string, error_type: ?string, error: ?string}
     */
    public function gateAttributes(bool $gateOpen, string $openPhase, string $openMessage): array
    {
        if ($gateOpen) {
            return [
                'status' => PipelineRun::STATUS_PENDING,
                'progress_current_phase' => $openPhase,
                'progress_message' => $openMessage,
                'error_type' => null,
                'error' => null,
            ];
        }

        return [
            'status' => PipelineRun::STATUS_BLOCKED,
            'progress_current_phase' => 'blocked',
            'progress_message' => 'Waiting for embedding index to complete.',
            'error_type' => self::BLOCKED_REASON_EMBEDDING_INDEX_NOT_READY,
            'error' => 'Waiting for embedding index to complete.',
        ];
    }

    public function embeddingGateOpen(): bool
    {
        return EmbeddingIndexState::query()->latest()->value('status') === EmbeddingIndexState::STATUS_COMPLETE;
    }

    public function dedupeKey(int $paperlessDocumentId, ?string $paperlessModified, ?string $contentHash, string $pipelineVersion = 'v1'): string
    {
        return hash('sha256', implode(':', [
            (string) $paperlessDocumentId,
            $paperlessModified ?: 'unknown_modified',
            $contentHash ?: 'unknown_content',
            $pipelineVersion,
        ]));
    }

    public function forceDedupeKey(int $paperlessDocumentId, ?string $paperlessModified, ?string $contentHash, string $forceToken, string $pipelineVersion = 'v1'): string
    {
        return hash('sha256', implode(':', [
            'force',
            (string) $paperlessDocumentId,
            $paperlessModified ?: 'unknown_modified',
            $contentHash ?: 'unknown_content',
            $forceToken,
            $pipelineVersion,
        ]));
    }

    private function coalesceExistingRun(
        PipelineRun $run,
        string $triggerSource,
        bool $reprocessRequested,
        ?string $reprocessReason,
        ?string $reprocessMode,
        ?int $requestedByUserId,
        ?int $webhookDeliveryId,
        ?int $commandId,
    ): PipelineRun {
        $sources = $run->coalesced_sources ?? [];
        if (! in_array($triggerSource, $sources, true)) {
            $sources[] = $triggerSource;
        }

        $run->forceFill([
            'coalesced_sources' => array_values($sources),
            'reprocess_requested' => $run->reprocess_requested || $reprocessRequested,
            'reprocess_reason' => $reprocessReason ?? $run->reprocess_reason,
            'reprocess_mode' => $reprocessMode ?? $run->reprocess_mode,
            'requested_by_user_id' => $requestedByUserId ?? $run->requested_by_user_id,
            'webhook_delivery_id' => $run->webhook_delivery_id ?? $webhookDeliveryId,
            'command_id' => $run->command_id ?? $commandId,
        ])->save();

        return $run;
    }

    private function outcome(bool $created, bool $forceNewRun, bool $gateOpen): string
    {
        if (! $created) {
            return 'coalesced';
        }
        if (! $gateOpen) {
            return 'blocked';
        }
        if ($forceNewRun) {
            return 'force_created';
        }

        return 'created';
    }

    private function recordActorQueuedEvent(PipelineRun $run): void
    {
        PipelineEvent::query()->create([
            'pipeline_run_id' => $run->id,
            'webhook_delivery_id' => $run->webhook_delivery_id,
            'command_id' => $run->command_id,
            'event_type' => 'pipeline.document_actor_queued',
            'paperless_document_id' => $run->paperless_document_id,
            'level' => 'info',
            'message' => 'Document actor queued through Laravel actor transport.',
            'payload' => [
                'actor_name' => 'handle_document_pipeline',
                'transport' => 'laravel_database_queue',
            ],
        ]);
    }

    private function recordStartEvent(
        PipelineRun $run,
        string $outcome,
        string $triggerSource,
        string $dedupeKey,
        ?string $paperlessModified,
        ?string $contentHash,
        bool $forceNewRun,
        ?string $blockedReason,
    ): void {
        $eventType = match ($outcome) {
            'coalesced' => 'pipeline.start.coalesced',
            'blocked' => 'pipeline.blocked.embedding_index_not_ready',
            default => 'pipeline.start.pending',
        };
        $message = match ($outcome) {
            'coalesced' => 'Document pipeline start coalesced with an existing run.',
            'blocked' => 'Document pipeline start blocked because the embedding index is not ready.',
            'force_created' => 'Manual force reprocess accepted as a new document pipeline run.',
            default => 'Document pipeline start accepted by the shared trigger gate.',
        };

        PipelineEvent::query()->create([
            'pipeline_run_id' => $run->id,
            'webhook_delivery_id' => $run->webhook_delivery_id,
            'command_id' => $run->command_id,
            'event_type' => $eventType,
            'paperless_document_id' => $run->paperless_document_id,
            'level' => $blockedReason === null ? 'info' : 'warning',
            'message' => $message,
            'payload' => [
                'trigger_source' => $triggerSource,
                'pipeline_dedupe_key' => $dedupeKey,
                'paperless_modified' => $paperlessModified,
                'content_hash_present' => $contentHash !== null,
                'force_new_run' => $forceNewRun,
                'outcome' => $outcome,
                'blocked_reason' => $blockedReason,
            ],
        ]);
    }
}
