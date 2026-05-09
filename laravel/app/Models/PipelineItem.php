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
