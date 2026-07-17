<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PipelineEvent;
use App\Services\Pipeline\MaintenanceCommandDispatcher;
use App\Services\Pipeline\PipelineStartGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmbeddingIndexController extends Controller
{
    public function build(Request $request): RedirectResponse
    {
        abort_unless((bool) $request->user()?->is_admin, 403);

        app(MaintenanceCommandDispatcher::class)->queueEmbeddingIndexBuild(
            $request,
            $request->integer('limit'),
            array_filter([
                'ui_surface' => (string) $request->string('ui_surface') ?: null,
            ], fn ($value): bool => $value !== null),
        );

        return back()->with('status', 'Embedding index build queued.');
    }

    public function markStale(Request $request): RedirectResponse
    {
        abort_unless((bool) $request->user()?->is_admin, 403);

        $state = app(PipelineStartGate::class)->markStale('Marked stale by admin.');

        PipelineEvent::query()->create([
            'event_type' => 'embedding_index.marked_stale',
            'level' => 'warning',
            'message' => 'Embedding index marked stale by admin.',
            'payload' => [
                'actor_user_id' => $request->user()->id,
                'actor_is_admin' => true,
                'embedding_index_state_id' => $state->id,
            ],
        ]);

        $this->audit($request, 'embedding_index.marked_stale', [
            'embedding_index_state_id' => $state->id,
        ]);

        return back()->with('status', 'Embedding index marked stale.');
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(Request $request, string $event, array $metadata): void
    {
        AuditLog::query()->create([
            'actor_user_id' => $request->user()->id,
            'event' => $event,
            'target_type' => 'embedding_index',
            'target_id' => (string) ($metadata['embedding_index_state_id'] ?? $metadata['command_id'] ?? 'new'),
            'metadata' => $metadata,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
