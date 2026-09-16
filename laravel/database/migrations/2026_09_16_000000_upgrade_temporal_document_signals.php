<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

return new class extends Migration
{
    /** @var array<string, string> */
    private const SIGNAL_UPGRADES = [
        'review_decision' => 'review_decision_v2',
        'force_reprocess' => 'force_reprocess_v2',
        'embedding_ready' => 'embedding_ready_v2',
    ];

    public function up(): void
    {
        DB::table('temporal_outbox_intents')
            ->where('operation', 'signal_workflow')
            ->whereIn('signal_name', array_keys(self::SIGNAL_UPGRADES))
            ->orderBy('id')
            ->chunkById(100, function ($intents): void {
                foreach ($intents as $intent) {
                    $payload = $this->decodePayload($intent->payload);
                    if (! $this->targetsActiveWorkflow($intent->signal_name, $intent->workflow_id, $payload)) {
                        continue;
                    }

                    DB::table('temporal_outbox_intents')->insertOrIgnore([
                        'intent_key' => Uuid::uuid5(
                            Uuid::NAMESPACE_URL,
                            "archibot:signal-v2:{$intent->intent_key}",
                        )->toString(),
                        'operation' => 'signal_workflow',
                        'workflow_id' => $intent->workflow_id,
                        'workflow_type' => null,
                        'task_queue' => null,
                        'signal_name' => self::SIGNAL_UPGRADES[$intent->signal_name],
                        'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                        'status' => 'pending',
                        'attempts' => 0,
                        'available_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Signals already accepted by Temporal cannot be rolled back safely.
    }

    /** @param array<string, mixed> $payload */
    private function targetsActiveWorkflow(string $signalName, string $workflowId, array $payload): bool
    {
        if ($signalName === 'review_decision') {
            $suggestionId = $payload['review_suggestion_id'] ?? null;
            $decision = $payload['decision'] ?? null;

            if (! is_numeric($suggestionId) || ! in_array($decision, ['accepted', 'rejected'], true)) {
                return false;
            }

            $query = DB::table('review_suggestions as r')
                ->join('pipeline_runs as p', 'p.id', '=', 'r.pipeline_run_id')
                ->where('r.id', (int) $suggestionId)
                ->where('r.status', $decision)
                ->where('p.orchestration_driver', 'temporal')
                ->where('p.temporal_workflow_id', $workflowId)
                ->whereIn('p.progress_current_phase', ['awaiting_review', 'review_suggestion']);

            if ($decision === 'accepted') {
                $query->whereIn('r.commit_status', ['queued', 'running']);
            }

            return $query->exists();
        }

        if ($signalName === 'force_reprocess') {
            $suggestionId = $payload['review_suggestion_id'] ?? null;

            return is_numeric($suggestionId)
                && DB::table('review_suggestions as r')
                    ->join('pipeline_runs as p', 'p.id', '=', 'r.pipeline_run_id')
                    ->where('r.id', (int) $suggestionId)
                    ->where('r.status', 'stale')
                    ->where('p.orchestration_driver', 'temporal')
                    ->where('p.temporal_workflow_id', $workflowId)
                    ->whereIn('p.progress_current_phase', ['awaiting_review', 'review_suggestion'])
                    ->exists();
        }

        $pipelineRunId = $payload['pipeline_run_id'] ?? null;

        return is_numeric($pipelineRunId)
            && DB::table('pipeline_runs')
                ->where('id', (int) $pipelineRunId)
                ->where('orchestration_driver', 'temporal')
                ->where('temporal_workflow_id', $workflowId)
                ->where('progress_current_phase', 'waiting_for_embedding')
                ->whereNotIn('status', ['succeeded', 'failed', 'failed_permanent', 'cancelled'])
                ->exists();
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

        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
};
