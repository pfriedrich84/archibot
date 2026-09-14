<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('commands')
            ->where('type', 'review_commit')
            ->whereIn('status', ['pending', 'queued', 'running'])
            ->orderBy('id')
            ->chunkById(100, function ($commands): void {
                foreach ($commands as $command) {
                    $suggestion = DB::table('review_suggestions')
                        ->where('commit_command_id', $command->id)
                        ->where('status', 'accepted')
                        ->whereIn('commit_status', ['queued', 'running'])
                        ->first();
                    if ($suggestion === null) {
                        continue;
                    }

                    $run = $suggestion->pipeline_run_id === null
                        ? null
                        : DB::table('pipeline_runs')->where('id', $suggestion->pipeline_run_id)->first();
                    $documentWorkflowId = $run !== null
                        && $run->orchestration_driver === 'temporal'
                        && is_string($run->temporal_workflow_id)
                        && $run->temporal_workflow_id !== ''
                            ? $run->temporal_workflow_id
                            : null;
                    $workflowId = $documentWorkflowId
                        ?? "archibot/review-commit/{$suggestion->id}";
                    $commandPayload = $this->decodePayload($command->payload);
                    $commandPayload['orchestration_driver'] = 'temporal';
                    $commandPayload['temporal_workflow_id'] = $workflowId;

                    DB::table('commands')->where('id', $command->id)->update([
                        'status' => 'queued',
                        'payload' => json_encode($commandPayload, JSON_THROW_ON_ERROR),
                        'started_at' => null,
                        'finished_at' => null,
                        'next_retry_at' => null,
                        'active_actor_token' => null,
                        'error' => null,
                        'updated_at' => now(),
                    ]);

                    $actor = [
                        'actor_principal' => 'migration_recovery',
                        'actor_user_id' => $command->created_by_user_id,
                        'actor_is_admin' => null,
                    ];
                    if ($documentWorkflowId !== null) {
                        $identity = "review-decision:{$suggestion->id}:accepted";
                        $intent = [
                            'operation' => 'signal_workflow',
                            'workflow_id' => $workflowId,
                            'workflow_type' => null,
                            'task_queue' => null,
                            'signal_name' => 'review_decision',
                            'payload' => [
                                'review_suggestion_id' => $suggestion->id,
                                'command_id' => $command->id,
                                'decision' => 'accepted',
                                'temporal_workflow_id' => $workflowId,
                                ...$actor,
                            ],
                        ];
                    } else {
                        $identity = "review-commit:{$suggestion->id}";
                        $intent = [
                            'operation' => 'start_workflow',
                            'workflow_id' => $workflowId,
                            'workflow_type' => 'archibot.review_commit',
                            'task_queue' => (string) config('archibot.temporal_task_queue'),
                            'signal_name' => null,
                            'payload' => [
                                'review_suggestion_id' => $suggestion->id,
                                'command_id' => $command->id,
                                'workflow_id' => $workflowId,
                            ],
                        ];
                    }

                    DB::table('temporal_outbox_intents')->insertOrIgnore([
                        ...$intent,
                        'intent_key' => Uuid::uuid5(
                            Uuid::NAMESPACE_URL,
                            "archibot:{$identity}",
                        )->toString(),
                        'payload' => json_encode($intent['payload'], JSON_THROW_ON_ERROR),
                        'status' => 'pending',
                        'attempts' => 0,
                        'available_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    DB::table('pipeline_events')->insert([
                        'command_id' => $command->id,
                        'event_type' => 'migration.review_commit_adopted_by_temporal',
                        'paperless_document_id' => $suggestion->paperless_document_id,
                        'level' => 'warning',
                        'message' => 'Pending legacy review commit adopted by Temporal.',
                        'payload' => json_encode([
                            'review_suggestion_id' => $suggestion->id,
                            'temporal_workflow_id' => $workflowId,
                            'operation' => $intent['operation'],
                        ], JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Delivered workflow starts and signals cannot be rolled back safely.
    }

    /** @return array<string, mixed> */
    private function decodePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }
        if (! is_string($payload) || $payload === '') {
            return [];
        }
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }
};
