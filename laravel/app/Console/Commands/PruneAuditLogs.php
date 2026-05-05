<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Models\AuditLog;
use Illuminate\Console\Command;

class PruneAuditLogs extends Command
{
    protected $signature = 'archibot:audit-prune {--days= : Override retention in days}';

    protected $description = 'Prune audit logs older than the configured retention period.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: AppSetting::getValue('audit.retention_days', '7'));
        $days = max(1, $days);

        $deleted = AuditLog::query()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Pruned {$deleted} audit log entries older than {$days} days.");

        return self::SUCCESS;
    }
}
