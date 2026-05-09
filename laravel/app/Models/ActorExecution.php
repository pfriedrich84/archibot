<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'pipeline_run_id', 'paperless_document_id', 'actor_name', 'message_id', 'queue_name',
    'status', 'attempt', 'max_attempts', 'worker_id', 'started_at', 'finished_at',
    'duration_ms', 'progress_total', 'progress_done', 'progress_failed', 'progress_skipped',
    'progress_current_item', 'progress_message', 'progress_updated_at', 'next_retry_at',
    'last_retry_at', 'retry_reason', 'retry_mode', 'error_type', 'error_message',
])]
class ActorExecution extends Model
{
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
