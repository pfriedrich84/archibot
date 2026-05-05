<?php

namespace App\Services\Workers;

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
    public function run(WorkerJob $workerJob): WorkerJob
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

        return $workerJob;
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
            default => throw new RuntimeException("Unsupported worker job type [{$workerJob->type}]."),
        };
    }
}
