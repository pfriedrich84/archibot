<?php

namespace App\Services\Actors;

use App\Models\ActorExecution;
use Illuminate\Support\Str;

final class ActorInvocationClaimer
{
    public function issue(
        string $actorName,
        int $currentVersion,
        string $sourceColumn,
        int $sourceId,
        mixed $documentId,
    ): ActorInvocationClaim {
        $token = (string) Str::uuid();
        // Attempts are source-scoped, not actor-name-scoped. A stale job with
        // another actor name must never create a second active execution for
        // the same durable source.
        $attempt = ((int) ActorExecution::query()
            ->where($sourceColumn, $sourceId)
            ->max('attempt')) + 1;
        $execution = ActorExecution::query()->create([
            $sourceColumn => $sourceId,
            'paperless_document_id' => is_numeric($documentId) ? (int) $documentId : null,
            'actor_name' => $actorName,
            'queue_name' => 'laravel.database',
            'status' => ActorExecution::STATUS_QUEUED,
            'attempt' => $attempt,
            'max_attempts' => 5,
            'execution_token' => $token,
            'source_version' => $currentVersion + 1,
        ]);

        return new ActorInvocationClaim($token, $currentVersion + 1, $execution->id, $attempt);
    }

    public function suppresses(string $actorName, string $sourceColumn, int $sourceId): bool
    {
        $latest = ActorExecution::query()
            ->where($sourceColumn, $sourceId)
            ->latest('id')
            ->lockForUpdate()
            ->first();
        if ($latest === null) {
            return false;
        }
        if (in_array($latest->status, [ActorExecution::STATUS_QUEUED, ActorExecution::STATUS_RUNNING], true)) {
            return true;
        }

        return $latest->status === ActorExecution::STATUS_RETRYING
            && ($latest->next_retry_at === null || $latest->next_retry_at->isFuture());
    }
}
