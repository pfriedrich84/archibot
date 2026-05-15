<?php

namespace App\Services\Workers;

use App\Jobs\RunPythonWorkerJob;
use App\Models\WorkerJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class WorkerJobRecovery
{
    public function __construct(
        private readonly StaleWorkerJobCanceller $staleCanceller,
        private bool $dryRun = false,
    ) {}

    public function dryRun(): self
    {
        return new self($this->staleCanceller, true);
    }

    /**
     * @return array{redispatched_queued: int, failed_queued: int, requeued_running: int, failed_running: int, cancelled_cancelling: int}
     */
    public function recoverAll(?int $pendingSeconds = null, ?int $runningMinutes = null): array
    {
        $queued = $this->recoverStaleQueued($pendingSeconds);
        $running = $this->recoverStaleRunning($runningMinutes);

        return [
            'redispatched_queued' => $queued['redispatched_queued'],
            'failed_queued' => $queued['failed_queued'],
            'requeued_running' => $running['requeued_running'],
            'failed_running' => $running['failed_running'],
            'cancelled_cancelling' => $this->cancelStaleCancelling(),
        ];
    }

    public function redispatchStaleQueued(?int $pendingSeconds = null): int
    {
        return $this->recoverStaleQueued($pendingSeconds)['redispatched_queued'];
    }

    /**
     * @return array{redispatched_queued: int, failed_queued: int}
     */
    private function recoverStaleQueued(?int $pendingSeconds = null): array
    {
        $pendingSeconds = $pendingSeconds ?? (int) config('archibot_workers.pending_redispatch_seconds', 900);

        if ($pendingSeconds < 1) {
            return ['redispatched_queued' => 0, 'failed_queued' => 0];
        }

        $maxDispatchAttempts = max(1, (int) config('archibot_workers.max_dispatch_attempts', 3));
        $now = now();
        $jobs = WorkerJob::query()
            ->where('status', WorkerJob::STATUS_QUEUED)
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('dispatched_at')
                    ->orWhere('dispatched_at', '<=', now());
            })
            ->get();

        $redispatched = 0;
        $failed = 0;

        foreach ($jobs as $job) {
            $dispatchAttempts = (int) $job->dispatch_attempts;
            $backoffSeconds = $pendingSeconds * max(1, $dispatchAttempts);
            $cutoff = $now->copy()->subSeconds($backoffSeconds);

            if ($job->dispatched_at !== null && $job->dispatched_at->greaterThan($cutoff)) {
                continue;
            }

            if ($dispatchAttempts >= $maxDispatchAttempts) {
                $failed++;

                if ($this->dryRun) {
                    continue;
                }

                $job->forceFill([
                    'status' => WorkerJob::STATUS_FAILED,
                    'finished_at' => $job->finished_at ?: Carbon::now(),
                    'worker_id' => null,
                    'lease_expires_at' => null,
                    'heartbeat_at' => null,
                    'error' => trim(($job->error ? $job->error."\n" : '').'queued_dispatch_attempts_exhausted'),
                    'progress' => array_merge(is_array($job->progress) ? $job->progress : [], [
                        'failed' => true,
                        'reason' => 'queued_dispatch_attempts_exhausted',
                        'message' => 'Worker job failed after queued dispatch attempts were exhausted before acquisition.',
                    ]),
                ])->save();

                $job->appendSystemLog('worker_job.queued_dispatch_failed', 'Queued worker job exhausted dispatch attempts before acquisition and was marked failed.', 'error', [
                    'pending_seconds' => $pendingSeconds,
                    'backoff_seconds' => $backoffSeconds,
                    'max_dispatch_attempts' => $maxDispatchAttempts,
                    'cutoff' => $cutoff->toISOString(),
                ]);

                continue;
            }

            $redispatched++;

            if ($this->dryRun) {
                continue;
            }

            RunPythonWorkerJob::dispatch($job->id);
            $job->markDispatched();
            $job->appendSystemLog('worker_job.redispatched', 'Queued worker job was redispatched by recovery.', 'info', [
                'pending_seconds' => $pendingSeconds,
                'backoff_seconds' => $backoffSeconds,
                'dispatch_attempts_before' => $dispatchAttempts,
                'cutoff' => $cutoff->toISOString(),
            ]);
        }

        return ['redispatched_queued' => $redispatched, 'failed_queued' => $failed];
    }

    /**
     * @return array{requeued_running: int, failed_running: int}
     */
    public function recoverStaleRunning(?int $runningMinutes = null): array
    {
        $runningMinutes = $runningMinutes ?? (int) config('archibot_workers.stale_running_minutes', 10);

        if ($runningMinutes < 1) {
            return ['requeued_running' => 0, 'failed_running' => 0];
        }

        $now = now();
        $heartbeatCutoff = $now->copy()->subMinutes($runningMinutes);
        $maxDispatchAttempts = max(1, (int) config('archibot_workers.max_dispatch_attempts', 3));

        $jobs = WorkerJob::query()
            ->where('status', WorkerJob::STATUS_RUNNING)
            ->where(function (Builder $query) use ($heartbeatCutoff, $now): void {
                $query
                    ->whereNull('heartbeat_at')
                    ->orWhere('heartbeat_at', '<=', $heartbeatCutoff)
                    ->orWhere(fn (Builder $query) => $query
                        ->whereNotNull('lease_expires_at')
                        ->where('lease_expires_at', '<=', $now));
            })
            ->get();

        $requeued = 0;
        $failed = 0;

        foreach ($jobs as $job) {
            if ((int) $job->dispatch_attempts < $maxDispatchAttempts) {
                $requeued++;

                if ($this->dryRun) {
                    continue;
                }

                $job->forceFill([
                    'status' => WorkerJob::STATUS_QUEUED,
                    'worker_id' => null,
                    'lease_expires_at' => null,
                    'heartbeat_at' => null,
                ])->save();

                RunPythonWorkerJob::dispatch($job->id);
                $job->markDispatched();
                $job->appendSystemLog('worker_job.stale_running_requeued', 'Stale running worker job was requeued by recovery.', 'warning', [
                    'running_minutes' => $runningMinutes,
                    'heartbeat_cutoff' => $heartbeatCutoff->toISOString(),
                    'max_dispatch_attempts' => $maxDispatchAttempts,
                ]);

                continue;
            }

            $failed++;

            if ($this->dryRun) {
                continue;
            }

            $job->forceFill([
                'status' => WorkerJob::STATUS_FAILED,
                'finished_at' => $job->finished_at ?: Carbon::now(),
                'worker_id' => null,
                'lease_expires_at' => null,
                'error' => trim(($job->error ? $job->error."\n" : '').'stale_running_timeout'),
                'progress' => array_merge(is_array($job->progress) ? $job->progress : [], [
                    'failed' => true,
                    'reason' => 'stale_running_timeout',
                    'message' => 'Worker job failed after stale running timeout.',
                ]),
            ])->save();

            $job->appendSystemLog('worker_job.stale_running_failed', 'Stale running worker job exhausted dispatch attempts and was marked failed.', 'error', [
                'running_minutes' => $runningMinutes,
                'heartbeat_cutoff' => $heartbeatCutoff->toISOString(),
                'max_dispatch_attempts' => $maxDispatchAttempts,
            ]);
        }

        return ['requeued_running' => $requeued, 'failed_running' => $failed];
    }

    public function cancelStaleCancelling(?int $minutes = null): int
    {
        if ($this->dryRun) {
            return $this->staleCancellingQuery($minutes)->count();
        }

        return $this->staleCanceller->cancel($minutes);
    }

    private function staleCancellingQuery(?int $minutes = null): Builder
    {
        $minutes = $minutes ?? (int) config('archibot_workers.stale_cancelling_minutes', 30);
        $cutoff = now()->subMinutes($minutes);

        return WorkerJob::query()
            ->where('status', WorkerJob::STATUS_CANCELLING)
            ->where(function (Builder $query) use ($cutoff): void {
                $query
                    ->where(fn (Builder $query) => $query
                        ->whereNotNull('cancellation_requested_at')
                        ->where('cancellation_requested_at', '<=', $cutoff))
                    ->orWhere(fn (Builder $query) => $query
                        ->whereNull('cancellation_requested_at')
                        ->where('updated_at', '<=', $cutoff))
                    ->orWhere(fn (Builder $query) => $query
                        ->whereNotNull('lease_expires_at')
                        ->where('lease_expires_at', '<=', now()));
            });
    }
}
