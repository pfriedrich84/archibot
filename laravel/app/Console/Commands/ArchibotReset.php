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

    protected $description = 'CLI-only destructive reset for Laravel/PostgreSQL operational state.';

    /** @var array<int, string> */
    private array $operationalTables = [
        'jobs',
        'job_batches',
        'failed_jobs',
        'cache_locks',
        'cache',
        'sessions',
        'chat_messages',
        'chat_sessions',
        'pipeline_items',
        'pipeline_events',
        'actor_executions',
        'commands',
        'pipeline_runs',
        'webhook_deliveries',
        'document_embeddings',
        'embedding_index_state',
        'llm_calls',
        'entity_approvals',
        'ocr_reviews',
        'review_suggestions',
        'audit_logs',
    ];

    /** @var array<int, string> */
    private array $configTables = [
        'mcp_tokens',
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
