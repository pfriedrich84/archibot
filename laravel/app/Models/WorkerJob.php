<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'type',
    'status',
    'payload',
    'dispatch_key',
    'dispatch_attempts',
    'worker_id',
    'lease_expires_at',
    'heartbeat_at',
    'dispatched_at',
    'input_path',
    'output_path',
    'result',
    'progress',
    'exit_code',
    'error',
    'created_by_user_id',
    'retry_of_worker_job_id',
    'started_at',
    'finished_at',
    'cancellation_requested_at',
])]
class WorkerJob extends Model
{
    use HasFactory;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_CANCELLING = 'cancelling';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PARTIALLY_FAILED = 'partially_failed';

    public const TYPE_POLL = 'poll';

    public const TYPE_REINDEX = 'reindex';

    public const TYPE_REINDEX_OCR = 'reindex_ocr';

    public const TYPE_REINDEX_EMBED = 'reindex_embed';

    public const TYPE_PROCESS_DOCUMENT = 'process_document';

    public const TYPE_COMMIT_REVIEW = 'commit_review';

    public const TYPE_SYNC_ENTITY_APPROVAL = 'sync_entity_approval';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'progress' => 'array',
            'dispatch_attempts' => 'integer',
            'lease_expires_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancellation_requested_at' => 'datetime',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function allowedTypes(): array
    {
        return [
            self::TYPE_POLL,
            self::TYPE_REINDEX,
            self::TYPE_REINDEX_OCR,
            self::TYPE_REINDEX_EMBED,
            self::TYPE_PROCESS_DOCUMENT,
            self::TYPE_COMMIT_REVIEW,
            self::TYPE_SYNC_ENTITY_APPROVAL,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function userQueueableTypes(): array
    {
        return [
            self::TYPE_POLL,
            self::TYPE_PROCESS_DOCUMENT,
            self::TYPE_REINDEX,
            self::TYPE_REINDEX_OCR,
            self::TYPE_REINDEX_EMBED,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function activeStatuses(): array
    {
        return [self::STATUS_QUEUED, self::STATUS_RUNNING, self::STATUS_CANCELLING];
    }

    /**
     * @return array<int, string>
     */
    public static function terminalStatuses(): array
    {
        return [
            self::STATUS_CANCELLED,
            self::STATUS_SUCCEEDED,
            self::STATUS_FAILED,
            self::STATUS_PARTIALLY_FAILED,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function runningStatuses(): array
    {
        return [self::STATUS_RUNNING, self::STATUS_CANCELLING];
    }

    /**
     * @return array<int, string>
     */
    public static function blockingTypes(): array
    {
        return [self::TYPE_REINDEX, self::TYPE_REINDEX_OCR, self::TYPE_REINDEX_EMBED];
    }

    /**
     * @return array<int, string>
     */
    public static function documentProcessingTypes(): array
    {
        return [self::TYPE_POLL, self::TYPE_PROCESS_DOCUMENT];
    }

    public function scopeRunningOrCancelling(Builder $query): Builder
    {
        return $query->whereIn('status', self::runningStatuses());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::activeStatuses());
    }

    public function scopeExpiredLease(Builder $query): Builder
    {
        return $query
            ->whereIn('status', self::runningStatuses())
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<=', now());
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::activeStatuses(), true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::terminalStatuses(), true);
    }

    public function markDispatched(): void
    {
        $this->forceFill([
            'dispatch_attempts' => ((int) $this->dispatch_attempts) + 1,
            'dispatched_at' => now(),
        ])->save();
    }

    public function hasActiveLease(): bool
    {
        return $this->isActive()
            && $this->worker_id !== null
            && $this->lease_expires_at !== null
            && $this->lease_expires_at->isFuture();
    }

    public function hasExpiredLease(): bool
    {
        return $this->isActive()
            && $this->lease_expires_at !== null
            && ! $this->lease_expires_at->isFuture();
    }

    public function acquireLease(string $workerId, ?int $leaseSeconds = null): bool
    {
        $leaseSeconds = max(1, $leaseSeconds ?? (int) config('archibot_workers.lease_seconds', 300));
        $now = now();
        $expiresAt = $now->copy()->addSeconds($leaseSeconds);

        $this->refresh();

        $updated = self::query()
            ->whereKey($this->id)
            ->where(function (Builder $query) use ($now): void {
                $query
                    ->where('status', self::STATUS_QUEUED)
                    ->orWhere(fn (Builder $query) => $query
                        ->where('status', self::STATUS_RUNNING)
                        ->whereNotNull('lease_expires_at')
                        ->where('lease_expires_at', '<=', $now));
            })
            ->where(function (Builder $query) use ($now, $workerId): void {
                $query
                    ->whereNull('worker_id')
                    ->orWhere('worker_id', $workerId)
                    ->orWhereNull('lease_expires_at')
                    ->orWhere('lease_expires_at', '<=', $now);
            })
            ->update([
                'status' => self::STATUS_RUNNING,
                'worker_id' => $workerId,
                'lease_expires_at' => $expiresAt,
                'heartbeat_at' => $now,
                'started_at' => $this->started_at ?: $now,
                'updated_at' => $now,
            ]);

        $this->refresh();

        return $updated === 1;
    }

    public function heartbeatLease(string $workerId, ?int $leaseSeconds = null): bool
    {
        $leaseSeconds = max(1, $leaseSeconds ?? (int) config('archibot_workers.lease_seconds', 300));
        $now = now();

        $updated = self::query()
            ->whereKey($this->id)
            ->where('worker_id', $workerId)
            ->whereIn('status', self::runningStatuses())
            ->update([
                'heartbeat_at' => $now,
                'lease_expires_at' => $now->copy()->addSeconds($leaseSeconds),
                'updated_at' => $now,
            ]);

        $this->refresh();

        return $updated === 1;
    }

    public function releaseLease(string $workerId): bool
    {
        $updated = self::query()
            ->whereKey($this->id)
            ->where('worker_id', $workerId)
            ->update([
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);

        $this->refresh();

        return $updated === 1;
    }

    /** @param array<string, mixed> $context */
    public function appendSystemLog(string $event, string $message, string $level = 'info', array $context = []): WorkerJobLog
    {
        return $this->logs()->create([
            'stream' => 'system',
            'level' => $level,
            'event' => $event,
            'phase' => is_array($this->progress) && is_scalar($this->progress['phase'] ?? null)
                ? (string) $this->progress['phase']
                : null,
            'message' => $message,
            'context' => $context,
        ]);
    }

    public function isBlockingType(): bool
    {
        return in_array($this->type, self::blockingTypes(), true);
    }

    public function isDocumentProcessingType(): bool
    {
        return in_array($this->type, self::documentProcessingTypes(), true);
    }

    public function paperlessDocumentId(): ?int
    {
        $value = data_get($this->payload, 'paperless_document_id') ?? data_get($this->payload, 'document_id');

        return is_numeric($value) ? (int) $value : null;
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_worker_job_id');
    }

    public function retryChildren(): HasMany
    {
        return $this->hasMany(self::class, 'retry_of_worker_job_id');
    }

    public function reviewSuggestions(): HasMany
    {
        return $this->hasMany(ReviewSuggestion::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(WorkerJobLog::class);
    }
}
