<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Command;
use App\Models\EmbeddingIndexState;
use App\Models\PipelineEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MaintenanceCommandController extends Controller
{
    public function poll(Request $request): RedirectResponse
    {
        abort_unless((bool) $request->user()?->is_admin, 403);

        $limit = $request->integer('limit');
        $payload = $limit > 0 ? ['limit' => $limit] : [];
        $command = Command::query()->create([
            'type' => 'poll_reconciliation',
            'status' => 'pending',
            'payload' => $payload,
            'created_by_user_id' => $request->user()->id,
        ]);

        PipelineEvent::query()->create([
            'command_id' => $command->id,
            'event_type' => 'job_control.poll_reconciliation_requested',
            'level' => 'info',
            'message' => 'Polling reconciliation requested by admin.',
            'payload' => [
                'actor_user_id' => $request->user()->id,
                'actor_is_admin' => true,
                'action' => 'poll_reconciliation',
                'command_id' => $command->id,
                'limit' => $limit > 0 ? $limit : null,
            ],
        ]);

        AuditLog::query()->create([
            'actor_user_id' => $request->user()->id,
            'event' => 'maintenance.poll_reconciliation_requested',
            'target_type' => 'command',
            'target_id' => (string) $command->id,
            'metadata' => [
                'command_id' => $command->id,
                'limit' => $limit > 0 ? $limit : null,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return back()->with('status', 'Polling reconciliation queued.');
    }

    public function reindex(Request $request): RedirectResponse
    {
        abort_unless((bool) $request->user()?->is_admin, 403);

        $limit = $request->integer('limit');
        $payload = $limit > 0 ? ['limit' => $limit] : [];
        $embeddingState = EmbeddingIndexState::query()->latest()->first();
        if ($embeddingState === null) {
            $embeddingState = EmbeddingIndexState::query()->create([
                'status' => 'stale',
                'error' => 'Reindex requested by admin before an index existed.',
            ]);
        } else {
            $embeddingState->forceFill([
                'status' => 'stale',
                'error' => 'Reindex requested by admin.',
            ])->save();
        }

        $command = Command::query()->create([
            'type' => 'reindex',
            'status' => 'pending',
            'payload' => $payload,
            'created_by_user_id' => $request->user()->id,
        ]);

        PipelineEvent::query()->create([
            'command_id' => $command->id,
            'event_type' => 'job_control.reindex_requested',
            'level' => 'warning',
            'message' => 'Reindex requested by admin; embedding gate marked stale.',
            'payload' => [
                'actor_user_id' => $request->user()->id,
                'actor_is_admin' => true,
                'action' => 'reindex',
                'command_id' => $command->id,
                'embedding_index_state_id' => $embeddingState->id,
                'limit' => $limit > 0 ? $limit : null,
            ],
        ]);

        AuditLog::query()->create([
            'actor_user_id' => $request->user()->id,
            'event' => 'maintenance.reindex_requested',
            'target_type' => 'command',
            'target_id' => (string) $command->id,
            'metadata' => [
                'command_id' => $command->id,
                'embedding_index_state_id' => $embeddingState->id,
                'limit' => $limit > 0 ? $limit : null,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return back()->with('status', 'Reindex queued.');
    }
}
