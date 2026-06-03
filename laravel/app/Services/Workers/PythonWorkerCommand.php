<?php

namespace App\Services\Workers;

use App\Models\Command;
use App\Models\EmbeddingIndexState;
use App\Models\EntityApproval;
use App\Models\ReviewSuggestion;
use App\Models\WorkerJob;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;
use Throwable;

class PythonWorkerCommand
{
    /**
     * Execute the JSON-file based Python CLI contract for a worker job.
     */
    public function run(WorkerJob $workerJob, ?WorkerResultIngestor $ingestor = null): WorkerJob
    {
        $workerId = (string) Str::uuid();

        if (! $workerJob->acquireLease($workerId)) {
            return $workerJob->refresh();
        }

        $this->waitForCompatibleJobs($workerJob, $workerId);
        $workerJob->refresh();

        if ($workerJob->status !== WorkerJob::STATUS_RUNNING || $workerJob->worker_id !== $workerId) {
            return $workerJob;
        }

        $workerJob->forceFill([
            'error' => null,
            'progress' => $workerJob->progress ?: [
                'phase' => 'starting',
                'done' => 0,
                'total' => 0,
                'message' => 'Worker started',
            ],
        ])->save();
        $workerJob->heartbeatLease($workerId);

        $paths = $this->writeInput($workerJob);
        $command = $this->commandFor($workerJob, $paths['input'], $paths['output']);

        $process = new Process($command, base_path('..'), timeout: null);
        $process->start(function (string $type, string $buffer) use ($workerJob, $process, $workerId): void {
            $this->captureOutput($workerJob, $type, $buffer, $workerId);
            $this->signalIfCancelling($workerJob, $process);
        });

        $nextHeartbeatAt = now()->addSeconds($this->heartbeatSeconds());
        while ($process->isRunning()) {
            $this->signalIfCancelling($workerJob, $process);
            if (now()->greaterThanOrEqualTo($nextHeartbeatAt)) {
                $workerJob->heartbeatLease($workerId);
                $nextHeartbeatAt = now()->addSeconds($this->heartbeatSeconds());
            }
            usleep(250_000);
        }

        try {
            $process->wait();
        } catch (ProcessSignaledException) {
            // The final status below will persist the cooperative cancellation result.
        }

        $workerJob->refresh();
        if ($workerJob->worker_id !== $workerId) {
            return $workerJob;
        }

        $result = is_file($paths['output'])
            ? json_decode((string) file_get_contents($paths['output']), true)
            : null;
        $processSucceeded = $process->isSuccessful();
        $status = $this->terminalStatus($workerJob, $processSucceeded, is_array($result) ? $result : null);

        $workerJob->forceFill([
            'status' => $status,
            'input_path' => $paths['input'],
            'output_path' => $paths['output'],
            'result' => is_array($result) ? $result : null,
            'progress' => $this->finalProgress($workerJob, is_array($result) ? $result : null),
            'exit_code' => $process->getExitCode(),
            'error' => in_array($status, [WorkerJob::STATUS_SUCCEEDED, WorkerJob::STATUS_PARTIALLY_FAILED], true)
                ? null
                : trim($process->getErrorOutput() ?: $process->getOutput() ?: ($status === WorkerJob::STATUS_CANCELLED ? 'Cancelled.' : '')),
            'finished_at' => now(),
            'lease_expires_at' => null,
        ])->save();

        if ($workerJob->type === WorkerJob::TYPE_COMMIT_REVIEW) {
            $this->updateReviewCommitStatus($workerJob, $processSucceeded, is_array($result) ? $result : []);
        }

        if ($workerJob->type === WorkerJob::TYPE_SYNC_ENTITY_APPROVAL) {
            $this->updateEntityApprovalSyncStatus($workerJob, $processSucceeded, is_array($result) ? $result : []);
        }

        if (in_array($workerJob->type, [WorkerJob::TYPE_REINDEX, WorkerJob::TYPE_REINDEX_EMBED], true)) {
            $this->updateEmbeddingIndexState($workerJob, is_array($result) ? $result : []);
        }

        if (is_array($result)) {
            $ingestSummary = ($ingestor ?? app(WorkerResultIngestor::class))->ingest($workerJob);

            if ($ingestSummary !== []) {
                $workerJob->forceFill([
                    'result' => array_merge($workerJob->result ?? [], ['ingest' => $ingestSummary]),
                ])->save();
            }
        }

        return $workerJob;
    }

