<?php

namespace App\Http\Controllers;

use App\Models\ActorExecution;
use App\Models\AuditLog;
use App\Models\Command;
use App\Models\PipelineEvent;
use App\Models\PipelineRun;
use App\Models\WebhookDelivery;
use App\Support\DiagnosticPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OperationsLogController extends Controller
{
    public function __construct(private readonly DiagnosticPresenter $diagnostics) {}

    public function __invoke(Request $request): Response
    {
        return Inertia::render('operations-log/Index', [
            'commands' => Command::query()
                ->latest()
                ->limit(25)
                ->get(['id', 'type', 'status', 'error', 'created_at', 'started_at', 'finished_at'])
                ->map(fn (Command $command): array => [
                    'id' => $command->id,
                    'type' => $this->diagnostics->typedScalar('command_type', $command->type),
                    'status' => $this->diagnostics->typedScalar('status', $command->status),
                    'error' => $this->diagnostics->redactedMessage($command->error),
                    'created_at' => $command->created_at?->toISOString(),
                    'started_at' => $command->started_at?->toISOString(),
                    'finished_at' => $command->finished_at?->toISOString(),
                ]),
            'pipelineRuns' => PipelineRun::query()
                ->latest()
                ->limit(25)
                ->get(['id', 'type', 'status', 'trigger_source', 'paperless_document_id', 'progress_current_phase', 'progress_message', 'created_at', 'started_at', 'finished_at'])
                ->map(fn (PipelineRun $run): array => [
                    'id' => $run->id,
                    'type' => $this->diagnostics->typedScalar('pipeline_type', $run->type),
                    'status' => $this->diagnostics->typedScalar('status', $run->status),
                    'trigger_source' => $this->diagnostics->typedScalar('trigger_source', $run->trigger_source),
                    'paperless_document_id' => $run->paperless_document_id,
                    'progress_current_phase' => $run->progress_current_phase === null
                        ? null
                        : $this->diagnostics->typedScalar('phase', $run->progress_current_phase),
                    'progress_message' => $this->diagnostics->redactedMessage($run->progress_message),
                    'created_at' => $run->created_at?->toISOString(),
                    'started_at' => $run->started_at?->toISOString(),
                    'finished_at' => $run->finished_at?->toISOString(),
                    'show_url' => route('pipeline-runs.show', $run),
                ]),
            'pipelineEvents' => PipelineEvent::query()
                ->latest()
                ->limit(25)
                ->get(['id', 'pipeline_run_id', 'event_type', 'level', 'message', 'paperless_document_id', 'created_at'])
                ->map(fn (PipelineEvent $event): array => [
                    'id' => $event->id,
                    'pipeline_run_id' => $event->pipeline_run_id,
                    'event_type' => $this->diagnostics->diagnosticEventType($event->event_type),
                    'level' => $this->diagnostics->typedScalar('level', $event->level),
                    'message' => $this->diagnostics->redactedMessage($event->message),
                    'paperless_document_id' => $event->paperless_document_id,
                    'created_at' => $event->created_at?->toISOString(),
                ]),
            'actorExecutions' => ActorExecution::query()
                ->latest()
                ->limit(25)
                ->get(['id', 'pipeline_run_id', 'actor_name', 'status', 'attempt', 'progress_message', 'error_type', 'error_message', 'created_at', 'started_at', 'finished_at'])
                ->map(fn (ActorExecution $execution): array => [
                    'id' => $execution->id,
                    'pipeline_run_id' => $execution->pipeline_run_id,
                    'actor_name' => $this->diagnostics->actorName($execution->actor_name),
                    'status' => $this->diagnostics->typedScalar('status', $execution->status),
                    'attempt' => $execution->attempt,
                    'progress_message' => $this->diagnostics->redactedMessage($execution->progress_message),
                    'error_type' => $this->diagnostics->errorType($execution->error_type),
                    'error_message' => $this->diagnostics->redactedMessage($execution->error_message),
                    'created_at' => $execution->created_at?->toISOString(),
                    'started_at' => $execution->started_at?->toISOString(),
                    'finished_at' => $execution->finished_at?->toISOString(),
                ]),
            'webhookDeliveries' => WebhookDelivery::query()
                ->latest()
                ->limit(25)
                ->get(['id', 'source', 'event_type', 'status', 'paperless_document_id', 'received_at', 'processed_at', 'error'])
                ->map(fn (WebhookDelivery $delivery): array => [
                    'id' => $delivery->id,
                    'source' => $this->diagnostics->typedScalar('source', $delivery->source),
                    'event_type' => $this->diagnostics->webhookEventType($delivery->event_type),
                    'status' => $this->diagnostics->typedScalar('status', $delivery->status),
                    'paperless_document_id' => $delivery->paperless_document_id,
                    'received_at' => $delivery->received_at?->toISOString(),
                    'processed_at' => $delivery->processed_at?->toISOString(),
                    'error' => $this->diagnostics->redactedMessage($delivery->error),
                    'show_url' => route('webhook-deliveries.show', $delivery),
                ]),
            'auditLogs' => AuditLog::query()
                ->latest()
                ->limit(25)
                ->get(['id', 'event', 'target_type', 'target_id', 'created_at'])
                ->map(fn (AuditLog $log): array => [
                    'id' => $log->id,
                    'event' => $this->diagnostics->diagnosticEventType($log->event),
                    'target_type' => in_array($log->target_type, [
                        'app_settings', 'command', 'embedding_index', 'entity_approval', 'mcp_token',
                        'ocr_review', 'paperless_connection', 'pipeline_recovery', 'pipeline_run',
                        'prompt', 'review_suggestion', 'setup_state', 'user', 'webhook_delivery',
                    ], true)
                        ? $log->target_type
                        : 'unknown',
                    'target_id' => ctype_digit((string) $log->target_id) ? $log->target_id : $this->diagnostics->opaqueReference($log->target_id),
                    'created_at' => $log->created_at?->toISOString(),
                ]),
        ]);
    }
}
