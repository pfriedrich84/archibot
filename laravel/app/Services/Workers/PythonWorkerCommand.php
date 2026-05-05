<?php

namespace App\Services\Workers;

use App\Models\ReviewSuggestion;
use App\Models\WorkerJob;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class PythonWorkerCommand
{
    /**
     * Execute the JSON-file based Python CLI contract for a worker job.
     */
    public function run(WorkerJob $workerJob, ?WorkerResultIngestor $ingestor = null): WorkerJob
    {
        $workerJob->forceFill([
            'status' => WorkerJob::STATUS_RUNNING,
            'started_at' => now(),
            'error' => null,
        ])->save();

        $paths = $this->writeInput($workerJob);
        $command = $this->commandFor($workerJob, $paths['input'], $paths['output']);

        $process = new Process($command, base_path('..'), timeout: null);
        $process->run();

        $result = is_file($paths['output'])
            ? json_decode((string) file_get_contents($paths['output']), true)
            : null;

        $workerJob->forceFill([
            'status' => $process->isSuccessful() ? WorkerJob::STATUS_SUCCEEDED : WorkerJob::STATUS_FAILED,
            'input_path' => $paths['input'],
            'output_path' => $paths['output'],
            'result' => is_array($result) ? $result : null,
            'exit_code' => $process->getExitCode(),
            'error' => $process->isSuccessful() ? null : trim($process->getErrorOutput() ?: $process->getOutput()),
            'finished_at' => now(),
        ])->save();

        if ($workerJob->type === WorkerJob::TYPE_COMMIT_REVIEW) {
            $this->updateReviewCommitStatus($workerJob, $process->isSuccessful(), is_array($result) ? $result : []);
        }

        if ($process->isSuccessful() && is_array($result)) {
            $ingestSummary = ($ingestor ?? app(WorkerResultIngestor::class))->ingest($workerJob);

            if ($ingestSummary !== []) {
                $workerJob->forceFill([
                    'result' => array_merge($workerJob->result ?? [], ['ingest' => $ingestSummary]),
                ])->save();
            }
        }

        return $workerJob;
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
            WorkerJob::TYPE_PROCESS_DOCUMENT => [$python, '-m', 'app.cli', 'process-document', '--input', $input, '--output', $output],
            WorkerJob::TYPE_COMMIT_REVIEW => [$python, '-m', 'app.cli', 'commit-review', '--input', $input, '--output', $output],
            default => throw new RuntimeException("Unsupported worker job type [{$workerJob->type}]."),
        };
    }
}
