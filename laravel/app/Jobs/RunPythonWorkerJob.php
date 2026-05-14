<?php

namespace App\Jobs;

use App\Models\WorkerJob;
use App\Services\Workers\PythonWorkerCommand;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunPythonWorkerJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $workerJobId) {}

    public function handle(PythonWorkerCommand $command): void
    {
        $workerJob = WorkerJob::query()->findOrFail($this->workerJobId);

        $command->run($workerJob);
    }
}
