<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\Pipeline\DocumentPipelineStarter;
use App\Services\Pipeline\MaintenanceCommandDispatcher;
use App\Support\OperatorPrincipal;
use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DispatchMaintenanceCommand extends ConsoleCommand
{
    protected $signature = 'archibot:maintenance-command
        {type : poll, reindex, reindex_ocr, reindex_embed, or process_document}
        {--force : Force the action where supported}
        {--limit= : Optional positive item limit for command-backed actions}
        {--document-id= : Paperless document id for process_document}';

    protected $description = 'Dispatch the same durable maintenance backend used by the admin Maintenance UI.';

    public function handle(MaintenanceCommandDispatcher $maintenanceCommands, DocumentPipelineStarter $pipelineStarter): int
    {
        $type = $this->normalizeType((string) $this->argument('type'));
        $force = (bool) $this->option('force');
        $limit = $this->normalizedLimit($this->option('limit'));
        $request = $this->localOperatorRequest();

        try {
            validator(
                ['type' => $type],
                ['type' => ['required', Rule::in(['poll', 'reindex', 'reindex_ocr', 'reindex_embed', 'process_document'])]],
            )->validate();
        } catch (ValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($type === 'process_document') {
            $documentId = $this->normalizedDocumentId($this->option('document-id'));
            if ($documentId === null) {
                $this->error('The --document-id option is required for process_document.');

                return self::FAILURE;
            }

            $result = $pipelineStarter->start(
                triggerSource: 'manual',
                paperlessDocumentId: $documentId,
                reprocessRequested: $force,
                reprocessReason: $force ? 'manual_force' : null,
                reprocessMode: $force ? 'manual' : null,
                forceNewRun: $force,
                requestedByUserId: null,
            );

            AuditLog::query()->create([
                'actor_user_id' => null,
                'event' => 'maintenance.document_pipeline_requested',
                'target_type' => 'pipeline_run',
                'target_id' => (string) $result->pipelineRun->id,
                'metadata' => [
                    'actor_principal' => OperatorPrincipal::LOCAL_OPERATOR,
                    'paperless_document_id' => $documentId,
                    'force' => $force,
                ],
                'ip_address' => '127.0.0.1',
                'user_agent' => 'archibot-local-operator',
            ]);
            $this->info("Document pipeline run {$result->pipelineRun->id} queued for Paperless document {$documentId}.");

            return self::SUCCESS;
        }

        $metadata = ['source' => 'cli'];
        if ($type === 'poll') {
            $command = $maintenanceCommands->queuePollReconciliation($request, $limit, [
                'force' => $force,
                ...$metadata,
            ]);
        } elseif ($type === 'reindex') {
            $command = $maintenanceCommands->queueReindex($request, $limit, $metadata);
        } elseif ($type === 'reindex_embed') {
            $command = $maintenanceCommands->queueEmbeddingIndexBuild($request, $limit, $metadata);
        } else {
            $command = $maintenanceCommands->queueOcrReindex($request, $limit, $force, $metadata);
        }

        $this->info("Durable {$command->type} command {$command->id} queued through Laravel Maintenance.");

        return self::SUCCESS;
    }

    private function localOperatorRequest(): Request
    {
        $request = Request::create('/cli/archibot/maintenance-command', 'POST');
        $request->headers->set('User-Agent', 'archibot-local-operator');
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        return OperatorPrincipal::markLocalOperator($request);
    }

    private function normalizeType(string $type): string
    {
        return str_replace('-', '_', $type);
    }

    private function normalizedLimit(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $limit = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $limit === false ? null : $limit;
    }

    private function normalizedDocumentId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $documentId = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $documentId === false ? null : $documentId;
    }
}
