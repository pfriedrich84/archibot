<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'command_id', 'webhook_delivery_id', 'type', 'status', 'scope', 'trigger_source',
    'paperless_document_id', 'paperless_modified', 'content_hash', 'pipeline_dedupe_key',
    'coalesced_sources', 'progress_total', 'progress_done', 'progress_failed',
    'progress_skipped', 'progress_current_phase', 'progress_phase_total',
    'progress_phase_done', 'progress_message', 'progress_updated_at', 'retry_count',
    'max_retries', 'next_retry_at', 'last_retry_at', 'retry_reason', 'retry_mode',
    'retry_of_run_id', 'reprocess_requested', 'reprocess_reason', 'reprocess_mode',
    'reprocess_of_run_id', 'requested_by_user_id', 'started_at', 'finished_at',
    'error_type', 'error', 'lifecycle_version', 'active_actor_token',
])]
class PipelineRun extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_RETRYING = 'retrying';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_PARTIALLY_FAILED = 'partially_failed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_FAILED_PERMANENT = 'failed_permanent';

    public const STATUS_CANCEL_REQUESTED = 'cancel_requested';

    public const STATUS_CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'paperless_modified' => 'datetime',
            'coalesced_sources' => 'array',
            'progress_updated_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'last_retry_at' => 'datetime',
            'reprocess_requested' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function command(): BelongsTo
    {
        return $this->belongsTo(Command::class);
    }

    public function webhookDelivery(): BelongsTo
    {
        return $this->belongsTo(WebhookDelivery::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PipelineEvent::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PipelineItem::class);
    }
}
