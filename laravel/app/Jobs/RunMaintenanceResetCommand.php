<?php

namespace App\Jobs;

use App\Models\AuditLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class RunMaintenanceResetCommand implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public bool $includeConfig = false,
        public ?int $actorUserId = null,
        public ?string $requestIp = null,
        public ?string $userAgent = null,
    ) {}

    public function handle(): void
    {
        $command = ['python', '-m', 'app.cli', 'reset', '--yes'];

        if ($this->includeConfig) {
            $command[] = '--include-config';
        }

        $process = new Process($command, base_path('..'), timeout: null);
        $process->run();

        if (! $process->isSuccessful()) {
            $message = trim($process->getErrorOutput() ?: $process->getOutput() ?: 'Reset command failed.');

            throw new RuntimeException($message);
        }

        $this->audit('maintenance.reset_completed', [
            'include_config' => $this->includeConfig,
            'exit_code' => $process->getExitCode(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $this->audit('maintenance.reset_failed', [
            'include_config' => $this->includeConfig,
            'error' => $exception?->getMessage(),
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function audit(string $event, array $metadata): void
    {
        AuditLog::query()->create([
            'actor_user_id' => $this->actorUserId,
            'event' => $event,
            'target_type' => 'maintenance_reset',
            'target_id' => $this->includeConfig ? 'database_config' : 'database',
            'metadata' => $metadata,
            'ip_address' => $this->requestIp,
            'user_agent' => $this->userAgent,
        ]);
    }
}
