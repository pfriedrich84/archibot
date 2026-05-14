<?php

namespace App\Services\Workers;

use App\Jobs\RunPythonWorkerJob;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WorkerJob;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class WorkerJobDispatcher
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $auditMetadata
     */
    public function dispatch(
        string $type,
        array $payload = [],
        ?User $user = null,
        ?Request $request = null,
        ?string $dedupeKey = null,
        ?int $retryOfWorkerJobId = null,
        string $auditEvent = 'worker_job.queued',
        array $auditMetadata = [],
    ): WorkerJob {
        $dispatchKey = $dedupeKey ?? self::dispatchKey($type, $payload);

        if ($retryOfWorkerJobId === null) {
            $existing = WorkerJob::query()
                ->active()
                ->where('dispatch_key', $dispatchKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $workerJob = WorkerJob::query()->create([
            'type' => $type,
            'status' => WorkerJob::STATUS_QUEUED,
            'payload' => $payload,
            'dispatch_key' => $dispatchKey,
            'retry_of_worker_job_id' => $retryOfWorkerJobId,
            'created_by_user_id' => $user?->id,
        ]);

        AuditLog::query()->create([
            'actor_user_id' => $user?->id,
            'event' => $auditEvent,
            'target_type' => 'worker_job',
            'target_id' => (string) $workerJob->id,
            'metadata' => [
                'type' => $workerJob->type,
                'payload' => $payload,
                ...$auditMetadata,
            ],
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);

        RunPythonWorkerJob::dispatch($workerJob->id);
        $workerJob->markDispatched();

        return $workerJob->refresh();
    }

    /** @param array<string, mixed> $payload */
    public static function dispatchKey(string $type, array $payload = []): string
    {
        return hash('sha256', $type.':'.json_encode(self::normalize($payload), JSON_THROW_ON_ERROR));
    }

    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (Arr::isList($value)) {
            return array_map(fn (mixed $item): mixed => self::normalize($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => self::normalize($item), $value);
    }
}
