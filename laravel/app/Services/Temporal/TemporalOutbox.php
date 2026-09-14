<?php

namespace App\Services\Temporal;

use App\Models\TemporalOutboxIntent;
use Illuminate\Support\Facades\DB;
use LogicException;
use Ramsey\Uuid\Uuid;

class TemporalOutbox
{
    /**
     * Persist one immutable workflow-start intent in the caller's transaction.
     *
     * @param  array<string, mixed>  $payload
     */
    public function startWorkflow(
        string $intentKey,
        string $workflowId,
        string $workflowType,
        array $payload,
        ?string $taskQueue = null,
    ): TemporalOutboxIntent {
        return $this->record([
            'intent_key' => $intentKey,
            'operation' => TemporalOutboxIntent::OPERATION_START,
            'workflow_id' => $workflowId,
            'workflow_type' => $workflowType,
            'task_queue' => $taskQueue ?: (string) config('archibot.temporal_task_queue'),
            'signal_name' => null,
            'payload' => $payload,
        ]);
    }

    /**
     * Persist one immutable workflow-signal intent in the caller's transaction.
     *
     * @param  array<string, mixed>  $payload
     */
    public function signalWorkflow(
        string $intentKey,
        string $workflowId,
        string $signalName,
        array $payload,
    ): TemporalOutboxIntent {
        return $this->record([
            'intent_key' => $intentKey,
            'operation' => TemporalOutboxIntent::OPERATION_SIGNAL,
            'workflow_id' => $workflowId,
            'workflow_type' => null,
            'task_queue' => null,
            'signal_name' => $signalName,
            'payload' => $payload,
        ]);
    }

    /**
     * Persist one immutable workflow-cancellation intent in the caller's transaction.
     *
     * @param  array<string, mixed>  $payload
     */
    public function cancelWorkflow(
        string $intentKey,
        string $workflowId,
        array $payload = [],
    ): TemporalOutboxIntent {
        return $this->record([
            'intent_key' => $intentKey,
            'operation' => TemporalOutboxIntent::OPERATION_CANCEL,
            'workflow_id' => $workflowId,
            'workflow_type' => null,
            'task_queue' => null,
            'signal_name' => null,
            'payload' => $payload,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function record(array $attributes): TemporalOutboxIntent
    {
        if (! Uuid::isValid($attributes['intent_key'])) {
            throw new LogicException('Temporal intent keys must be UUIDs.');
        }

        return DB::transaction(function () use ($attributes): TemporalOutboxIntent {
            $existing = TemporalOutboxIntent::query()
                ->where('intent_key', $attributes['intent_key'])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                foreach (['operation', 'workflow_id', 'workflow_type', 'task_queue', 'signal_name', 'payload'] as $field) {
                    if ($existing->{$field} !== $attributes[$field]) {
                        throw new LogicException("Temporal intent key collision changed {$field}.");
                    }
                }

                return $existing;
            }

            return TemporalOutboxIntent::query()->create([
                ...$attributes,
                'status' => TemporalOutboxIntent::STATUS_PENDING,
                'available_at' => now(),
            ]);
        });
    }
}
