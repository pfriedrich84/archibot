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
        $summary = $recovery->runRecoveryScan($limit);
        if (($summary['scan_skipped_locked'] ?? 0) === 1) {
            $this->info('Recovery scan skipped because another scan owns the recovery lock.');

            return self::SUCCESS;
        }

        $this->info(
            "Recovery scan complete. actor_executions_stale={$summary['actor_executions_stale']} actor_executions_redispatched={$summary['actor_executions_redispatched']} actor_executions_failed_permanent={$summary['actor_executions_failed_permanent']} pipeline_runs_cancelled={$summary['pipeline_runs_cancelled']} webhook_deliveries_redispatched={$summary['webhook_deliveries_redispatched']} document_pipeline_runs_redispatched={$summary['document_pipeline_runs_redispatched']} commands_redispatched={$summary['commands_redispatched']}",
        );

        return self::SUCCESS;
    }
}
