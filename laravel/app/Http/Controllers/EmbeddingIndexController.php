<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Command;
use App\Models\EmbeddingIndexState;
use App\Models\PipelineEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmbeddingIndexController extends Controller
{
    public function build(Request $request): RedirectResponse
    {
        abort_unless((bool) $request->user()?->is_admin, 403);

        $limit = $request->integer('limit');
        $payload = $limit > 0 ? ['limit' => $limit] : [];

        $command = Command::query()->create([
            'type' => Command::TYPE_EMBEDDING_INDEX_BUILD,
            'status' => Command::STATUS_PENDING,
            'payload' => $payload,
            'created_by_user_id' => $request->user()->id,
        ]);

        PipelineEvent::query()->create([
            'command_id' => $command->id,
            'event_type' => 'job_control.embedding_build_requested',
            'level' => 'info',
            'message' => 'Embedding index build requested by admin.',
            'payload' => [
                'actor_user_id' => $request->user()->id,
                'actor_is_admin' => true,
                'action' => Command::TYPE_EMBEDDING_INDEX_BUILD,
                'command_id' => $command->id,
                'limit' => $limit > 0 ? $limit : null,
            ],
        ]);

        $this->audit($request, 'embedding_index.build_requested', [
            'command_id' => $command->id,
            'limit' => $limit > 0 ? $limit : null,
        ]);

        return back()->with('status', 'Embedding index build queued.');
    }

    public function markStale(Request $request): RedirectResponse
    {
        abort_unless((bool) $request->user()?->is_admin, 403);

        $state = EmbeddingIndexState::query()->latest()->first();
        if ($state === null) {
            $state = EmbeddingIndexState::query()->create([
                'status' => EmbeddingIndexState::STATUS_STALE,
                'error' => 'Marked stale by admin before an index existed.',
            ]);
        } else {
            $state->forceFill([
                'status' => EmbeddingIndexState::STATUS_STALE,
                'error' => 'Marked stale by admin.',
            ])->save();
        }

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
