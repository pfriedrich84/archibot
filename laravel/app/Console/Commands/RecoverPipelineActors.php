<?php

namespace App\Console\Commands;

use App\Services\Pipeline\PipelineRecoveryDispatcher;
use Illuminate\Console\Command;

class RecoverPipelineActors extends Command
{
    protected $signature = 'archibot:recovery-scan {--limit=100 : Maximum durable records to inspect per recovery class}';

    protected $description = 'Redispatch safe durable pipeline work through Laravel queued actor jobs.';

    public function handle(PipelineRecoveryDispatcher $recovery): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $webhookDeliveries = $recovery->recoverQueuedWebhookDeliveries($limit);

        $this->info("Recovery scan complete. webhook_deliveries_redispatched={$webhookDeliveries}");

        return self::SUCCESS;
    }
}
