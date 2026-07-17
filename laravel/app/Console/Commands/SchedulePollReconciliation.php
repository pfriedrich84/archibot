<?php

namespace App\Console\Commands;

use App\Services\Pipeline\MaintenanceCommandDispatcher;
use Illuminate\Console\Command;

class SchedulePollReconciliation extends Command
{
    protected $signature = 'archibot:scheduled-poll';

    protected $description = 'Queue due automatic polling reconciliation through Laravel actor transport.';

    public function handle(MaintenanceCommandDispatcher $maintenanceCommands): int
    {
        $command = $maintenanceCommands->queueScheduledPollReconciliation();
        if ($command === null) {
            $this->info('Scheduled poll skipped because polling is disabled, not due, or already active.');

            return self::SUCCESS;
        }

        $this->info("Scheduled poll reconciliation command {$command->id} queued.");

        return self::SUCCESS;
    }
}
