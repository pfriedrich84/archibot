<?php

namespace App\Services\Workers;

use App\Models\WorkerJob;
use Illuminate\Support\Carbon;

class StaleWorkerJobCanceller
{
    public function cancel(?int $olderThanMinutes = null): int
    {
        $minutes = $olderThanMinutes ?? (int) config('archibot_workers.stale_cancelling_minutes', 30);

        if ($minutes < 1) {
            return 0;
        }

        $cutoff = now()->subMinutes($minutes);

        $jobs = WorkerJob::query()
            ->where('status', WorkerJob::STATUS_CANCELLING)
            ->where(function ($query) use ($cutoff): void {
                $query
                    ->where(fn ($query) => $query
                        ->whereNotNull('cancellation_requested_at')
                        ->where('cancellation_requested_at', '<=', $cutoff))
                    ->orWhere(fn ($query) => $query
                        ->whereNull('cancellation_requested_at')
                        ->where('updated_at', '<=', $cutoff));
            })
            ->get();

        foreach ($jobs as $job) {
            $job->forceFill([
                'status' => WorkerJob::STATUS_CANCELLED,
                'finished_at' => $job->finished_at ?: Carbon::now(),
                'error' => trim(($job->error ? $job->error."\n" : '').'Cancelled automatically after being stuck in cancelling state.'),
                'progress' => array_merge(is_array($job->progress) ? $job->progress : [], [
                    'cancelled' => true,
                    'message' => 'Cancelled automatically after stale cancellation timeout.',
                ]),
            ])->save();

            $job->logs()->create([
                'stream' => 'system',
                'level' => 'warning',
                'event' => 'worker_job.stale_cancelled',
                'phase' => is_array($job->progress) && is_scalar($job->progress['phase'] ?? null)
                    ? (string) $job->progress['phase']
                    : null,
                'message' => 'Worker job was force-cancelled after stale cancellation timeout.',
                'context' => [
                    'timeout_minutes' => $minutes,
                    'cutoff' => $cutoff->toISOString(),
                ],
            ]);
        }

        return $jobs->count();
    }
}
