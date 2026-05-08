<?php

namespace App\Services\Workers;

use App\Models\EntityApproval;
use App\Models\ReviewSuggestion;
use App\Models\WorkerJob;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

class PythonWorkerCommand
{
    /**
     * Execute the JSON-file based Python CLI contract for a worker job.
     */
    public function run(WorkerJob $workerJob, ?WorkerResultIngestor $ingestor = null): WorkerJob
    {
        if ($workerJob->status !== WorkerJob::STATUS_QUEUED) {
            return $workerJob;
        }

        $this->waitForCompatibleJobs($workerJob);
        $workerJob->refresh();

        if ($workerJob->status !== WorkerJob::STATUS_QUEUED) {
            return $workerJob;
        }

        $workerJob->forceFill([
            'status' => WorkerJob::STATUS_RUNNING,
            'started_at' => now(),
            'error' => null,
        ])->save();

        $paths = $this->writeInput($workerJob);
        $command = $this->commandFor($workerJob, $paths['input'], $paths['output']);

        $process = new Process($command, base_path('..'), timeout: null);
        $process->start(function (string $type, string $buffer) use ($workerJob, $process): void {
            $this->captureOutput($workerJob, $type, $buffer);
            $this->signalIfCancelling($workerJob, $process);
        });

        while ($process->isRunning()) {
            $this->signalIfCancelling($workerJob, $process);
            usleep(250_000);
        }

        try {
            $process->wait();
        } catch (ProcessSignaledException) {
            // The final status below will persist the cooperative cancellation result.
        }

        $workerJob->refresh();
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
        ])->save();

        if ($workerJob->type === WorkerJob::TYPE_COMMIT_REVIEW) {
            $this->updateReviewCommitStatus($workerJob, $processSucceeded, is_array($result) ? $result : []);
        }

        if ($workerJob->type === WorkerJob::TYPE_SYNC_ENTITY_APPROVAL) {
            $this->updateEntityApprovalSyncStatus($workerJob, $processSucceeded, is_array($result) ? $result : []);
        }

        if ($processSucceeded && is_array($result)) {
            $ingestSummary = ($ingestor ?? app(WorkerResultIngestor::class))->ingest($workerJob);

            if ($ingestSummary !== []) {
                $workerJob->forceFill([
                    'result' => array_merge($workerJob->result ?? [], ['ingest' => $ingestSummary]),
                ])->save();
            }
        }

        return $workerJob;
    }

    private function waitForCompatibleJobs(WorkerJob $workerJob): void
    {
        while ($this->hasIncompatibleRunningJob($workerJob)) {
            $workerJob->refresh();
            if ($workerJob->status !== WorkerJob::STATUS_QUEUED) {
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
                ->exists();
        }

        if ($workerJob->isDocumentProcessingType()) {
            $blocking = WorkerJob::query()
                ->whereKeyNot($workerJob->id)
                ->whereIn('type', WorkerJob::blockingTypes())
                ->runningOrCancelling()
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
                    ->where('payload->paperless_document_id', $documentId)
                    ->exists();
            }
        }

        return false;
    }

    private function captureOutput(WorkerJob $workerJob, string $type, string $buffer): void
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
                    $this->persistLog($workerJob, $type, $payload['message'] ?? $payload['event'] ?? 'Progress update', $payload);
                }

                continue;
            }

            $this->persistLog($workerJob, $type, $line);
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

    private function signalIfCancelling(WorkerJob $workerJob, Process $process): void
    {
        $workerJob->refresh();

        if ($workerJob->status !== WorkerJob::STATUS_CANCELLING || ! $process->isRunning()) {
            return;
        }

        try {
            $process->signal(2);
        } catch (RuntimeException) {
            // Process may have exited between isRunning() and signal().
        }
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

        return match ($workerJob->type) {
            WorkerJob::TYPE_POLL => [$python, '-m', 'app.cli', 'poll', '--input', $input, '--output', $output],
            WorkerJob::TYPE_REINDEX => [$python, '-m', 'app.cli', 'reindex', '--input', $input, '--output', $output],
            WorkerJob::TYPE_REINDEX_OCR => [$python, '-m', 'app.cli', 'reindex-ocr', '--input', $input, '--output', $output],
            WorkerJob::TYPE_REINDEX_EMBED => [$python, '-m', 'app.cli', 'reindex-embed', '--input', $input, '--output', $output],
            WorkerJob::TYPE_PROCESS_DOCUMENT => [$python, '-m', 'app.cli', 'process-document', '--input', $input, '--output', $output],
            WorkerJob::TYPE_COMMIT_REVIEW => [$python, '-m', 'app.cli', 'commit-review', '--input', $input, '--output', $output],
            WorkerJob::TYPE_SYNC_ENTITY_APPROVAL => [$python, '-m', 'app.cli', 'sync-entity-approval', '--input', $input, '--output', $output],
            default => throw new RuntimeException("Unsupported worker job type [{$workerJob->type}]."),
        };
    }
}
