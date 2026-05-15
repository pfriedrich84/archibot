<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ArchibotReset extends Command
{
    protected $signature = 'archibot:reset
        {--yes : Confirm the destructive reset}
        {--include-config : Also clear Laravel app settings and setup state}';

    protected $description = 'CLI-only destructive reset for Laravel operational and job-control state.';

    /** @var array<int, string> */
    private array $operationalTables = [
        'worker_job_logs',
        'worker_jobs',
        'jobs',
        'failed_jobs',
        'pipeline_items',
        'pipeline_events',
        'actor_executions',
        'commands',
        'pipeline_runs',
        'webhook_deliveries',
        'entity_approvals',
        'ocr_reviews',
        'review_suggestions',
    ];

    /** @var array<int, string> */
    private array $configTables = [
        'app_settings',
        'setup_states',
    ];

    public function handle(): int
    {
        if (! $this->option('yes')) {
            $this->error('This command is destructive. Re-run with --yes to confirm.');

            return self::FAILURE;
        }

        $tables = $this->operationalTables;

        if ($this->option('include-config')) {
            $tables = array_merge($tables, $this->configTables);
        }

        $cleared = [];

        Schema::disableForeignKeyConstraints();

        try {
            foreach ($tables as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                DB::table($table)->delete();
                $cleared[] = $table;
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->info('Archibot Laravel reset complete.');
        $this->line('Cleared tables: '.implode(', ', $cleared));

        return self::SUCCESS;
    }
}
