<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'pipeline_run_id', 'paperless_document_id', 'item_type', 'status', 'attempt',
    'max_attempts', 'next_retry_at', 'last_retry_at', 'retry_reason', 'retry_mode',
    'error', 'started_at', 'finished_at',
])]
class PipelineItem extends Model
{
    public const TYPE_PAPERLESS_FETCH = 'paperless_fetch';

    public const TYPE_CLASSIFICATION = 'classification';

    public const TYPE_REVIEW_SUGGESTION = 'review_suggestion';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected function casts(): array
    {
        return [
            'next_retry_at' => 'datetime',
            'last_retry_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
