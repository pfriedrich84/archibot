<?php

namespace App\Http\Controllers;

use App\Models\ActorExecution;
use App\Models\AuditLog;
use App\Models\Command;
use App\Models\PipelineEvent;
use App\Models\PipelineRun;
use App\Models\WebhookDelivery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OperationsLogController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('operations-log/Index', [
            'commands' => Command::query()
                ->latest()
                ->limit(25)
                ->get(['id', 'type', 'status', 'payload', 'error', 'created_at', 'started_at', 'finished_at'])
                ->map(fn (Command $command): array => [
                    'id' => $command->id,
                    'type' => $command->type,
                    'status' => $command->status,
                    'payload' => $command->payload ?? [],
                    'error' => $command->error,
                    'created_at' => $command->created_at?->toISOString(),
                    'started_at' => $command->started_at?->toISOString(),
                    'finished_at' => $command->finished_at?->toISOString(),
                ]),
            'pipelineRuns' => PipelineRun::query()
                ->latest()
                ->limit(25)
                ->get(['id', 'type', 'status', 'scope', 'trigger_source', 'paperless_document_id', 'progress_current_phase', 'progress_message', 'created_at', 'started_at', 'finished_at'])
                ->map(fn (PipelineRun $run): array => [
                    'id' => $run->id,
                    'type' => $run->type,
                    'status' => $run->status,
                    'scope' => $run->scope,
                    'trigger_source' => $run->trigger_source,
                    'paperless_document_id' => $run->paperless_document_id,
                    'progress_current_phase' => $run->progress_current_phase,
                    'progress_message' => $run->progress_message,
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
                    'event_type' => $event->event_type,
                    'level' => $event->level,
                    'message' => $event->message,
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
                    'actor_name' => $execution->actor_name,
                    'status' => $execution->status,
                    'attempt' => $execution->attempt,
                    'progress_message' => $execution->progress_message,
                    'error_type' => $execution->error_type,
                    'error_message' => $execution->error_message,
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
                    'source' => $delivery->source,
                    'event_type' => $delivery->event_type,
                    'status' => $delivery->status,
                    'paperless_document_id' => $delivery->paperless_document_id,
                    'received_at' => $delivery->received_at?->toISOString(),
                    'processed_at' => $delivery->processed_at?->toISOString(),
                    'error' => $delivery->error,
                    'show_url' => route('webhook-deliveries.show', $delivery),
                ]),
            'auditLogs' => AuditLog::query()
                ->latest()
                ->limit(25)
                ->get(['id', 'event', 'target_type', 'target_id', 'created_at'])
                ->map(fn (AuditLog $log): array => [
                    'id' => $log->id,
                    'event' => $log->event,
                    'target_type' => $log->target_type,
                    'target_id' => $log->target_id,
                    'created_at' => $log->created_at?->toISOString(),
                ]),
        ]);
    }
}