    private function waitForCompatibleJobs(WorkerJob $workerJob, string $workerId): void
    {
        while ($this->hasIncompatibleRunningJob($workerJob)) {
            app(StaleWorkerJobCanceller::class)->cancel();
            $workerJob->heartbeatLease($workerId);

            $workerJob->refresh();
            if ($workerJob->status !== WorkerJob::STATUS_RUNNING || $workerJob->worker_id !== $workerId) {
                return;
            }
            sleep(1);
        }
    }

    private function hasIncompatibleRunningJob(WorkerJob $workerJob): bool
    {
        if ($workerJob->isBlockingType()) {
            return WorkerJob::query()
                ->whereKeyNot($workerJob->id)
                ->runningOrCancelling()
                ->where(fn ($query) => $query
                    ->whereNull('lease_expires_at')
                    ->orWhere('lease_expires_at', '>', now()))
                ->exists();
        }

        if ($workerJob->isDocumentProcessingType()) {
            $blocking = WorkerJob::query()
                ->whereKeyNot($workerJob->id)
                ->whereIn('type', WorkerJob::blockingTypes())
                ->runningOrCancelling()
                ->where(fn ($query) => $query
                    ->whereNull('lease_expires_at')
                    ->orWhere('lease_expires_at', '>', now()))
                ->exists();

            if ($blocking) {
                return true;
            }

            $documentId = $workerJob->paperlessDocumentId();
            if ($documentId !== null) {
                return WorkerJob::query()
                    ->whereKeyNot($workerJob->id)
                    ->where('type', WorkerJob::TYPE_PROCESS_DOCUMENT)
                    ->runningOrCancelling()
                    ->where(fn ($query) => $query
                        ->whereNull('lease_expires_at')
                        ->orWhere('lease_expires_at', '>', now()))
                    ->where('payload->paperless_document_id', $documentId)
                    ->exists();
            }
        }

        return false;
    }

    private function captureOutput(WorkerJob $workerJob, string $type, string $buffer, string $workerId): void
    {
        foreach (preg_split('/\R/', $buffer) ?: [] as $line) {
            $line = $this->sanitizeOutputLine(trim($line));

            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, 'PROGRESS ')) {
                $payload = json_decode(substr($line, 9), true);

                if (is_array($payload)) {
                    $workerJob->forceFill(['progress' => $payload])->save();
                    $workerJob->heartbeatLease($workerId);
                    $this->persistLog($workerJob, $type, $payload['message'] ?? $payload['event'] ?? 'Progress update', $payload);
                }

                continue;
            }

