<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\ArchibotResetService;
use App\Support\OperatorPrincipal;
use Illuminate\Console\Command;

class ArchibotReset extends Command
{
    protected $signature = 'archibot:reset
        {--yes : Confirm the destructive reset}
        {--include-config : Also clear Laravel app settings and setup state}';

    protected $description = 'Destructive reset through the shared Laravel/PostgreSQL reset backend.';

    public function handle(ArchibotResetService $reset): int
    {
        if (! $this->option('yes')) {
            $this->error('This command is destructive. Re-run with --yes to confirm.');

            return self::FAILURE;
        }

        $includeConfig = (bool) $this->option('include-config');
        $cleared = $reset->reset($includeConfig);
        AuditLog::query()->create([
            'actor_user_id' => null,
            'event' => 'maintenance.reset_completed',
            'target_type' => 'system',
            'target_id' => 'archibot',
            'metadata' => [
                'actor_principal' => OperatorPrincipal::LOCAL_OPERATOR,
                'include_config' => $includeConfig,
                'cleared_tables' => $cleared,
            ],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'archibot-local-operator',
        ]);

        $this->info('Archibot Laravel reset complete.');
        $this->line('Cleared tables: '.implode(', ', $cleared));

        return self::SUCCESS;
    }
}
