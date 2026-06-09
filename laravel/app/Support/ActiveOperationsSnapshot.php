<?php

namespace App\Support;

use App\Models\ActorExecution;
use App\Models\Command;
use App\Models\PipelineRun;

class ActiveOperationsSnapshot
{
    /**
     * @return array{
     *     summary: array{total: int, queued: int, running: int, retrying: int, blocked: int},
     *     items: array<int, array<string, mixed>>,
     *     operations_log_url: string
     * }
     */
    public function make(int $limit = 8): array
    {
        $actorProgress = ActorExecution::query()
            ->whereIn('status', [
                ActorExecution::STATUS_QUEUED,
                ActorExecution::STATUS_RUNNING,
                ActorExecution::STATUS_RETRYING,
            ])
            ->whereNull('pipeline_run_id')
            ->latest('updated_at')
            ->get()
            ->keyBy('actor_name');

        $commandItems = Command::query()
            ->whereIn('status', Command::activeStatuses())
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (Command $command): array => $this->commandItem(
                $command,
                $actorProgress->get($this->commandActorName($command->type)),
            ));

        $pipelineItems = PipelineRun::query()
            ->whereIn('status', [
                PipelineRun::STATUS_PENDING,
                PipelineRun::STATUS_BLOCKED,
                PipelineRun::STATUS_QUEUED,
                PipelineRun::STATUS_RUNNING,
                PipelineRun::STATUS_RETRYING,
                PipelineRun::STATUS_CANCEL_REQUESTED,
            ])
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (PipelineRun $run): array => $this->pipelineRunItem($run));

        $items = $commandItems
            ->concat($pipelineItems)
            ->sortByDesc('updated_at')
            ->take($limit)
            ->values();

        return [
            'summary' => [
                'total' => $items->count(),
                'queued' => $items
                    ->whereIn('status', [Command::STATUS_PENDING, Command::STATUS_QUEUED])
                    ->count(),
                'running' => $items->where('status', Command::STATUS_RUNNING)->count(),
                'retrying' => $items->where('status', PipelineRun::STATUS_RETRYING)->count(),
                'blocked' => $items->where('status', PipelineRun::STATUS_BLOCKED)->count(),
            ],
            'items' => $items->all(),
            'operations_log_url' => route('operations-log.index'),
        ];
    }

    /** @return array<string, mixed> */
    private function commandItem(Command $command, ?ActorExecution $execution = null): array
    {
        return [
            'key' => "command-{$command->id}",
            'kind' => 'command',
            'id' => $command->id,
            'label' => $this->commandLabel($command),
            'status' => $command->status,
            'detail' => $this->commandDetail($command),
            'progress_total' => $execution?->progress_total ?? 0,
            'progress_done' => $execution?->progress_done ?? 0,
            'progress_failed' => $execution?->progress_failed ?? 0,
            'progress_skipped' => $execution?->progress_skipped ?? 0,
            'progress_message' => $execution?->progress_message ?? $command->error,
            'created_at' => $command->created_at?->toISOString(),
            'started_at' => $command->started_at?->toISOString(),
            'updated_at' => $command->updated_at?->toISOString(),
            'href' => route('operations-log.index'),
        ];
    }

    /** @return array<string, mixed> */
    private function pipelineRunItem(PipelineRun $run): array
    {
        return [
            'key' => "pipeline-run-{$run->id}",
            'kind' => 'pipeline_run',
            'id' => $run->id,
            'label' => $this->pipelineRunLabel($run),
            'status' => $run->status,
            'detail' => $this->pipelineRunDetail($run),
            'progress_total' => $run->progress_total,
            'progress_done' => $run->progress_done,
            'progress_failed' => $run->progress_failed,
            'progress_skipped' => $run->progress_skipped,
            'progress_message' => $run->progress_message,
            'created_at' => $run->created_at?->toISOString(),
            'started_at' => $run->started_at?->toISOString(),
            'updated_at' => $run->updated_at?->toISOString(),
            'href' => route('pipeline-runs.show', $run),
        ];
    }

    private function commandActorName(string $type): ?string
    {
        return match ($type) {
            Command::TYPE_POLL_RECONCILIATION => 'poll_reconciliation',
            Command::TYPE_REINDEX => 'reindex',
            Command::TYPE_REINDEX_OCR => 'reindex_ocr',
            Command::TYPE_EMBEDDING_INDEX_BUILD => 'build_embedding_index',
            Command::TYPE_REVIEW_COMMIT => 'review_commit',
            Command::TYPE_SYNC_ENTITY_APPROVAL => 'sync_entity_approval',
            default => null,
        };
    }

    private function commandLabel(Command $command): string
    {
        return match ($command->type) {
            Command::TYPE_POLL_RECONCILIATION => 'Poll reconciliation',
            Command::TYPE_REINDEX => 'Full reindex',
            Command::TYPE_REINDEX_OCR => 'OCR reindex',
            Command::TYPE_EMBEDDING_INDEX_BUILD => 'Embedding index build',
            Command::TYPE_REVIEW_COMMIT => 'Review commit',
            Command::TYPE_SYNC_ENTITY_APPROVAL => 'Entity approval sync',
            default => str($command->type)->replace('_', ' ')->title()->toString(),
        };
    }

    private function commandDetail(Command $command): string
    {
        $payload = $command->payload ?? [];
        $details = ["Command #{$command->id}"];

        if (($payload['force'] ?? false) === true) {
            $details[] = 'force';
        }

        if (isset($payload['limit'])) {
            $details[] = "limit {$payload['limit']}";
        }

        return implode(' · ', $details);
    }

    private function pipelineRunLabel(PipelineRun $run): string
    {
        if ($run->type === 'document' && $run->paperless_document_id !== null) {
            return "Document #{$run->paperless_document_id} processing";
        }

        return match ($run->type) {
            'document' => 'Document processing',
            'reconciliation' => 'Poll reconciliation',
            'reindex' => 'Full reindex',
            'ocr_reindex' => 'OCR reindex',
            'embedding_index' => 'Embedding index build',
            default => str($run->type)->replace('_', ' ')->title()->toString(),
        };
    }

    private function pipelineRunDetail(PipelineRun $run): string
    {
        $details = ["Pipeline Run #{$run->id}"];

        if ($run->progress_current_phase) {
            $details[] = str($run->progress_current_phase)
                ->replace('_', ' ')
                ->title()
                ->toString();
        }

        if ($run->reprocess_requested) {
            $details[] = 'reprocess';
        }

        return implode(' · ', $details);
    }
}
