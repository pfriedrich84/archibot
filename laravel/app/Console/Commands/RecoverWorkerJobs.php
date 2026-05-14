<?php

namespace App\Console\Commands;

use App\Services\Workers\WorkerJobRecovery;
use Illuminate\Console\Command;

class RecoverWorkerJobs extends Command
{
    protected $signature = 'worker-jobs:recover
        {--pending-seconds= : Redispatch queued jobs older than this many seconds}
        {--running-minutes= : Recover running jobs stale for this many minutes}
        {--dry-run : Show what would be recovered without changing rows}';

    protected $description = 'Recover queued, running, and cancelling worker jobs that were lost after worker crashes or restarts.';

    public function handle(WorkerJobRecovery $recovery): int
    {
        $pendingSeconds = $this->integerOption('pending-seconds');
        $runningMinutes = $this->integerOption('running-minutes');

        if (($pendingSeconds !== null && $pendingSeconds < 1) || ($runningMinutes !== null && $runningMinutes < 1)) {
            $this->error('Recovery timeout options must be at least 1.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $recovery = $recovery->dryRun();
            $this->warn('Dry run: no worker jobs will be changed or dispatched.');
        }

        $summary = $recovery->recoverAll($pendingSeconds, $runningMinutes);

        $this->info('Worker job recovery summary:');
        $this->line('Redispatched queued: '.$summary['redispatched_queued']);
        $this->line('Requeued running: '.$summary['requeued_running']);
        $this->line('Failed running: '.$summary['failed_running']);
        $this->line('Cancelled cancelling: '.$summary['cancelled_cancelling']);

        return self::SUCCESS;
    }

    private function integerOption(string $name): ?int
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
