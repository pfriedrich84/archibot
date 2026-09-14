<?php

namespace App\Console\Commands;

use App\Services\Temporal\TemporalOutbox;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class TemporalProbe extends Command
{
    protected $signature = 'archibot:temporal-probe {--request-id= : Optional UUID used for idempotent retries}';

    protected $description = 'Queue a side-effect-free Temporal runtime probe through the transactional outbox.';

    public function handle(TemporalOutbox $outbox): int
    {
        $requestId = (string) ($this->option('request-id') ?: Str::uuid());
        if (! Str::isUuid($requestId)) {
            $this->error('The --request-id value must be a UUID.');

            return self::FAILURE;
        }

        $intent = $outbox->startWorkflow(
            intentKey: $requestId,
            workflowId: "archibot/runtime-probe/{$requestId}",
            workflowType: 'archibot.runtime_probe',
            payload: ['request_id' => $requestId],
        );

        $this->info("Temporal probe intent {$intent->id} queued with key {$requestId}.");

        return self::SUCCESS;
    }
}
