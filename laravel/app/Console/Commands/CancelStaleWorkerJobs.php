<?php

namespace App\Console\Commands;

use App\Services\Workers\StaleWorkerJobCanceller;
use Illuminate\Console\Command;

class CancelStaleWorkerJobs extends Command
{
    protected $signature = 'worker-jobs:cancel-stale {--minutes= : Override stale cancelling timeout in minutes}';

    protected $description = 'Force-cancel worker jobs that have been stuck in cancelling state past the timeout.';

    public function handle(StaleWorkerJobCanceller $canceller): int
    {
        $minutesOption = $this->option('minutes');
        $minutes = $minutesOption === null || $minutesOption === '' ? null : (int) $minutesOption;

        if ($minutes !== null && $minutes < 1) {
            $this->error('The --minutes option must be at least 1.');

            return self::FAILURE;
        }

        $count = $canceller->cancel($minutes);

        $this->info("Cancelled {$count} stale worker job(s).");

        return self::SUCCESS;
    }
}
