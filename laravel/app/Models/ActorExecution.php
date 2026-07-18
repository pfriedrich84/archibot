<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'pipeline_run_id', 'command_id', 'webhook_delivery_id', 'paperless_document_id', 'actor_name', 'message_id', 'queue_name',
    'status', 'attempt', 'max_attempts', 'worker_id', 'started_at', 'finished_at',
    'duration_ms', 'progress_total', 'progress_done', 'progress_failed', 'progress_skipped',
    'progress_current_item', 'progress_message', 'progress_updated_at', 'next_retry_at',
    'last_retry_at', 'retry_reason', 'retry_mode', 'error_type', 'error_message',
    'execution_token', 'source_version',
])]
class ActorExecution extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_RETRYING = 'retrying';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_FAILED_PERMANENT = 'failed_permanent';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_SKIPPED = 'skipped';

    public function pipelineRun(): BelongsTo
    {
        return $this->belongsTo(PipelineRun::class);
    }

    public function command(): BelongsTo
    {
        return $this->belongsTo(Command::class);
    }

    public function webhookDelivery(): BelongsTo
    {
        return $this->belongsTo(WebhookDelivery::class);
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'progress_updated_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'last_retry_at' => 'datetime',
        ];
    }
}
