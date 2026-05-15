<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\WorkerJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Throwable;

class HealthCheckController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->databaseCheck(),
            'worker_recovery' => $this->workerRecoveryCheck(),
            'stale_queued_worker_jobs' => $this->staleQueuedWorkerJobsCheck(),
            'queue' => $this->queueCheck(),
            'paperless_config' => $this->paperlessConfigCheck(),
            'python_runtime' => $this->pythonRuntimeCheck(),
        ];

        $status = 'ok';

        if (in_array('error', $checks, true)) {
            $status = 'error';
        } elseif (count(array_intersect($checks, ['stale', 'unknown', 'missing', 'warning'])) > 0) {
            $status = 'degraded';
        }

        return response()->json([
            'status' => $status,
            'checks' => $checks,
        ], $status === 'error' ? 503 : 200);
    }

    private function databaseCheck(): string
    {
        try {
            DB::select('select 1');

            return 'ok';
        } catch (Throwable) {
            return 'error';
        }
    }

    private function workerRecoveryCheck(): string
    {
        $lastSuccessfulAt = AppSetting::getValue('worker_jobs.recovery.last_successful_at');

        if (! filled($lastSuccessfulAt)) {
            return 'unknown';
        }

        try {
            $lastSuccess = Carbon::parse($lastSuccessfulAt);
        } catch (Throwable) {
            return 'unknown';
        }

        $intervalSeconds = max(1, (int) env('ARCHIBOT_WORKER_RECOVERY_INTERVAL_SECONDS', 30));
        $staleAfterSeconds = max(120, $intervalSeconds * 4);

        return $lastSuccess->greaterThanOrEqualTo(now()->subSeconds($staleAfterSeconds)) ? 'ok' : 'stale';
    }

    private function staleQueuedWorkerJobsCheck(): string
    {
        $pendingRedispatchCutoff = now()->subSeconds((int) config('archibot_workers.pending_redispatch_seconds', 900));

        return WorkerJob::query()
            ->where('status', WorkerJob::STATUS_QUEUED)
            ->where(fn ($query) => $query
                ->whereNull('dispatched_at')
                ->orWhere('dispatched_at', '<', $pendingRedispatchCutoff))
            ->exists() ? 'warning' : 'ok';
    }

    private function queueCheck(): string
    {
        try {
            $connection = config('queue.default');

            return filled($connection) ? 'ok' : 'unknown';
        } catch (Throwable) {
            return 'error';
        }
    }

    private function paperlessConfigCheck(): string
    {
        return filled(AppSetting::getValue('paperless.url')) ? 'ok' : 'missing';
    }

    private function pythonRuntimeCheck(): string
    {
        $binaries = array_values(array_unique(array_filter([
            (string) config('archibot_workers.python_binary', 'python'),
            'python3',
        ])));

        foreach ($binaries as $binary) {
            try {
                $process = new Process([$binary, '--version']);
                $process->setTimeout(2);
                $process->run();

                if ($process->isSuccessful()) {
                    return 'ok';
                }
            } catch (Throwable) {
                // Try the next local Python binary before reporting an error.
            }
        }

        return 'error';
    }
}
