<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\DiagnosticPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function __construct(private readonly DiagnosticPresenter $diagnostics) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->is_admin, 403);

        $logs = AuditLog::query()
            ->with('actor:id,name,email,paperless_username')
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (AuditLog $log) => [
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
                'target_url' => $this->targetUrl($log),
                'metadata' => $this->diagnostics->metadata($log->metadata),
                'actor' => $log->actor ? [
                    'id' => $log->actor->id,
                    'name' => $this->diagnostics->scalarSummary('actor name', $log->actor->name),
                    'paperless_username' => $this->diagnostics->scalarSummary('paperless username', $log->actor->paperless_username),
                ] : null,
                'created_at' => $log->created_at?->toISOString(),
            ]);

        return Inertia::render('admin/AuditLogs', [
            'logs' => $logs,
        ]);
    }

    private function targetUrl(AuditLog $log): ?string
    {
        if (! $log->target_type || ! ctype_digit((string) $log->target_id)) {
            return null;
        }

        return match ($log->target_type) {
            'webhook_delivery' => route('webhook-deliveries.show', $log->target_id),
            'review_suggestion' => route('review.show', $log->target_id),
            default => null,
        };
    }
}
