<?php

namespace App\Jobs;

use App\Models\WorkerJob;
use App\Services\Workers\PythonWorkerCommand;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunPythonWorkerJob implements ShouldQueue
{
    use Queueable;

    /**
     * Python worker jobs can legitimately run for hours while local LLM calls
     * classify large inbox batches. ArchiBot tracks liveness in worker_jobs via
     * its own lease/heartbeat, so the Laravel queue wrapper must not time out
     * and orphan the subprocess.
     */
    public int $timeout = 0;

    public int $tries = 1;

    public function __construct(public int $workerJobId) {}

    public function handle(PythonWorkerCommand $command): void
    {
        $workerJob = WorkerJob::query()->findOrFail($this->workerJobId);

        $command->run($workerJob);
    }
}
