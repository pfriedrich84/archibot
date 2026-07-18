<?php

namespace App\Jobs;

use App\Models\Command;
use App\Services\EntityApprovalDecisionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ApplyEntityApprovalCommand implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $commandId) {}

    public function handle(EntityApprovalDecisionService $decisions): void
    {
        $command = Command::query()->findOrFail($this->commandId);
        $decisions->execute($command);
    }
}