            $this->persistLog($workerJob, $type, $line);
            $workerJob->heartbeatLease($workerId);
        }
    }

    private function sanitizeOutputLine(string $line): string
    {
        $line = preg_replace('/\e\[[0-?]*[ -\/]*[@-~]/', '', $line) ?? $line;

        return trim($line);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function persistLog(WorkerJob $workerJob, string $stream, string $message, array $context = []): void
    {
        $workerJob->logs()->create([
            'stream' => $stream === Process::ERR ? 'stderr' : 'stdout',
            'level' => (string) ($context['level'] ?? ($stream === Process::ERR ? 'error' : 'info')),
            'event' => is_scalar($context['event'] ?? null) ? (string) $context['event'] : null,
            'paperless_document_id' => is_numeric($context['document_id'] ?? null) ? (int) $context['document_id'] : null,
            'phase' => is_scalar($context['phase'] ?? null) ? (string) $context['phase'] : null,
            'message' => $message,
            'context' => $context,
        ]);
    }

    private function heartbeatSeconds(): int
    {
        return max(1, (int) config('archibot_workers.heartbeat_seconds', 15));
    }

    private function signalIfCancelling(WorkerJob $workerJob, Process $process): void
    {
        $workerJob->refresh();

        if ($workerJob->status !== WorkerJob::STATUS_CANCELLING || ! $process->isRunning()) {
            return;
        }

        $progress = is_array($workerJob->progress) ? $workerJob->progress : [];
        $cancellation = is_array($progress['cancellation'] ?? null) ? $progress['cancellation'] : [];

        if (empty($cancellation['cancel_signal_sent_at'])) {
            try {
                $process->signal(2);
                $cancellation['cancel_signal_sent_at'] = now()->toISOString();
                $cancellation['signal'] = 'SIGINT';
                $progress['cancellation'] = $cancellation;
                $workerJob->forceFill(['progress' => $progress])->save();
                $workerJob->appendSystemLog('worker_job.cancel_signal_sent', 'Sent SIGINT to cancelling worker process.', 'warning');
            } catch (RuntimeException) {
                // Process may have exited between isRunning() and signal().
            }
        }

        if (! empty($cancellation['forced_stop_at'])) {
            return;
        }

        $killAfterAt = $this->cancellationKillAfterAt($workerJob, $cancellation);
        if ($killAfterAt === null || now()->lessThan($killAfterAt)) {
            return;
        }

        $cancellation['forced_stop_at'] = now()->toISOString();
        $progress['cancellation'] = $cancellation;
        $workerJob->forceFill(['progress' => $progress])->save();
        $workerJob->appendSystemLog('worker_job.cancel_force_stop', 'Cancellation grace period expired; stopping worker process.', 'error', [
            'kill_after_at' => $killAfterAt->toISOString(),
        ]);

        try {
            $process->stop(5, 15);
        } catch (RuntimeException) {
            // Process may already have exited.
        }
    }

    /** @param array<string, mixed> $cancellation */
    private function cancellationKillAfterAt(WorkerJob $workerJob, array $cancellation): ?Carbon
    {
        if (is_string($cancellation['kill_after_at'] ?? null)) {
            try {
                return Carbon::parse($cancellation['kill_after_at']);
            } catch (Throwable) {
                // Fall back to cancellation_requested_at plus configured grace period.
            }
        }

        if ($workerJob->cancellation_requested_at === null) {
            return null;
        }

        return $workerJob->cancellation_requested_at->copy()->addSeconds(max(1, (int) config('archibot_workers.cancel_grace_seconds', 30)));
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function terminalStatus(WorkerJob $workerJob, bool $processSucceeded, ?array $result): string
    {
        if ($workerJob->status === WorkerJob::STATUS_CANCELLING || data_get($result, 'cancelled') === true) {
            return WorkerJob::STATUS_CANCELLED;
        }

        $failed = data_get($result, 'progress.failed') ?? data_get($result, 'failed');
        if ($processSucceeded && is_numeric($failed) && (int) $failed > 0) {
            return WorkerJob::STATUS_PARTIALLY_FAILED;
        }

        return $processSucceeded ? WorkerJob::STATUS_SUCCEEDED : WorkerJob::STATUS_FAILED;
    }

    /**
     * @param  array<string, mixed>|null  $result
     * @return array<string, mixed>|null
     */
    private function finalProgress(WorkerJob $workerJob, ?array $result): ?array
    {
        $progress = $workerJob->progress;

        if (is_array($result) && is_array(Arr::get($result, 'progress'))) {
            $progress = Arr::get($result, 'progress');
        }

        return is_array($progress) ? $progress : null;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function updateReviewCommitStatus(WorkerJob $workerJob, bool $processSucceeded, array $result): void
    {
        $reviewSuggestionId = data_get($workerJob->payload, 'review_suggestion_id');

        if (! is_numeric($reviewSuggestionId)) {
            return;
        }

        $committed = $processSucceeded && data_get($result, 'result.committed') === true;

        ReviewSuggestion::query()
            ->whereKey((int) $reviewSuggestionId)
            ->update([
                'commit_worker_job_id' => $workerJob->id,
                'commit_status' => $committed
                    ? ReviewSuggestion::COMMIT_STATUS_COMMITTED
                    : ReviewSuggestion::COMMIT_STATUS_FAILED,
            ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function updateEntityApprovalSyncStatus(WorkerJob $workerJob, bool $processSucceeded, array $result): void
    {
        $entityApprovalId = data_get($workerJob->payload, 'entity_approval_id');

        if (! is_numeric($entityApprovalId)) {
            return;
        }

        $synced = $processSucceeded && data_get($result, 'result.synced') === true;

        EntityApproval::query()
            ->whereKey((int) $entityApprovalId)
            ->update([
                'sync_worker_job_id' => $workerJob->id,
                'sync_status' => $synced
                    ? EntityApproval::SYNC_STATUS_SYNCED
                    : EntityApproval::SYNC_STATUS_FAILED,
            ]);
    }

    /** @param array<string, mixed> $result */
    private function updateEmbeddingIndexState(WorkerJob $workerJob, array $result): void
    {
        $indexed = $this->integerResultValue($result, ['indexed', 'result.indexed', 'progress.done', 'result.progress.done']);
        $failed = $this->integerResultValue($result, ['failed', 'result.failed', 'progress.failed', 'result.progress.failed']) ?? 0;
        $total = $this->integerResultValue($result, ['progress.total', 'result.progress.total']) ?? (($indexed ?? 0) + $failed);
        $succeeded = in_array($workerJob->status, [WorkerJob::STATUS_SUCCEEDED, WorkerJob::STATUS_PARTIALLY_FAILED], true);

        $state = EmbeddingIndexState::query()->latest()->first() ?? new EmbeddingIndexState;
        $state->forceFill([
            'status' => $succeeded && $failed === 0 ? EmbeddingIndexState::STATUS_COMPLETE : EmbeddingIndexState::STATUS_FAILED,
            'content_scope' => 'reviewed_documents',
            'started_at' => $workerJob->started_at,
            'completed_at' => $workerJob->finished_at,
            'document_count' => max(0, $total),
            'embedded_count' => max(0, $indexed ?? 0),
            'failed_count' => max(0, $failed),
            'error' => $succeeded && $failed === 0 ? null : ($workerJob->error ?: 'Embedding reindex did not complete cleanly.'),
        ])->save();

        $commandId = data_get($workerJob->payload, 'command_id');
        if (is_numeric($commandId)) {
            Command::query()
                ->whereKey((int) $commandId)
                ->update([
                    'status' => $succeeded && $failed === 0
                        ? Command::STATUS_SUCCEEDED
                        : Command::STATUS_FAILED,
                    'started_at' => $workerJob->started_at,
                    'finished_at' => $workerJob->finished_at,
                    'error' => $succeeded && $failed === 0
                        ? null
                        : ($workerJob->error ?: 'Embedding fallback worker job did not complete cleanly.'),
                ]);
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<int, string>  $paths
     */
    private function integerResultValue(array $result, array $paths): ?int
    {
        foreach ($paths as $path) {
            $value = data_get($result, $path);
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * @return array{input: string, output: string}
     */
    private function writeInput(WorkerJob $workerJob): array
    {
        $directory = 'worker-jobs/'.$workerJob->id.'-'.Str::uuid();
        $input = storage_path('app/'.$directory.'/input.json');
        $output = storage_path('app/'.$directory.'/output.json');

        File::ensureDirectoryExists(dirname($input));
        file_put_contents($input, json_encode([
            'id' => $workerJob->id,
            'type' => $workerJob->type,
            'payload' => $workerJob->payload ?? [],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return ['input' => $input, 'output' => $output];
    }

    /**
     * @return array<int, string>
     */
    private function commandFor(WorkerJob $workerJob, string $input, string $output): array
    {
        $python = config('archibot_workers.python_binary', 'python');

        $command = match ($workerJob->type) {
            WorkerJob::TYPE_POLL => [$python, '-m', 'app.cli', 'poll', '--input', $input, '--output', $output],
            WorkerJob::TYPE_REINDEX => [$python, '-m', 'app.cli', 'reindex', '--input', $input, '--output', $output],
            WorkerJob::TYPE_REINDEX_OCR => [$python, '-m', 'app.cli', 'reindex-ocr', '--input', $input, '--output', $output],
            WorkerJob::TYPE_REINDEX_EMBED => [$python, '-m', 'app.cli', 'reindex-embed', '--input', $input, '--output', $output],
            WorkerJob::TYPE_PROCESS_DOCUMENT => [$python, '-m', 'app.cli', 'process-document', '--input', $input, '--output', $output],
            WorkerJob::TYPE_COMMIT_REVIEW => [$python, '-m', 'app.cli', 'commit-review', '--input', $input, '--output', $output],
            WorkerJob::TYPE_SYNC_ENTITY_APPROVAL => [$python, '-m', 'app.cli', 'sync-entity-approval', '--input', $input, '--output', $output],
            default => throw new RuntimeException("Unsupported worker job type [{$workerJob->type}]."),
        };

        if ($this->shouldForce($workerJob)) {
            $command[] = '--force';
        }

        return $command;
    }

    private function shouldForce(WorkerJob $workerJob): bool
    {
        return (bool) data_get($workerJob->payload, 'force', false)
            && in_array($workerJob->type, [
                WorkerJob::TYPE_POLL,
                WorkerJob::TYPE_PROCESS_DOCUMENT,
                WorkerJob::TYPE_REINDEX_OCR,
            ], true);
    }
}
